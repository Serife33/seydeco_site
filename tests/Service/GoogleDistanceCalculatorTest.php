<?php

namespace App\Tests\Service;

use App\Entity\Setting;
use App\Service\GoogleDistanceCalculator;
use App\Service\SettingsProvider;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Story 3.5 : appel Routes computeRoutes simulé (aucune requête réelle) ; tout échec donne null et un warning sans adresse ni clé. */
final class GoogleDistanceCalculatorTest extends TestCase
{
    private const string ADDRESS = '3 place de la Bourse, 33000 Bordeaux';
    private const string KEY = 'cle-de-test';

    /** @var list<array{string, string}> */
    private array $logs = [];

    private function calculator(MockHttpClient $http, string $key = self::KEY): GoogleDistanceCalculator
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findAll')->willReturn([new Setting(SettingsProvider::DEPOT_ADDRESS, '12 rue du Dépôt, 33530 Bassens')]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $logger = new class($this->logs) extends AbstractLogger {
            public function __construct(private array &$logs)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = [$level, $message.json_encode($context)];
            }
        };

        return new GoogleDistanceCalculator($http, new SettingsProvider($em), $logger, $key);
    }

    public function testCallsRoutesApiFromServer(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) {
            self::assertSame(['POST', 'https://routes.googleapis.com/directions/v2:computeRoutes'], [$method, $url]);
            self::assertContains('X-Goog-Api-Key: '.self::KEY, $options['headers']);
            self::assertContains('X-Goog-FieldMask: routes.distanceMeters', $options['headers']);
            self::assertSame(5.0, (float) $options['max_duration']);
            self::assertSame([
                'origin' => ['address' => '12 rue du Dépôt, 33530 Bassens'],
                'destination' => ['address' => self::ADDRESS],
                'travelMode' => 'DRIVE',
                'routingPreference' => 'TRAFFIC_UNAWARE',
            ], json_decode($options['body'], true));

            return new MockResponse('{"routes":[{"distanceMeters":10400}]}');
        });

        self::assertSame(10400, $this->calculator($http)->metersFromDepot(self::ADDRESS));
        self::assertSame([], $this->logs);
    }

    public function testNoKeyMeansNoCall(): void
    {
        $http = new MockHttpClient(fn () => self::fail('Aucun appel sans clé'));

        self::assertNull($this->calculator($http, '')->metersFromDepot(self::ADDRESS));
    }

    /** @return iterable<string, array{MockResponse|\Closure}> */
    public static function failures(): iterable
    {
        yield 'adresse introuvable' => [new MockResponse('{}')];
        yield 'erreur Google' => [new MockResponse('{"error":{}}', ['http_code' => 500])];
        yield 'quota dépassé' => [new MockResponse('{"error":{}}', ['http_code' => 429])];
        yield 'erreur réseau' => [new MockResponse('', ['error' => 'Could not resolve host'])];
        // Chaîne vide = délai dépassé pour MockResponse
        yield 'délai dépassé' => [new MockResponse((function () { yield ''; })())];
    }

    #[DataProvider('failures')]
    public function testFailureGivesNullAndWarningWithoutAddress(MockResponse $response): void
    {
        self::assertNull($this->calculator(new MockHttpClient($response))->metersFromDepot(self::ADDRESS));
        self::assertCount(1, $this->logs);
        self::assertSame('warning', $this->logs[0][0]);
        self::assertStringNotContainsString('Bourse', $this->logs[0][1]);
        self::assertStringNotContainsString(self::KEY, $this->logs[0][1]);
    }
}
