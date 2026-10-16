<?php

namespace App\Security;

use Scheb\TwoFactorBundle\Security\Http\Authenticator\Passport\Credentials\TwoFactorCodeCredentials;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/** Compte bloqué après 5 codes faux : plus aucun code accepté, même juste, jusqu'à la fin de la fenêtre. */
#[AsEventListener(priority: 100)]
final class TwoFactorCodeLimitListener
{
    public function __construct(private readonly RateLimiterFactoryInterface $twoFactorCodeLimiter)
    {
    }

    public function __invoke(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        if (!$passport->hasBadge(TwoFactorCodeCredentials::class)) {
            return;
        }

        $limiter = $this->twoFactorCodeLimiter->create($passport->getUser()->getUserIdentifier());
        if (0 === $limiter->consume(0)->getRemainingTokens()) {
            throw new TooManyLoginAttemptsAuthenticationException();
        }
    }
}
