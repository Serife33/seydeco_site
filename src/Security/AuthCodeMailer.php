<?php

namespace App\Security;

use Psr\Log\LoggerInterface;
use Scheb\TwoFactorBundle\Mailer\AuthCodeMailerInterface;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;

/** Email du code de connexion, en français. Un échec d'envoi n'interrompt pas la connexion. */
final class AuthCodeMailer implements AuthCodeMailerInterface
{
    public const string SEND_FAILED = 'Le code n\'a pas pu être envoyé. Réessayez dans quelques instants.';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')] private readonly string $from,
    ) {
    }

    public function sendAuthCode(TwoFactorInterface $user): void
    {
        $code = $user->getEmailAuthCode();
        if (null === $code) {
            return;
        }

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from($this->from)
                ->to($user->getEmailAuthRecipient())
                ->subject('Votre code de connexion Seydeco')
                ->htmlTemplate('emails/two_factor_code.html.twig')
                ->textTemplate('emails/two_factor_code.txt.twig')
                ->context(['code' => $code]));
        } catch (TransportExceptionInterface $e) {
            // Ni le code ni le message brut (il peut contenir l'email envoyé)
            $this->logger->warning('Échec d\'envoi du code de connexion.', ['exception_class' => $e::class]);
            $this->requestStack->getSession()->getFlashBag()->add('error', self::SEND_FAILED);
        }
    }
}
