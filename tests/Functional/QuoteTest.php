<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\ProductColor;
use App\Entity\ProductPhoto;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Ajout au devis et page « Mon devis » minimale, réglage « Devis en ligne » (story 1.13). Comportements navigateur : vérification manuelle. */
final class QuoteTest extends WebTestCase
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

    public function testProductPageHasQuantitySelectorAndAddButton(): void
    {
        $chaises = $this->category('Chaises', PricingType::Chairs);
        $product = $this->product($chaises, 'Chaise Napoléon', [15 => 350, 50 => 300], colors: ['Blanc']);

        $crawler = $this->client->request('GET', '/location/chaises/'.$product->getSlug());

        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form.add-form');
        self::assertSame(['submit->selection#add', (string) $product->getId(), '15', 'chaise', 'chaises'], [$form->attr('data-action'), $form->attr('data-product-id'), $form->attr('data-min'), $form->attr('data-one'), $form->attr('data-many')]);
        // Valeur de départ = minimum ; boutons − / + ; message d'erreur relié au champ
        $input = $form->filter('input#quantite');
        self::assertSame(['number', '15', '15', 'quantite-erreur'], [$input->attr('type'), $input->attr('value'), $input->attr('min'), $input->attr('aria-describedby')]);
        self::assertSame(['−', '+'], $form->filter('.quantity__step')->each(fn (Crawler $b) => $b->text()));
        self::assertSame('Ajouter à mon devis', $form->filter('button[type="submit"].button-primary')->text());
        self::assertSame('polite', $form->filter('[data-role="confirm"]')->attr('aria-live'));
        // Palier atteint au minimum : rendu par le serveur, surligné et écrit ; bornes lues par le navigateur
        self::assertSelectorTextSame('[data-role="tier"]', 'Palier appliqué : 15 à 49');
        self::assertSame([['15', '49', 'is-reached'], ['50', '', null]], $crawler->filter('.price-tiers tbody tr')->each(fn (Crawler $r) => [$r->attr('data-from'), $r->attr('data-to'), $r->attr('class')]));
        // Le coloris choisi est lu sur la fiche (data-selected-color, posé par color-picker)
        self::assertCount(1, $crawler->filter('[data-controller~="color-picker"] form.add-form'));
        self::assertCount(0, $crawler->filter('a[data-role="whatsapp"]'));
    }

    public function testProductCardAddsMinimum(): void
    {
        $tables = $this->category('Tables', PricingType::Tables);
        $product = $this->product($tables, 'Table ronde', [1 => 1500]);

        $form = $this->client->request('GET', '/location/tables')->filter('.product-card form.add-form');

        self::assertSame([(string) $product->getId(), '1', 'table', 'tables'], [$form->attr('data-product-id'), $form->attr('data-min'), $form->attr('data-one'), $form->attr('data-many')]);
        self::assertSame('Ajouter au devis : Table ronde', $form->filter('button.button-secondary.button-sm')->text());
        self::assertCount(0, $form->filter('input'));
        self::assertSame('polite', $form->filter('[data-role="confirm"]')->attr('aria-live'));
    }

    public function testServerHtmlHasNoSelectionNorCounter(): void
    {
        $product = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);

        foreach (['/', '/location', '/location/chaises', '/location/chaises/'.$product->getSlug(), '/mon-devis'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            // Compteur vide et masqué : rempli par le contrôleur selection
            $counter = $crawler->filter('header a[data-selection-target="counter"]');
            self::assertSame(['/mon-devis', '', ''], [$counter->attr('href'), $counter->attr('hidden'), $counter->text()], $url);
            self::assertStringNotContainsString('Mon devis (', (string) $this->client->getResponse()->getContent());
            self::assertSame('selection', $crawler->filter('body')->attr('data-controller'));
        }
    }

    public function testQuotePageIsAShellLoadingLinesFromIds(): void
    {
        $crawler = $this->client->request('GET', '/mon-devis');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Mon devis');
        $frame = $crawler->filter('turbo-frame#mon-devis-lignes');
        self::assertSame(['lines', '/mon-devis/lignes', ''], [$frame->attr('data-selection-target'), $frame->attr('data-url'), $frame->text()]);
        self::assertSame('Votre navigateur ne peut pas garder votre sélection.', $crawler->filter('[data-selection-target="storageError"]')->text());
    }

    public function testQuoteLinesListProductsFromIds(): void
    {
        $chaises = $this->category('Chaises', PricingType::Chairs);
        $chaise = $this->product($chaises, 'Chaise Napoléon', [15 => 350], colors: ['Blanc', 'Or'], photo: true);
        $nappe = $this->product($this->category('Nappes'), 'Nappe ronde', [1 => 800]);
        $gold = $chaise->getColors()->last()->getId();

        $crawler = $this->client->request('GET', '/mon-devis/lignes?'.http_build_query(['l' => [$chaise->getId().':'.$gold.':150', $nappe->getId().'::3']]));

        self::assertResponseIsSuccessful();
        $lines = $crawler->filter('turbo-frame#mon-devis-lignes .quote-line');
        self::assertSame([
            ['Chaise Napoléon', 'Coloris : Or', 'Quantité : 150'],
            ['Nappe ronde', 'Quantité : 3'],
        ], $lines->each(fn (Crawler $l) => array_merge([$l->filter('a.quote-line__name')->text()], $l->filter('p')->each(fn (Crawler $p) => $p->text()))));
        $img = $lines->first()->filter('img');
        self::assertSame(['64', '64'], [$img->attr('width'), $img->attr('height')]);
        self::assertStringNotContainsString('€', $crawler->text());
    }

    public function testEmptyHiddenAndInvalidQuoteLines(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise Napoléon', [15 => 350], visible: false);

        $crawler = $this->client->request('GET', '/mon-devis/lignes');
        self::assertSelectorTextSame('.quote-empty p', 'Votre devis est vide.');
        self::assertSame('/location', $crawler->filter('.quote-empty a')->attr('href'));

        // Lignes illisibles ignorées ; coloris étranger au produit ignoré ; produit masqué ou inconnu : « Retirer »
        $crawler = $this->client->request('GET', '/mon-devis/lignes?'.http_build_query(['l' => ['abc', '0::1', $chaise->getId().'::0', $chaise->getId().':999:15', '424242::1']]));
        self::assertResponseIsSuccessful();
        $lines = $crawler->filter('.quote-line');
        self::assertCount(2, $lines);
        self::assertStringContainsString("Ce produit n'est plus proposé", $lines->first()->text());
        self::assertStringNotContainsString('Coloris', $lines->first()->text());
        $remove = $lines->first()->filter('button[data-action="selection#remove"]');
        self::assertSame([(string) $chaise->getId(), '999'], [$remove->attr('data-selection-product-id-param'), $remove->attr('data-selection-color-id-param')]);
        self::assertStringStartsWith('Retirer', $remove->text());
        self::assertStringContainsString("Ce produit n'est plus proposé", $lines->last()->text());
        self::assertCount(0, $crawler->filter('a.quote-line__name'));

        // Liste de taille bornée
        $this->client->request('GET', '/mon-devis/lignes?'.http_build_query(['l' => array_fill(0, 150, '424242::1')]));
        self::assertCount(100, $this->client->getCrawler()->filter('.quote-line'));
    }

    public function testDisabledQuoteShowsWhatsappEverywhere(): void
    {
        $this->quoteOnline(false);
        $product = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise Napoléon', [15 => 350]);
        $message = fn (int $qty) => 'https://wa.me/33612345678?text='.rawurlencode("Bonjour, je souhaite un devis pour : Chaise Napoléon, quantité $qty.");

        $crawler = $this->client->request('GET', '/location/chaises/'.$product->getSlug());
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('form.add-form a[data-role="whatsapp"]');
        self::assertSame($message(15), $link->attr('href'));
        self::assertSame('Bonjour, je souhaite un devis pour : Chaise Napoléon, quantité {qty}.', $link->attr('data-message'));
        self::assertStringStartsWith('Demander sur WhatsApp', $link->text());
        // Le sélecteur de quantité reste : la quantité choisie part dans le message
        self::assertCount(1, $crawler->filter('input#quantite'));

        $crawler = $this->client->request('GET', '/location/chaises');
        self::assertSame($message(15), $crawler->filter('.product-card a[data-role="whatsapp"]')->attr('href'));

        foreach (['/', '/location', '/location/chaises', '/location/chaises/'.$product->getSlug(), '/mon-devis'] as $url) {
            $crawler = $this->client->request('GET', $url);
            $html = (string) $this->client->getResponse()->getContent();
            self::assertStringNotContainsString('Ajouter au devis', $html, $url);
            self::assertStringNotContainsString('Ajouter à mon devis', $html, $url);
            self::assertCount(0, $crawler->filter('[data-selection-target="counter"], [data-role="confirm"], turbo-frame#mon-devis-lignes'), $url);
        }
        self::assertSelectorTextSame('h1', 'Demander un devis');
        self::assertSame('https://wa.me/33612345678?text='.rawurlencode('Bonjour, je souhaite un devis.'), $crawler->selectLink('Demander sur WhatsApp')->attr('href'));

        $this->client->request('GET', '/mon-devis/lignes?l[]='.$product->getId().'::15');
        self::assertResponseStatusCodeSame(404);
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

    /**
     * @param array<int, int> $tiers  quantité minimale → prix en centimes
     * @param list<string>    $colors
     */
    private function product(Category $category, string $name, array $tiers, bool $visible = true, array $colors = [], bool $photo = false): Product
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
        foreach ($colors as $colorName) {
            $color = new ProductColor();
            $color->setName($colorName);
            $product->addColor($color);
        }
        if ($photo) {
            $image = new ProductPhoto();
            $image->setFilename('photo.jpg');
            $image->setAlt($name);
            $product->addPhoto($image);
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
