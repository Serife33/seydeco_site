<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pages publiques sans donnée personnelle : cache public de 5 minutes (AD-23, story 2.12).
 * Jamais pour le back-office (/admin, connexion, aperçu), « Mon devis » (sélection), une réponse qui pose un cookie
 * ou une requête qui a ouvert la session ; le SessionListener de Symfony repasse de toute façon en privé une réponse liée à la session.
 */
#[AsEventListener(KernelEvents::RESPONSE)]
final class PublicCacheListener
{
    public const int MAX_AGE = 300;

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        if (!$event->isMainRequest()
            || !$request->isMethodCacheable()
            || Response::HTTP_OK !== $response->getStatusCode()
            || str_starts_with($request->getPathInfo(), '/admin')
            || str_starts_with((string) $request->attributes->get('_route'), 'quote')
            || $response->headers->getCookies()
            || ($request->hasSession(true) && $request->getSession()->isStarted())
        ) {
            return;
        }
        $response->setPublic();
        $response->setMaxAge(self::MAX_AGE);
    }
}
