<?php

namespace App\Service;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

/** Seul accès aux réglages (AD-12) : aucune valeur métier écrite dans le code. */
final class SettingsProvider
{
    public const string WHATSAPP_PHONE = 'whatsapp_phone';

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function get(string $name): string
    {
        if (null === $this->values) {
            $this->values = [];
            foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
                $this->values[$setting->getName()] = $setting->getValue();
            }
        }

        return $this->values[$name] ?? '';
    }

    /** Numéro au format international sans « + » pour wa.me (06 12 34 56 78 → 33612345678) ; vide si non renseigné. */
    public function whatsappNumber(): string
    {
        // « +33… », « 0033… » ou « 06… » → 33…
        $digits = preg_replace(['/\D/', '/^00/'], '', $this->get(self::WHATSAPP_PHONE));

        return 10 === \strlen($digits) && str_starts_with($digits, '0') ? '33'.substr($digits, 1) : $digits;
    }
}
