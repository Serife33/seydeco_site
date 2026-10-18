<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\RelatedProduct;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Stories 3.7 + 3.8 : Barre d'estimation (coquille vide et cacheable), Encadré Conditions, tables suggérées, partage.
 * Remplissage par le navigateur (localStorage, lien ?s=, partage natif, clavier) : vérification manuelle.
 */
final class EstimateBarShareTest extends WebTestCase
{
    private const array SETTINGS = [
        'quote_online' => '1',
        'deposit_percent' => '50',
        'deposit_amount' => '30000',
        'return_terms' => 'Chaises rendues propres, nappes en sac.',
        'pickup_terms' => 'Véhicule adapté et sangles.',
        'fuel_terms' => 'Révisable selon le prix du carburant.',
        'payment_methods' => 'virement,wero',
        'bank_holder' => 'TITULAIRE SECRET',
        'iban' => 'FR7630006000011234567890189',
        'bic' => 'AGRIFRPPXXX',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['related_product', 'pack_item', 'pack', 'price_tier', 'product_photo', 'product_color', 'product', 'category'] as $table) {
            $this->db->executeStatement("TRUNCATE TABLE $table");
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (self::SETTINGS as $name => $value) {
            $this->saved[$name] = (string) $this->db->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
            $this->setting($name, $value);
        }
        $container->get('cache.rate_limiter_dbal')->clear();
        $this->em = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setting($name, $value);
        }
        parent::tearDown();
    }

    public function testEstimateBarIsAnEmptyHiddenShellBelowCookieBanner(): void
    {
        $product = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);

        foreach (['/', '/location', '/location/chaises', '/location/chaises/'.$product->getSlug()] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            // Cacheable : ni montant ni compteur, remplie par le contrôleur selection depuis POST /estimation
            self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('public'), $url);
            $bar = $crawler->filter('.bottom-bars > .estimate-bar');
            self::assertSame(['bar', '/estimation', '', 'Estimation de mon devis'], [$bar->attr('data-selection-target'), $bar->attr('data-url'), $bar->attr('hidden'), $bar->attr('aria-label')], $url);
            self::assertSame(['', ''], [$bar->filter('[data-role="count"]')->text(), $bar->filter('[data-role="total"]')->text()]);
            self::assertSame(['Voir mon devis', '/mon-devis'], [$bar->filter('a.button-inverse')->text(), $bar->filter('a.button-inverse')->attr('href')]);
            // Bandeau cookies empilé au-dessus
            self::assertSame(['cookie-banner', 'estimate-bar'], $crawler->filter('.bottom-bars > section')->each(fn (Crawler $s) => $s->attr('class')));
        }

        // Ni sur Mon devis, ni quand le devis en ligne est fermé
        $crawler = $this->client->request('GET', '/mon-devis');
        self::assertCount(0, $crawler->filter('.estimate-bar'));
        $this->setting('quote_online', '0');
        $crawler = $this->client->request('GET', '/location');
        self::assertCount(0, $crawler->filter('.estimate-bar'));
    }

    public function testConditionsPanelFollowsTotalWithoutBankDetails(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise Napoléon', [15 => 350], breakage: 4500, loss: 5000);
        $nappe = $this->product($this->category('Nappes'), 'Nappe', [1 => 800]);

        $crawler = $this->client->request('GET', '/mon-devis/lignes?'.http_build_query(['l' => [$chaise->getId().'::150', $nappe->getId().'::3']]));

        self::assertResponseIsSuccessful();
        // Suit immédiatement l'estimation, jamais replié
        $panel = $crawler->filter('.quote-summary + .conditions-panel');
        self::assertCount(1, $panel);
        self::assertCount(0, $panel->filter('details, [hidden], [aria-expanded]'));
        self::assertSame('Avant de réserver', $panel->filter('h2')->text());
        self::assertSame(['Acompte', 'Caution', 'Casse ou perte', 'Retour', 'Retrait au dépôt', 'Tarif de livraison', 'Paiement'], $panel->filter('h3')->each(fn (Crawler $h) => $h->text()));
        $items = $panel->filter('.conditions-panel__list > li')->each(fn (Crawler $li) => $li->filter('div')->text());
        self::assertStringContainsString("50 % du total, versé pour réserver. Il n'est pas remboursable.", $items[0]);
        self::assertStringContainsString("300\u{A0}€, par chèque, avec présentation d'une pièce d'identité.", $items[1]);
        // Casse et perte des seuls produits de la sélection qui en ont
        self::assertSame(["Chaise Napoléon : casse 45\u{A0}€, perte 50\u{A0}€"], $panel->filter('[data-damage-product]')->each(fn (Crawler $li) => $li->text()));
        self::assertStringContainsString('Chaises rendues propres, nappes en sac.', $items[3]);
        self::assertStringContainsString('Véhicule adapté et sangles.', $items[4]);
        self::assertStringContainsString('Révisable selon le prix du carburant.', $items[5]);
        self::assertStringContainsString('Virement, Wero.', $items[6]);
        self::assertSame('Partager ma sélection', $crawler->filter('.quote-share button[data-action="selection#share"]')->text());
        self::assertSame('status', $crawler->filter('[data-role="share-status"]')->attr('role'));

        // Coordonnées bancaires : nulle part (lignes, coquille, estimation, catalogue)
        $pages = [(string) $this->client->getResponse()->getContent()];
        foreach (['/mon-devis', '/location', '/'] as $url) {
            $this->client->request('GET', $url);
            $pages[] = (string) $this->client->getResponse()->getContent();
        }
        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['lines' => [['productId' => $chaise->getId(), 'quantity' => 150]]]));
        $pages[] = (string) $this->client->getResponse()->getContent();
        foreach ($pages as $html) {
            foreach (['TITULAIRE SECRET', 'FR7630006000011234567890189', 'FR76 3000', 'AGRIFRPPXXX'] as $secret) {
                self::assertStringNotContainsString($secret, $html);
            }
        }
    }

    public function testEmptySettingsHideTheirConditionLines(): void
    {
        foreach (['deposit_amount', 'return_terms', 'pickup_terms', 'fuel_terms', 'payment_methods'] as $name) {
            $this->setting($name, '');
        }
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);

        $crawler = $this->client->request('GET', '/mon-devis/lignes?l[]='.$chaise->getId().'::15');

        self::assertSame(['Acompte', 'Caution'], $crawler->filter('.conditions-panel h3')->each(fn (Crawler $h) => $h->text()));
        self::assertSame("Par chèque, avec présentation d'une pièce d'identité.", $crawler->filter('.conditions-panel li')->eq(1)->filter('p')->text());
    }

    public function testRelatedOnQuoteExcludesSelectedProducts(): void
    {
        $chaises = $this->category('Chaises', PricingType::Chairs);
        $chaise = $this->product($chaises, 'Chaise', [15 => 350]);
        $housse = $this->product($chaises, 'Housse', [1 => 200]);
        $noeud = $this->product($chaises, 'Noeud', [1 => 100]);
        $masque = $this->product($chaises, 'Masqué', [1 => 100], visible: false);
        foreach ([$housse, $noeud, $masque] as $i => $other) {
            $row = new RelatedProduct($other);
            $row->setPosition($i);
            $chaise->addRelatedProduct($row);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/mon-devis/lignes?'.http_build_query(['l' => [$chaise->getId().'::15', $noeud->getId().'::2']]));

        $related = $crawler->filter('section.related[data-role="related"]');
        self::assertSame([null, 'Souvent loué avec'], [$related->attr('hidden'), $related->filter('h2.accent-sm')->text()]);
        self::assertSame(['Housse'], $related->filter('.product-card__name')->each(fn (Crawler $n) => $n->text()));
        // Tables suggérées : bloc vide, rempli depuis POST /estimation
        self::assertSame(['', ''], [$related->filter('[data-role="tables"]')->attr('hidden'), $related->filter('[data-role="tables-text"]')->text()]);

        // Sans associé : section masquée jusqu'à une suggestion de tables
        $crawler = $this->client->request('GET', '/mon-devis/lignes?l[]='.$housse->getId().'::1');
        self::assertSame('', $crawler->filter('section.related')->attr('hidden'));
    }

    public function testEstimateSuggestsTablesRoundedUp(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $table = $this->product($this->category('Tables', PricingType::Tables), 'Table ronde', [1 => 1500], seats: 8);
        $sansPlaces = $this->product($this->category('Mange-debout', PricingType::Tables), 'Mange-debout', [1 => 900]);

        // 150 chaises ÷ 8 = 18,75 → 19 tables ; 1 déjà choisie → 18 à ajouter
        $data = $this->estimate([[$sansPlaces, 2], [$chaise, 150], [$table, 1]]);
        self::assertSame([
            'line' => 2,
            'productName' => 'Table ronde',
            'missing' => 18,
            'text' => 'Pour 150 chaises, il faut environ 19 tables de 8 places.',
            'buttonText' => 'Ajouter 18 tables',
        ], $data['tables']);

        self::assertSame('Ajouter 1 table', $this->estimate([[$chaise, 150], [$table, 18]])['tables']['buttonText']);
        // Tables suffisantes, sans chaise ou sans table à places : rien
        self::assertNull($this->estimate([[$chaise, 150], [$table, 19]])['tables']);
        self::assertNull($this->estimate([[$table, 3]])['tables']);
        self::assertNull($this->estimate([[$chaise, 150], [$sansPlaces, 3]])['tables']);
    }

    public function testQuoteShellHasInlineShareChoiceAndDamagedLinkMessage(): void
    {
        // Le lien partagé est lu par le navigateur : la coquille reste la même, sans stockage serveur
        $crawler = $this->client->request('GET', '/mon-devis?s=MTIuLjE1MCwzLjQuMg');

        self::assertResponseIsSuccessful();
        $error = $crawler->filter('[data-selection-target="shareError"]');
        self::assertSame(["Une partie du lien n'a pas pu être lue.", '', 'status'], [$error->text(), $error->attr('hidden'), $error->attr('role')]);
        $choice = $crawler->filter('[data-selection-target="shareChoice"]');
        self::assertSame('', $choice->attr('hidden'));
        self::assertSame([['Remplacer ma sélection', 'selection#replaceShared'], ['Ajouter à ma sélection', 'selection#addShared']], $choice->filter('button')->each(fn (Crawler $b) => [$b->text(), $b->attr('data-action')]));
        self::assertCount(0, $crawler->filter('dialog, [role="dialog"]'));
        self::assertStringNotContainsString('MTIuLjE1MCwzLjQuMg', (string) $this->client->getResponse()->getContent());
    }

    /**
     * @param list<array{Product, int}> $lines
     *
     * @return array<string, mixed>
     */
    private function estimate(array $lines): array
    {
        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['lines' => array_map(fn (array $l) => ['productId' => $l[0]->getId(), 'quantity' => $l[1]], $lines)]));
        self::assertResponseIsSuccessful();

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
    private function product(Category $category, string $name, array $tiers, bool $visible = true, ?int $seats = null, ?int $breakage = null, ?int $loss = null): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)));
        $product->setCategory($category);
        $product->setVisible($visible);
        $product->setMinQty(array_key_first($tiers));
        $product->setSeats($seats);
        $product->setBreakagePrice($breakage);
        $product->setLossPrice($loss);
        foreach ($tiers as $qty => $price) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
