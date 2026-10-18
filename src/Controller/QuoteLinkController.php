<?php

namespace App\Controller;

use App\Entity\QuoteRequest;
use App\Form\QuoteSignatureType;
use App\Repository\QuoteRequestRepository;
use App\Service\PdfRenderer;
use App\Service\QuoteSigner;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lien secret du devis (stories 3.13, 3.15, 3.16, AD-18) : page, PDF et signature en ligne, retrouvés par le jeton seul.
 * Jeton inconnu ou mal formé : même 404 neutre. Limiteur ~30/min par IP (AD-26). Jamais indexé ni mis en cache.
 */
final class QuoteLinkController extends AbstractController
{
    public const string UPDATED = 'Votre devis a été mis à jour. Relisez-le avant de signer.';
    public const string ALREADY_SIGNED = 'Ce devis est déjà signé.';

    public function __construct(
        private readonly QuoteRequestRepository $requests,
        private readonly RateLimiterFactoryInterface $quoteLinkLimiter,
    ) {
    }

    #[Route('/devis/{token}', name: 'quote_show', methods: ['GET'])]
    public function show(Request $request, string $token): Response
    {
        $quote = $this->find($request, $token);
        if ($quote instanceof Response) {
            return $quote;
        }

        return $this->private($this->render('quote/show.html.twig', ['quote' => $quote, 'version' => $quote->getCurrentVersion()]));
    }

    /** Devis PDF de la version en cours ; une fois signée, le PDF signé rangé à la signature (story 3.16). */
    #[Route('/devis/{token}/pdf', name: 'quote_pdf', methods: ['GET'])]
    public function pdf(Request $request, string $token, PdfRenderer $renderer, QuoteSigner $signer): Response
    {
        $quote = $this->find($request, $token);
        if ($quote instanceof Response) {
            return $quote;
        }
        $version = $quote->getCurrentVersion();
        if ($signature = $version->getSignature()) {
            $response = new BinaryFileResponse($signer->path($signature));
            $filename = $quote->getNumber().'-signe.pdf';
        } else {
            $response = new Response($renderer->render('pdf/quote.html.twig', ['quote' => $quote, 'version' => $version]));
            $filename = $quote->getNumber().'.pdf';
        }
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $filename));

        return $this->private($response);
    }

    /** Signature au doigt (story 3.16) : refusée si la version affichée n'est plus la version en cours, ou déjà signée. */
    #[Route('/devis/{token}/signer', name: 'quote_sign', methods: ['GET', 'POST'])]
    public function sign(Request $request, string $token, QuoteSigner $signer): Response
    {
        $quote = $this->find($request, $token);
        if ($quote instanceof Response) {
            return $quote;
        }
        $version = $quote->getCurrentVersion();
        if (!$quote->canBeSignedOnline()) {
            if ($version->getSignature() && $request->isMethod('POST')) {
                $this->addFlash('quote_notice', self::ALREADY_SIGNED);
            }

            return $this->redirectToRoute('quote_show', ['token' => $token], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(QuoteSignatureType::class, ['versionId' => $version->getId()]);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            // Version affichée périmée : rien n'est enregistré, la cliente relit le devis à jour (AD-29)
            if ((string) $form->get('versionId')->getData() !== (string) $version->getId()) {
                $this->addFlash('quote_notice', self::UPDATED);

                return $this->redirectToRoute('quote_show', ['token' => $token], Response::HTTP_SEE_OTHER);
            }
            if ($form->isValid()) {
                try {
                    $signer->sign($version, (string) QuoteSignatureType::png($form->get('image')->getData()), $request->getClientIp() ?? '');
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash('quote_notice', self::ALREADY_SIGNED);
                }

                return $this->redirectToRoute('quote_show', ['token' => $token], Response::HTTP_SEE_OTHER);
            }
        }

        $response = $this->render('quote/sign.html.twig', ['quote' => $quote, 'version' => $version, 'form' => $form], new Response(status: $form->isSubmitted() ? 422 : 200));

        return $this->private($response);
    }

    /** Demande du jeton, ou la page de refus (limiteur, jeton inconnu ou mal formé : même 404, AD-18). */
    private function find(Request $request, string $token): QuoteRequest|Response
    {
        if (!$this->quoteLinkLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return $this->private($this->render('quote/unavailable.html.twig', ['message' => 'Trop d\'essais. Réessayez dans une minute.'], new Response(status: 429)));
        }
        $quote = preg_match('/^[0-9a-f]{32}$/', $token) ? $this->requests->findOneBy(['token' => $token]) : null;
        if (!$quote?->getCurrentVersion()) {
            return $this->private($this->render('quote/unavailable.html.twig', ['message' => 'Ce devis n\'est plus disponible.'], new Response(status: 404)));
        }

        return $quote;
    }

    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
