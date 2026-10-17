<?php

namespace App\Twig;

use App\Service\SettingsProvider;
use Twig\Attribute\AsTwigFunction;

final class SettingsExtension
{
    public function __construct(private readonly SettingsProvider $settings)
    {
    }

    /** Réglage « Devis en ligne » : désactivé, les ajouts au devis deviennent « Demander sur WhatsApp ». */
    #[AsTwigFunction('quote_online')]
    public function quoteOnline(): bool
    {
        return $this->settings->quoteOnline();
    }

    /** Numéro WhatsApp international (vide si non renseigné), pour les boutons des cartes et de l'en-tête. */
    #[AsTwigFunction('whatsapp_number')]
    public function whatsappNumber(): string
    {
        return $this->settings->whatsappNumber();
    }

    /**
     * Coordonnées, réseaux et réglages SEO du layout public (en-tête, pied, JSON-LD LocalBusiness, robots), lus en une fois.
     *
     * @return array{whatsapp: string, phone: string, phoneNumber: string, address: string, siret: string, social: array<string, string>, maxKm: string, depotCity: string, indexable: bool}
     */
    #[AsTwigFunction('site_contact')]
    public function siteContact(): array
    {
        $s = $this->settings;

        return [
            'whatsapp' => $s->whatsappNumber(),
            'phone' => $s->phoneDisplay(),
            'phoneNumber' => $s->phoneNumber(),
            'address' => trim($s->get(SettingsProvider::ADDRESS)),
            'siret' => trim($s->get(SettingsProvider::SIRET)),
            'social' => $s->socialLinks(),
            'maxKm' => ctype_digit($s->get(SettingsProvider::DELIVERY_MAX_KM)) ? $s->get(SettingsProvider::DELIVERY_MAX_KM) : '',
            'depotCity' => trim($s->get(SettingsProvider::DEPOT_CITY)),
            'indexable' => $s->searchEnginesOpen(),
        ];
    }
}
