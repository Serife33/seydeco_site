<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Pack;
use App\Entity\PackItem;
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
 * Stories 1.14 (packs) et 1.15 (« Souvent loué avec ») côté public.
 * Navigateur (vérification manuelle) : ajustement / retrait des lignes, éclatement du pack dans localStorage, recopie des liens dans la confirmation.
 */
final class PacksAndRelatedTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['related_product', 'pack_item', 'pack', 'price_tier', 'product_photo', 'product_color', 'product', 'category'] as $table) {
            $this->db->executeStatement("TRUNCATE TABLE $table");
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->db->executeStatement("UPDATE setting SET value = '06 12 34 56 78' WHERE name = 'whatsapp_phone'");
        $this->quoteOnline(true);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->quoteOnline(false);
        parent::tearDown();
    }

    // --- Packs (story 1.14) ---

    public function testCatalogShowsVisiblePacksInOrder(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $masquee = $this->product($this->category('Nappes'), 'Nappe', [1 => 800], visible: false);
        $this->pack('Coin des mariés', [[$chaise, 20]], position: 2);
        $this->pack('Pack 100 invités', [[$chaise, 100]], position: 1);
        $this->pack('Pack masqué', [[$chaise, 100]], position: 3, visible: false);
        $this->pack('Pack sans produit visible', [[$masquee, 10]], position: 4);

        $crawler = $this->client->request('GET', '/location');

        self::assertSelectorTextSame('.packs h2', 'Packs');
        $cards = $crawler->filter('.packs .product-card');
        self::assertSame(['Pack 100 invités', 'Coin des mariés'], $cards->filter('.product-card__name')->each(fn (Crawler $n) => $n->text()));
        $link = $cards->first()->filter('a');
        self::assertSame(['/location/packs/pack-100-invites', 'Voir le pack : Pack 100 invités'], [$link->attr('href'), $link->text()]);
        $img = $cards->first()->filter('img');
        self::assertSame(['Photo de Pack 100 invités', '480', '600'], [$img->attr('alt'), $img->attr('width'), $img->attr('height')]);
    }

    public function testNoPackSectionWithoutVisiblePack(): void
    {
        $this->client->request('GET', '/location');
        self::assertSelectorNotExists('.packs');
    }

    public function testPackPageListsProductsWithTierPriceQuantityAndRemove(): void
    {
        $chaises = $this->category('Chaises', PricingType::Chairs);
        $chaise = $this->product($chaises, 'Chaise Napoléon', [15 => 350, 50 => 300]);
        $table = $this->product($this->category('Tables', PricingType::Tables), 'Table ronde', [1 => 1500]);
        $masque = $this->product($chaises, 'Chaise masquée', [1 => 100], visible: false);
        $this->pack('Pack 100 invités', [[$chaise, 100], [$masque, 10], [$table, 12]], description: 'Tout pour 100 personnes.');

        $crawler = $this->client->request('GET', '/location/packs/pack-100-invites');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Pack 100 invités');
        self::assertSelectorTextContains('.product-panel', 'Tout pour 100 personnes.');
        $form = $crawler->filter('form.pack-form');
        self::assertSame(['submit->selection#addPack', 'Pack 100 invités'], [$form->attr('data-action'), $form->attr('data-pack-name')]);
        // Produit masqué absent ; le reste du pack reste ajoutable
        $items = $form->filter('[data-role="pack-item"]');
        self::assertSame([
            [(string) $chaise->getId(), 'Chaise Napoléon', '3,00 € l\'unité', '100', '15', 'chaise', 'chaises'],
            [(string) $table->getId(), 'Table ronde', '15,00 € l\'unité', '12', '1', 'table', 'tables'],
        ], $items->each(fn (Crawler $i) => [
            $i->attr('data-product-id'), $i->filter('a.quote-line__name')->text(), str_replace("\u{A0}", ' ', $i->filter('[data-role="price"]')->text()),
            $i->filter('input[name="qty"]')->attr('value'), $i->filter('input[name="qty"]')->attr('min'), $i->attr('data-one'), $i->attr('data-many'),
        ]));
        // Paliers rendus par le serveur pour l'ajustement dans le navigateur (aucun calcul de prix côté client)
        self::assertSame([[15, 49, "3,50\u{A0}€"], [50, null, "3,00\u{A0}€"]], json_decode($items->first()->attr('data-tiers'), true));
        self::assertSame(['−', '+'], $items->first()->filter('.quantity__step')->each(fn (Crawler $b) => $b->text()));
        self::assertSame('selection#removePackItem', $items->first()->filter('button[data-role="remove"]')->attr('data-action'));
        self::assertStringStartsWith('Retirer', $items->first()->filter('button[data-role="remove"]')->text());
        self::assertSame('Ajouter le pack à mon devis', $form->filter('button[type="submit"].button-primary')->text());
        self::assertSame('polite', $form->filter('[data-role="confirm"]')->attr('aria-live'));
        self::assertSame('/location/packs/pack-100-invites', $form->filter('[data-role="pack-empty"] a')->attr('href'));
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['Disponible', 'En stock', 'Plus que', 'stock'] as $word) {
            self::assertStringNotContainsStringIgnoringCase($word, $crawler->filter('main')->text(), $word);
        }
        self::assertStringNotContainsString('Chaise masquée', $html);
    }

    public function testProposedQuantityFollowsRaisedMinimum(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $this->pack('Pack', [[$chaise, 20]]);
        $chaise = $this->em->find(Product::class, $chaise->getId());
        $chaise->setMinQty(30);
        $chaise->getPriceTiers()->first()->setMinQty(30);
        $this->em->flush();

        $input = $this->client->request('GET', '/location/packs/pack')->filter('input[name="qty"]');
        self::assertSame(['30', '30'], [$input->attr('value'), $input->attr('min')]);
    }

    public function testProductWithoutTierDoesNotBreakPackPage(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $chaise->removePriceTier($chaise->getPriceTiers()->first());
        $this->em->flush();
        $this->pack('Pack', [[$chaise, 20]]);

        $crawler = $this->client->request('GET', '/location/packs/pack');

        self::assertResponseIsSuccessful();
        self::assertSame('', $crawler->filter('[data-role="price"]')->attr('hidden'));
        self::assertSame('[]', $crawler->filter('[data-role="pack-item"]')->attr('data-tiers'));
    }

    public function testHiddenOrEmptyPackShowsMessageAndUnknownIs404(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $masquee = $this->product($this->category('Nappes'), 'Nappe', [1 => 800], visible: false);
        $this->pack('Pack masqué', [[$chaise, 100]], visible: false);
        $this->pack('Pack vide', [[$masquee, 10]]);

        foreach (['pack-masque', 'pack-vide'] as $slug) {
            $crawler = $this->client->request('GET', '/location/packs/'.$slug);
            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', "Ce pack n'est plus proposé.");
            self::assertSame('/location', $crawler->selectLink('Voir le catalogue')->attr('href'));
            self::assertCount(0, $crawler->filter('form.pack-form'));
        }

        $this->client->request('GET', '/location/packs/inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDisabledQuoteTurnsPackButtonIntoWhatsapp(): void
    {
        $this->quoteOnline(false);
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise Napoléon', [15 => 350]);
        $table = $this->product($this->category('Tables', PricingType::Tables), 'Table ronde', [1 => 1500]);
        $this->pack('Pack 100 invités', [[$chaise, 100], [$table, 12]]);

        $crawler = $this->client->request('GET', '/location/packs/pack-100-invites');

        self::assertStringNotContainsString('Ajouter le pack', (string) $this->client->getResponse()->getContent());
        self::assertCount(0, $crawler->filter('[data-role="confirm"]'));
        $link = $crawler->filter('a[data-role="whatsapp"]');
        self::assertSame('https://wa.me/33612345678?text='.rawurlencode('Bonjour, je souhaite un devis pour le pack « Pack 100 invités » : Chaise Napoléon × 100, Table ronde × 12.'), $link->attr('href'));
        self::assertSame('Bonjour, je souhaite un devis pour le pack « Pack 100 invités » : {items}.', $link->attr('data-message'));
        self::assertStringStartsWith('Demander sur WhatsApp', $link->text());
    }

    // --- Souvent loué avec (story 1.15) ---

    public function testProductPageShowsVisibleRelatedAfterPracticalInfo(): void
    {
        $tables = $this->category('Tables', PricingType::Tables);
        $table = $this->product($tables, 'Table ronde', [1 => 1500]);
        $nappes = $this->category('Nappes');
        $nappe = $this->product($nappes, 'Nappe ronde', [1 => 800]);
        $masquee = $this->product($nappes, 'Nappe masquée', [1 => 800], visible: false);
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise Napoléon', [15 => 350]);
        $this->relate($table, [$chaise, $masquee, $nappe]);

        $crawler = $this->client->request('GET', '/location/tables/'.$table->getSlug());

        $section = $crawler->filter('section.related');
        self::assertSame(['Souvent loué avec', 'souvent-loue-avec'], [$section->filter('h2.accent-sm')->text(), $section->attr('aria-labelledby')]);
        // Ordre choisi par Serife, associé masqué ignoré ; cartes produit réduites
        self::assertSame(['Chaise Napoléon', 'Nappe ronde'], $section->filter('.product-grid--related .product-card h3.product-card__name')->each(fn (Crawler $n) => $n->text()));
        // Après le bloc infos pratiques dans l'ordre de lecture, jamais en popup
        $html = (string) $this->client->getResponse()->getContent();
        self::assertGreaterThan(strpos($html, 'class="info-block"'), strpos($html, 'class="related"'));
        self::assertCount(0, $crawler->filter('dialog section.related, [role="dialog"]'));

        // Confirmation d'ajout de la fiche : liens « Souvent loué avec » prêts à recopier
        $links = $crawler->filter('.product-panel form.add-form template[data-role="related"]');
        self::assertSame(1, preg_match('~^Souvent loué avec : <a href="/location/chaises/[^"]+" data-turbo-frame="_top">Chaise Napoléon</a>, <a href="/location/nappes/[^"]+" data-turbo-frame="_top">Nappe ronde</a>$~', trim($links->html())), $links->html());
        // Les cartes associées n'emportent pas les liens de la fiche
        self::assertCount(0, $section->filter('template'));
    }

    public function testNoRelatedSectionWithoutVisibleRelated(): void
    {
        $nappes = $this->category('Nappes');
        $nappe = $this->product($nappes, 'Nappe ronde', [1 => 800]);
        $this->relate($nappe, [$this->product($nappes, 'Nappe masquée', [1 => 800], visible: false)]);

        $crawler = $this->client->request('GET', '/location/nappes/'.$nappe->getSlug());

        self::assertCount(0, $crawler->filter('section.related, template[data-role="related"]'));
    }

    public function testProductCardsCarryRelatedLinksForConfirmation(): void
    {
        $tables = $this->category('Tables', PricingType::Tables);
        $table = $this->product($tables, 'Table ronde', [1 => 1500]);
        $this->product($tables, 'Table seule', [1 => 1500]);
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise Napoléon', [15 => 350]);
        $this->relate($table, [$chaise]);

        $crawler = $this->client->request('GET', '/location/tables');

        $cards = $crawler->filter('.product-card');
        self::assertSame(['Table ronde', 'Table seule'], $cards->filter('.product-card__name')->each(fn (Crawler $n) => $n->text()));
        self::assertStringContainsString('>Chaise Napoléon</a>', $cards->first()->filter('form template[data-role="related"]')->html());
        self::assertCount(0, $cards->last()->filter('template'));

        // Devis en ligne désactivé : pas de confirmation, donc pas de liens
        $this->quoteOnline(false);
        self::assertCount(0, $this->client->request('GET', '/location/tables')->filter('template[data-role="related"]'));
    }

    private function quoteOnline(bool $on): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$on ? '1' : '0', 'quote_online']);
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
    private function product(Category $category, string $name, array $tiers, bool $visible = true): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)));
        $product->setCategory($category);
        $product->setVisible($visible);
        $product->setMinQty(array_key_first($tiers));
        foreach ($tiers as $qty => $price) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @param list<array{Product, int}> $items */
    private function pack(string $name, array $items, int $position = 1, bool $visible = true, ?string $description = null): Pack
    {
        $pack = new Pack();
        $pack->setName($name);
        $pack->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
        $pack->setDescription($description);
        $pack->setPhoto('pack.jpg');
        $pack->setPhotoAlt('Photo de '.$name);
        $pack->setPosition($position);
        $pack->setVisible($visible);
        foreach ($items as [$product, $qty]) {
            $pack->addItem(new PackItem($product, $qty));
        }
        $this->em->persist($pack);
        $this->em->flush();

        return $pack;
    }

    /** @param list<Product> $related */
    private function relate(Product $product, array $related): void
    {
        foreach ($related as $i => $other) {
            $row = new RelatedProduct($other);
            $row->setPosition($i);
            $product->addRelatedProduct($row);
        }
        $this->em->flush();
    }
}
