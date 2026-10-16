<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Code faux : on réaffiche l'écran du code. Au 5ᵉ code faux en 15 min pour ce compte (compteur en base,
 * non remis à zéro par une reconnexion), retour à la Connexion.
 */
final class TwoFactorFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urls,
        private readonly RateLimiterFactoryInterface $twoFactorCodeLimiter,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $user = $this->tokenStorage->getToken()?->getUser();
        $blocked = $exception instanceof TooManyLoginAttemptsAuthenticationException
            || (null !== $user && 0 === $this->twoFactorCodeLimiter->create($user->getUserIdentifier())->consume()->getRemainingTokens());

        if ($blocked) {
            $this->tokenStorage->setToken(null);
            $session = $request->getSession();
            $session->invalidate();
            $session->getFlashBag()->add('error', 'Trop de codes incorrects. Réessayez dans 15 minutes.');

            return new RedirectResponse($this->urls->generate('admin_login'));
        }

        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate('2fa_login'));
    }
}
