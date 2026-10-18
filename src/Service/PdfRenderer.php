<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Liip\ImagineBundle\Imagine\Data\DataManager;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * Seul moteur PDF du site (AD-14) : Dompdf depuis un gabarit Twig, police DejaVu embarquée.
 * Aucun accès réseau (`isRemoteEnabled=false`) ni fichier hors d'un dossier vide (`chroot`) : les images sont passées en `data:`.
 */
final class PdfRenderer
{
    public function __construct(
        private readonly Environment $twig,
        private readonly DataManager $images,
        private readonly FilterManager $filters,
        #[Autowire('%kernel.project_dir%/templates/pdf')] private readonly string $chroot,
        #[Autowire('%kernel.cache_dir%/dompdf')] private readonly string $fontCache,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function render(string $template, array $context = []): string
    {
        if (!is_dir($this->fontCache)) {
            mkdir($this->fontCache, 0o775, true);
        }
        $options = (new Options())
            ->setIsRemoteEnabled(false)
            ->setIsPhpEnabled(false)
            ->setIsJavascriptEnabled(false)
            ->setChroot([$this->chroot])
            ->setFontCache($this->fontCache)
            ->setDefaultFont('DejaVu Sans')
            ->setDefaultPaperSize('a4');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->twig->render($template, $context), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /** Miniature JPEG en `data:` lue sur le disque ; photo manquante ou illisible = null (cadre vide, le rendu continue). */
    #[AsTwigFunction('pdf_thumbnail')]
    public function thumbnail(?string $filename): ?string
    {
        if (null === $filename) {
            return null;
        }
        try {
            $binary = $this->filters->applyFilter($this->images->find('admin_thumb', $filename), 'admin_thumb', ['format' => 'jpg']);
        } catch (\Throwable) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($binary->getContent());
    }
}
