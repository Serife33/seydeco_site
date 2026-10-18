<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Routes `computeRoutes` (DRIVE, sans trafic), appelé seulement par le serveur.
 * La clé part dans un en-tête, jamais dans l'URL ; les journaux ne contiennent ni clé ni adresse.
 */
final class GoogleDistanceCalculator implements DistanceCalculatorInterface
{
    private const string URL = 'https://routes.googleapis.com/directions/v2:computeRoutes';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SettingsProvider $settings,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'GOOGLE_ROUTES_API_KEY')] private readonly string $apiKey,
    ) {
    }

    public function metersFromDepot(string $address): ?int
    {
        $depot = $this->settings->get(SettingsProvider::DEPOT_ADDRESS);
        if ('' === $this->apiKey || '' === trim($depot) || '' === trim($address)) {
            return null;
        }
        try {
            $response = $this->httpClient->request('POST', self::URL, [
                'headers' => ['X-Goog-Api-Key' => $this->apiKey, 'X-Goog-FieldMask' => 'routes.distanceMeters'],
                'json' => [
                    'origin' => ['address' => $depot],
                    'destination' => ['address' => $address],
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_UNAWARE',
                ],
                'max_duration' => 5,
            ]);
            $status = $response->getStatusCode();
            $meters = 200 === $status ? ($response->toArray(false)['routes'][0]['distanceMeters'] ?? null) : null;
        } catch (ExceptionInterface $e) {
            // Erreur réseau ou délai dépassé ; le message de l'exception peut contenir l'adresse : non journalisé
            $this->logger->warning('Distance Google indisponible : {error}', ['error' => $e::class]);

            return null;
        }
        if (!\is_int($meters)) {
            // Adresse introuvable (aucun itinéraire), erreur ou quota (429)
            $this->logger->warning('Distance Google non obtenue (HTTP {status})', ['status' => $status]);

            return null;
        }

        return $meters;
    }
}
