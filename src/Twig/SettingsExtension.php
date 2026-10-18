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
     * Encadré Conditions et pénalités (story 3.7) : réglages vides = ligne non affichée. Jamais de coordonnées bancaires ici.
     *
     * @return array{depositPercent: ?int, deposit: ?int, returnTerms: string, pickupTerms: string, fuelTerms: string, paymentMethods: list<string>}
     */
    #[AsTwigFunction('quote_conditions')]
    public function quoteConditions(): array
    {
        $s = $this->settings;
        $number = fn (string $name) => ctype_digit($s->get($name)) ? (int) $s->get($name) : null;
        $methods = array_intersect_key(SettingsProvider::PAYMENT_METHOD_LABELS, array_flip(explode(',', $s->get(SettingsProvider::PAYMENT_METHODS))));

        return [
            'depositPercent' => $number(SettingsProvider::DEPOSIT_PERCENT),
            'deposit' => $number(SettingsProvider::DEPOSIT_AMOUNT),
            'returnTerms' => trim($s->get(SettingsProvider::RETURN_TERMS)),
            'pickupTerms' => trim($s->get(SettingsProvider::PICKUP_TERMS)),
            'fuelTerms' => trim($s->get(SettingsProvider::FUEL_TERMS)),
            'paymentMethods' => array_values($methods),
        ];
    }

    /**
     * Coordonnées, réseaux et réglages SEO du layout public (en-tête, pied, JSON-LD LocalBusiness, robots, accueil), lus en une fois.
     *
     * @return array{whatsapp: string, phone: string, phoneNumber: string, address: string, siret: string, email: string, social: array<string, string>, maxKm: string, depotCity: string, indexable: bool, googleReviews: string, foundedYear: string, companyText: string}
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
            'email' => trim($s->get(SettingsProvider::EMAIL)),
            'social' => $s->socialLinks(),
            'maxKm' => ctype_digit($s->get(SettingsProvider::DELIVERY_MAX_KM)) ? $s->get(SettingsProvider::DELIVERY_MAX_KM) : '',
            'depotCity' => trim($s->get(SettingsProvider::DEPOT_CITY)),
            'indexable' => $s->searchEnginesOpen(),
            'googleReviews' => trim($s->get(SettingsProvider::GOOGLE_REVIEWS_URL)),
            'foundedYear' => ctype_digit($s->get(SettingsProvider::FOUNDED_YEAR)) ? $s->get(SettingsProvider::FOUNDED_YEAR) : '',
            'companyText' => trim($s->get(SettingsProvider::COMPANY_TEXT)),
        ];
    }
}
