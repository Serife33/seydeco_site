<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** À lancer sur le serveur : le format des images (paramètre app.image_format) doit pouvoir y être écrit. */
#[AsCommand(name: 'app:images:check', description: 'Vérifie que le serveur sait écrire les images WebP (GD / Imagick)')]
final class ImagesCheckCommand
{
    public function __construct(#[Autowire('%app.image_format%')] private readonly string $format)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $gd = \function_exists('imagewebp') && \function_exists('imagecreatetruecolor') && self::gdWritesWebp();
        $imagick = class_exists(\Imagick::class) && [] !== \Imagick::queryFormats('WEBP');

        $io->definitionList(
            ['GD (utilisé par le site)' => \extension_loaded('gd') ? ($gd ? 'WebP pris en charge' : 'WebP NON pris en charge') : 'extension absente'],
            ['Imagick' => class_exists(\Imagick::class) ? ($imagick ? 'WebP pris en charge' : 'WebP NON pris en charge') : 'extension absente'],
            ['Format configuré (app.image_format)' => $this->format],
        );

        if ('webp' === $this->format && !$gd) {
            $io->error('Ce serveur n\'écrit pas le WebP avec GD : mettre app.image_format à jpeg dans config/services.yaml.');

            return Command::FAILURE;
        }
        $io->success('Les images peuvent être générées au format '.$this->format.'.');

        return Command::SUCCESS;
    }

    private static function gdWritesWebp(): bool
    {
        ob_start();
        $ok = imagewebp(imagecreatetruecolor(1, 1));
        $data = ob_get_clean();

        return $ok && str_starts_with((string) $data, 'RIFF');
    }
}
