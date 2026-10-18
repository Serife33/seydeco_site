<?php

namespace App\Controller;

use App\Entity\QuoteRequest;
use App\Form\QuoteRequestType;
use App\Repository\QuoteRequestRepository;
use App\Service\QuoteEstimator;
use App\Service\QuoteRequestCreator;
use App\Service\QuoteRequestNotifier;
use App\Service\RentalPeriod;
use App\Service\SettingsProvider;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Envoi de la demande (story 3.12) : tout est revalidé et recalculé au serveur (Symfony Form + CSRF, AD-5, AD-27),
 * puis enregistré d'un bloc ; la confirmation affiche le numéro. Réponses JSON lues par le contrôleur `finalize`.
 */
final class QuoteRequestController extends AbstractController
{
    public const string TOO_MANY = 'Trop de demandes envoyées. Réessayez dans une heure, ou écrivez-nous sur WhatsApp.';
    public const string FAILED = 'Le devis n\'a pas pu être créé. Réessayez, ou écrivez-nous sur WhatsApp.';
    private const string SESSION_KEY = 'quote_confirmation';
    /** Raison du calcul qui bloque l'envoi → champ de Finaliser où la corriger (`lines` = Mon devis). */
    private const array REASON_FIELDS = ['min_qty' => 'lines', 'delivery_min_chairs' => 'mode', 'delivery_max_km' => 'mode', 'installation_pickup' => 'installation'];

    public function __construct(
        private readonly SettingsProvider $settings,
        private readonly QuoteEstimator $estimator,
        private readonly RentalPeriod $period,
        private readonly QuoteRequestCreator $creator,
        private readonly QuoteRequestNotifier $notifier,
        private readonly RateLimiterFactoryInterface $quoteRequestLimiter,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/mon-devis/envoyer', name: 'quote_submit', methods: ['POST'])]
    public function submit(Request $request): JsonResponse
    {
        if (!$this->settings->quoteOnline()) {
            return $this->json(['error' => 'Le devis en ligne est momentanément fermé.'], 503);
        }
        $quoteRequest = new QuoteRequest();
        $form = $this->createForm(QuoteRequestType::class, $quoteRequest);
        $form->handleRequest($request);
        if (!$form->isSubmitted()) {
            return $this->json(['error' => 'Demande illisible.'], 400);
        }
        // Champ piège rempli : réponse neutre, rien n'est enregistré
        if ('' !== trim((string) $form->get('website')->getData())) {
            return $this->json(['redirect' => $this->generateUrl('quote_confirmation')]);
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $origin = $error->getOrigin();
            $errors[] = [$origin === $form || null === $origin ? 'form' : $origin->getName(), $error->getMessage()];
        }
        $draft = json_decode((string) $form->get('draft')->getData(), true);
        try {
            $estimate = $this->estimator->estimate(\is_array($draft) ? $draft : []);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['errors' => [['lines', $e->getMessage()]]], 422);
        }
        [$eventDate, $period, $draftErrors] = $this->check($draft, $estimate);
        if ($errors = [...$draftErrors, ...$errors]) {
            return $this->json(['errors' => $errors], 422);
        }

        if (!$this->quoteRequestLimiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return $this->json(['error' => self::TOO_MANY], 429);
        }
        try {
            $this->creator->create($quoteRequest, $estimate, $eventDate, $period);
        } catch (\Throwable $e) {
            // Aucune donnée personnelle dans le journal
            $this->logger->error('Demande de devis non enregistrée : {class}.', ['class' => $e::class, 'message' => $e instanceof \OverflowException ? $e->getMessage() : null]);

            return $this->json(['error' => self::FAILED], 500);
        }
        // Après l'enregistrement : un échec d'envoi n'annule pas la demande (AD-15)
        $this->notifier->notify($quoteRequest);
        $request->getSession()->set(self::SESSION_KEY, $quoteRequest->getToken());

        return $this->json(['redirect' => $this->generateUrl('quote_confirmation')]);
    }

    /** Numéro du devis tout juste créé, retrouvé par la session (jamais d'id ni de numéro dans l'URL, AD-18). */
    #[Route('/mon-devis/confirmation', name: 'quote_confirmation', methods: ['GET'])]
    public function confirmation(Request $request, QuoteRequestRepository $requests): Response
    {
        $token = $request->hasPreviousSession() ? $request->getSession()->get(self::SESSION_KEY) : null;
        $quote = \is_string($token) ? $requests->findOneBy(['token' => $token]) : null;
        if (!$quote) {
            return $this->redirectToRoute('quote');
        }
        $response = $this->render('quote/confirmation.html.twig', ['quote' => $quote]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Date (au plus tôt demain), mode, adresse, éligibilité recalculée et période contre les créneaux ouverts.
     *
     * @param array<string, mixed> $estimate
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?array{startAt: \DateTimeImmutable, endAt: \DateTimeImmutable}, 2: list<array{0: string, 1: string}>}
     */
    private function check(mixed $draft, array $estimate): array
    {
        $draft = \is_array($draft) ? $draft : [];
        $errors = [];
        if (!$estimate['priced']) {
            $errors[] = ['lines', 'Votre sélection est vide ou ses produits ne sont plus proposés : revenez à Mon devis.'];
        }
        foreach ($estimate['quote']['reasons'] as $reason) {
            $errors[] = [self::REASON_FIELDS[$reason['type']] ?? 'lines', $reason['message']];
        }
        if (!\in_array($draft['mode'] ?? null, ['pickup', 'delivery'], true)) {
            $errors[] = ['mode', 'Choisissez le retrait au dépôt ou la livraison.'];
        } elseif ($estimate['delivery'] && '' === $estimate['address']) {
            $errors[] = ['address', 'Indiquez l\'adresse du lieu de l\'événement.'];
        }

        $date = $this->period->eventDate($draft['date'] ?? null);
        if (null === $date) {
            $errors[] = ['date', 'Indiquez la date de votre événement.'];
        } elseif ($this->period->tooSoon($date)) {
            $errors[] = ['date', RentalPeriod::DATE_TOO_SOON];
            $date = null;
        }
        $period = null;
        if ($date && $estimate['priced']) {
            $period = $this->period->resolve($date, $estimate['chairs'], $estimate['delivery'], $estimate['collect'], \is_array($draft['period'] ?? null) ? $draft['period'] : []);
            if (isset($period['error'])) {
                $errors[] = [$period['field'], $period['error']];
                $period = null;
            }
        }

        return [$date, $period, $errors];
    }
}
