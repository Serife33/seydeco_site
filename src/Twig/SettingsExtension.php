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
}
