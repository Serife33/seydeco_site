<?php

namespace App\Service;

use App\Entity\QuoteRequest;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;

/**
 * Email à Serife pour chaque nouvelle demande (story 3.14, AD-15) : synchrone, après l'enregistrement.
 * Un échec n'annule rien : la demande reste dans le back-office, l'échec est journalisé sans donnée personnelle.
 */
final class QuoteRequestNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly SettingsProvider $settings,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    public function notify(QuoteRequest $quote): void
    {
        $to = trim($this->settings->get(SettingsProvider::NOTIFICATION_EMAIL));
        if ('' === $to) {
            $this->logger->warning('Nouvelle demande de devis non signalée : adresse de notification non renseignée.');

            return;
        }

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from($this->from)
                ->to($to)
                ->subject(\sprintf('Nouvelle demande %s — %s %s', $quote->getNumber(), $quote->getFirstName(), $quote->getLastName()))
                ->htmlTemplate('emails/new_quote_request.html.twig')
                ->textTemplate('emails/new_quote_request.txt.twig')
                ->context(['quote' => $quote, 'version' => $quote->getCurrentVersion()]));
        } catch (\Throwable $e) {
            // Ni l'adresse ni le message brut (il peut contenir des coordonnées)
            $this->logger->warning('Échec d\'envoi de l\'email de nouvelle demande.', ['exception_class' => $e::class]);
        }
    }
}
