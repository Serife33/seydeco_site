<?php

namespace App\Service;

use App\Repository\DistanceCacheRepository;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Cache et limite devant le calcul de distance (AD-10, AD-26) : même adresse autrement écrite = même clé ;
 * changer l'adresse du dépôt change la clé. Hors cache, ~10 calculs/min par IP, au-delà distance inconnue. Échec jamais mis en cache.
 */
#[AsDecorator(DistanceCalculatorInterface::class)]
final class CachedDistanceCalculator implements DistanceCalculatorInterface
{
    public function __construct(
        #[AutowireDecorated] private readonly DistanceCalculatorInterface $inner,
        private readonly DistanceCacheRepository $cache,
        private readonly AddressNormalizer $normalizer,
        private readonly SettingsProvider $settings,
        private readonly RateLimiterFactoryInterface $distanceLimiter,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function metersFromDepot(string $address): ?int
    {
        $depot = $this->normalizer->normalize($this->settings->get(SettingsProvider::DEPOT_ADDRESS));
        $destination = $this->normalizer->normalize($address);
        if ('' === $depot || '' === $destination) {
            return null;
        }
        $key = hash('sha256', $depot."\n".$destination);
        if (null !== $meters = $this->cache->meters($key)) {
            return $meters;
        }
        $ip = $this->requestStack->getMainRequest()?->getClientIp() ?? 'cli';
        if (!$this->distanceLimiter->create($ip)->consume()->isAccepted()) {
            return null;
        }
        $meters = $this->inner->metersFromDepot($address);
        if (null !== $meters) {
            $this->cache->save($key, $meters);
        }

        return $meters;
    }
}
