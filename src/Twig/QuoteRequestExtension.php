<?php

namespace App\Twig;

use App\Repository\QuoteRequestRepository;
use Twig\Attribute\AsTwigFunction;

final class QuoteRequestExtension
{
    public function __construct(private readonly QuoteRequestRepository $requests)
    {
    }

    /** Pastille de l'onglet Accueil du back-office (story 3.14) : demandes à l'état `nouvelle`. */
    #[AsTwigFunction('new_quote_requests')]
    public function newQuoteRequests(): int
    {
        return $this->requests->countNew();
    }
}
