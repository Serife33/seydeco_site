<?php

namespace App\Service;

use App\Entity\QuoteVersion;
use App\Entity\Signature;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Signature en ligne d'une version (story 3.16, AD-29) : le PDF signé est rendu une seule fois par PdfRenderer,
 * rangé hors de public/ sous un nom aléatoire, puis la Signature est enregistrée avec son SHA-256.
 * Si l'enregistrement échoue (double envoi : contrainte unique), le fichier est effacé et l'erreur remonte.
 */
final class QuoteSigner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfRenderer $renderer,
        #[Autowire('%app.upload_dir%')] private readonly string $dir,
    ) {
    }

    /** @param string $image tracé PNG en base64 (déjà vérifié) */
    public function sign(QuoteVersion $version, string $image, string $ip, ?\DateTimeImmutable $now = null): Signature
    {
        $now ??= new \DateTimeImmutable();
        $quote = $version->getRequest();
        $pdf = $this->renderer->render('pdf/quote.html.twig', ['quote' => $quote, 'version' => $version, 'signature' => [
            'signedAt' => $now, 'ip' => $ip, 'image' => $image,
        ]]);
        $path = 'signatures/'.bin2hex(random_bytes(16)).'.pdf';
        $fs = new Filesystem();
        $fs->dumpFile($this->dir.'/'.$path, $pdf);

        $signature = new Signature($version, Signature::TYPE_ONLINE, $now, $ip, $image, $path, hash('sha256', $pdf));
        try {
            $this->em->persist($signature);
            $this->em->flush();
        } catch (\Throwable $e) {
            $fs->remove($this->dir.'/'.$path);
            throw $e;
        }
        $version->addSignature($signature);

        return $signature;
    }

    public function path(Signature $signature): string
    {
        return $this->dir.'/'.$signature->getPdfPath();
    }
}
