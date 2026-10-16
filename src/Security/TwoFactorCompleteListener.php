<?php

namespace App\Security;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** Code à usage unique : effacé dès que la double authentification est réussie ; compteur de codes faux remis à zéro. */
#[AsEventListener(event: TwoFactorAuthenticationEvents::COMPLETE)]
final class TwoFactorCompleteListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RateLimiterFactoryInterface $twoFactorCodeLimiter,
    ) {
    }

    public function __invoke(TwoFactorAuthenticationEvent $event): void
    {
        $user = $event->getToken()->getUser();
        if ($user instanceof AdminUser) {
            $this->twoFactorCodeLimiter->create($user->getUserIdentifier())->reset();
            $user->clearEmailAuthCode();
            $this->em->flush();
        }
    }
}
