<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Tests\Double\FakeDistanceCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Story 3.6 : POST /estimation, chiffrage par PricingCalculator seulement, sans session, limité par IP. */
final class EstimateTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['related_product', 'pack_item', 'pack', 'price_tier', 'product_photo', 'product_color', 'product', 'category', 'distance_cache'] as $table) {
            $this->db->executeStatement("TRUNCATE TABLE $table");
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->setting('quote_online', '1');
        $this->setting('depot_address', '12 rue du Dépôt, 33530 Bassens');
        $container->get('cache.rate_limiter_dbal')->clear();
        FakeDistanceCalculator::reset();
        $this->em = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->setting('quote_online', '0');
        $this->setting('depot_address', '');
        parent::tearDown();
    }

    public function testPricesSelectionWithDiscountDepositAndBalance(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350, 100 => 300]);
        $table = $this->product($this->category('Tables', PricingType::Tables), 'Table ronde', [1 => 1500], seats: 8, priceWithChairs: 1200);

        $data = $this->estimate(['lines' => [['productId' => $chaise->getId(), 'quantity' => 101], ['productId' => $table->getId(), 'quantity' => 3]]]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame([
            ['product', 0, 300, 30300, "3,00\u{A0}€", "303,00\u{A0}€"],
            ['product', 1, 1500, 4500, "15,00\u{A0}€", "45,00\u{A0}€"],
        ], array_map(fn (array $l) => [$l['type'], $l['line'], $l['unitPrice'], $l['amount'], $l['unitPriceText'], $l['amountText']], \array_slice($data['lines'], 0, 2)));
        self::assertSame(['discount', 'Remise tables avec chaises', -900, "-9,00\u{A0}€"], [$data['lines'][2]['type'], $data['lines'][2]['label'], $data['lines'][2]['amount'], $data['lines'][2]['amountText']]);
        // 34 800 - 900 = 33 900 ; acompte 50 %
        self::assertSame([33900, 16950, 16950, 50, true], [$data['total'], $data['deposit'], $data['balance'], $data['depositPercent'], $data['excludesDelivery']]);
        self::assertSame("339,00\u{A0}€", $data['totalText']);
        self::assertSame([[], []], [$data['reasons'], $data['ignored']]);
        // Ni session ni cookie
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
        self::assertFalse($this->client->getResponse()->headers->has('Set-Cookie'));
    }

    public function testHiddenAndUnknownProductsAreIgnoredAndReported(): void
    {
        $chaises = $this->category('Chaises', PricingType::Chairs);
        $hidden = $this->product($chaises, 'Chaise masquée', [1 => 100], visible: false);
        $chaise = $this->product($chaises, 'Chaise', [15 => 350]);
        $noPrice = $this->product($chaises, 'Sans palier', []);

        $data = $this->estimate(['lines' => [
            ['productId' => $hidden->getId(), 'quantity' => 5],
            ['productId' => 424242, 'quantity' => 1],
            ['productId' => $chaise->getId(), 'quantity' => 10],
            ['productId' => $chaise->getId(), 'quantity' => 20],
            ['productId' => $noPrice->getId(), 'quantity' => 20],
        ]]);

        self::assertResponseIsSuccessful();
        self::assertSame([['line' => 0, 'productId' => $hidden->getId()], ['line' => 1, 'productId' => 424242], ['line' => 4, 'productId' => $noPrice->getId()]], $data['ignored']);
        // Positions dans la liste reçue ; sous le minimum : raison, pas de prix
        self::assertSame([[3, 7000]], array_map(fn (array $l) => [$l['line'], $l['amount']], $data['lines']));
        self::assertSame(['min_qty', 2, 'Minimum 15 pour « Chaise » : vous en avez 10.'], [$data['reasons'][0]['type'], $data['reasons'][0]['line'], $data['reasons'][0]['message']]);
        self::assertSame(7000, $data['total']);
    }

    /** @return iterable<string, array{string}> */
    public static function badRequests(): iterable
    {
        yield 'quantité à virgule' => ['{"lines":[{"productId":1,"quantity":2.5}]}'];
        yield 'quantité négative' => ['{"lines":[{"productId":1,"quantity":-3}]}'];
        yield 'quantité en texte' => ['{"lines":[{"productId":1,"quantity":"3"}]}'];
    }

    #[DataProvider('badRequests')]
    public function testQuantityNotWholeOrNegativeIsRefusedInPlainWords(string $body): void
    {
        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: $body);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['error' => 'Chaque quantité doit être un nombre entier, sans virgule, d\'au moins 1.'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testUnreadableRequestIsRefused(): void
    {
        foreach (['pas du json', '{"lines":"x"}', '{"lines":[{"productId":"1","quantity":1}]}'] as $body) {
            $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
            self::assertResponseStatusCodeSame(400, $body);
        }
    }

    public function testDeliveryUsesRoadDistanceOrSaysToConfirm(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $request = ['lines' => [['productId' => $chaise->getId(), 'quantity' => 100]], 'mode' => 'delivery', 'address' => '3 place de la Bourse, Bordeaux', 'options' => ['pickup' => true], 'date' => '2026-12-12'];

        // 10 400 m → 11 km × 2 × 1 voyage × 1 € = 22 € par trajet
        $data = $this->estimate($request);
        self::assertSame([['delivery', 2200], ['pickup', 2200]], array_map(fn (array $l) => [$l['type'], $l['amount']], \array_slice($data['lines'], 1)));
        self::assertFalse($data['excludesDelivery']);

        FakeDistanceCalculator::$meters = null;
        $data = $this->estimate(['address' => 'Adresse inconnue'] + $request);
        self::assertSame(['Livraison : à confirmer par Serife', 'Récupération : à confirmer par Serife'], array_column(\array_slice($data['lines'], 1), 'label'));
        self::assertTrue($data['excludesDelivery']);
        self::assertContains(['type' => 'distance_unknown', 'message' => 'Adresse introuvable : les frais de livraison seront confirmés par Serife.'], $data['reasons']);
        self::assertStringNotContainsString('GOOGLE', (string) $this->client->getResponse()->getContent());
    }

    public function testClosedQuoteAnswersInPlainWords(): void
    {
        $this->setting('quote_online', '0');

        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: '{"lines":[]}');

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['error' => 'Le devis en ligne est momentanément fermé.'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testLimitedPerIp(): void
    {
        static::getContainer()->get('limiter.estimate')->create('127.0.0.1')->consume(120);

        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: '{"lines":[]}');
        self::assertResponseStatusCodeSame(429);

        // Une autre IP n'est pas touchée
        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '10.0.0.2'], content: '{"lines":[]}');
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function estimate(array $body): array
    {
        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function setting(string $name, string $value): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
    }

    private function category(string $name, PricingType $type = PricingType::Other): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(mb_strtolower($name));
        $category->setPosition(1);
        $category->setPricingType($type);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /** @param array<int, int> $tiers quantité minimale → prix en centimes */
    private function product(Category $category, string $name, array $tiers, bool $visible = true, ?int $seats = null, ?int $priceWithChairs = null): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)));
        $product->setCategory($category);
        $product->setVisible($visible);
        $product->setMinQty(array_key_first($tiers) ?? 1);
        $product->setSeats($seats);
        $product->setPriceWithChairs($priceWithChairs);
        foreach ($tiers as $qty => $price) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
