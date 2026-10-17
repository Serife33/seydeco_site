<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\EventType;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\ProductColor;
use App\Entity\ProductPhoto;
use App\Entity\Style;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/** Catalogue public : catégories, grille, recherche, filtres, tri (stories 1.8 + 1.11). */
final class CatalogTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['product_style', 'product_event_type', 'style', 'event_type', 'price_tier', 'product_photo', 'product_color', 'product', 'category'] as $table) {
            $db->executeStatement("TRUNCATE TABLE $table");
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $db->executeStatement("UPDATE setting SET value = '06 12 34 56 78' WHERE name = 'whatsapp_phone'");
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCatalogShowsVisibleCategoriesInSerifesOrder(): void
    {
        $this->category('Tables', 2);
        $this->category('Chaises', 1);
        $this->category('Vaisselle', 3, visible: false);

        $crawler = $this->client->request('GET', '/location');

        self::assertResponseIsSuccessful();
        self::assertSame(['Chaises', 'Tables'], $crawler->filter('.category-tile a')->each(fn (Crawler $a) => $a->text()));
        self::assertSame('/location/chaises', $crawler->filter('.category-tile a')->first()->attr('href'));
        // En-tête : lien Catalogue et recherche
        self::assertCount(1, $crawler->filter('header a[href="/location"]'));
        self::assertCount(1, $crawler->filter('header form[action="/recherche"] input[name="q"]'));
    }

    public function testCategoryPageShowsVisibleProductsWithFromPrice(): void
    {
        $chaises = $this->category('Chaises', 1);
        $this->product($chaises, 'Chaise Napoléon', [15 => 350, 50 => 300], photo: true);
        $this->product($chaises, 'Chaise pliante', [1 => 200]);
        $this->product($chaises, 'Chaise cachée', [1 => 100], visible: false);

        $crawler = $this->client->request('GET', '/location/chaises');

        self::assertResponseIsSuccessful();
        self::assertSame(['Chaise Napoléon', 'Chaise pliante'], $this->names($crawler));
        self::assertSelectorTextContains('.product-card', "dès 3,00\u{A0}€");
        self::assertSelectorTextContains('h1', 'Chaises');
        self::assertSelectorTextContains('title', 'Chaises');
        self::assertSelectorTextContains('.breadcrumb [aria-current="page"]', 'Chaises');
        self::assertSame('polite', $crawler->filter('#catalog-count')->attr('aria-live'));
        self::assertSelectorTextSame('#catalog-count', '2 produits');
        // Photo : dimensions réservées ; 1re rangée chargée tout de suite
        $img = $crawler->filter('.product-card img');
        self::assertSame(['480', '600', null], [$img->attr('width'), $img->attr('height'), $img->attr('loading')]);
    }

    public function testNoStockOrAvailabilityMentionAnywhere(): void
    {
        $chaises = $this->category('Chaises', 1);
        $product = $this->product($chaises, 'Chaise Napoléon', [1 => 300], colors: ['Blanc']);
        $product->setStock(3);
        $this->em->flush();

        foreach (['/location', '/location/chaises', '/recherche?q=chaise'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $text = mb_strtolower($crawler->filter('body')->text());
            foreach (['disponible', 'disponibilité', 'en stock', 'plus que', 'stock'] as $word) {
                self::assertStringNotContainsString($word, $text, "« $word » affiché sur $url");
            }
            self::assertCount(0, $crawler->filter('input[type="date"], input[name*="date"]'), "Filtre de date sur $url");
        }
    }

    public function testPaginationShowsMoreWithoutInfiniteScroll(): void
    {
        $chaises = $this->category('Chaises', 1);
        for ($i = 1; $i <= 30; ++$i) {
            $this->product($chaises, \sprintf('Chaise %02d', $i), [1 => 100], photo: true, persistOnly: true);
        }
        $this->em->flush();

        $crawler = $this->client->request('GET', '/location/chaises');
        self::assertCount(24, $crawler->filter('.product-card'));
        self::assertSelectorTextSame('#catalog-count', '30 produits');
        self::assertCount(20, $crawler->filter('.product-card img[loading="lazy"]'), '1re rangée (4) chargée tout de suite');
        $more = $crawler->selectLink('Voir plus');
        self::assertStringContainsString('page=2', $more->attr('href'));

        $crawler = $this->client->click($more->link());
        self::assertCount(30, $crawler->filter('.product-card'));
        self::assertCount(0, $crawler->selectLink('Voir plus'));

        // Page hors limites : dernière page
        $crawler = $this->client->request('GET', '/location/chaises?page=99');
        self::assertResponseIsSuccessful();
        self::assertCount(30, $crawler->filter('.product-card'));
    }

    public function testHiddenUnknownAndEmptyCategories(): void
    {
        $this->category('Vaisselle', 1, visible: false);
        $this->category('Nappes', 2);

        $crawler = $this->client->request('GET', '/location/vaisselle');
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('main', "Cette catégorie n'est plus proposée.");
        self::assertSame('/location', $crawler->selectLink('Voir le catalogue')->attr('href'));

        $this->client->request('GET', '/location/inconnue');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/location/nappes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.catalog__empty', 'Aucun produit ne correspond.');
    }

    public function testSearchIgnoresAccentsCaseAndSimplePlurals(): void
    {
        $chaises = $this->category('Chaises', 1);
        $tables = $this->category('Tables', 2);
        $this->product($chaises, 'Chaise Napoléon', [1 => 300], colors: ['Ivoire']);
        $this->product($tables, 'Table ronde', [1 => 1500], description: 'Plateau en bois massif');
        $this->product($this->category('Archives', 3, visible: false), 'Chaise ancienne', [1 => 100]);

        foreach (['chaise napoleon', 'Chaises', 'CHAISE NAPOLÉON', 'ivoire'] as $query) {
            $crawler = $this->client->request('GET', '/recherche?q='.urlencode($query));
            self::assertResponseIsSuccessful();
            self::assertSame(['Chaise Napoléon'], $this->names($crawler), "Recherche « $query »");
        }
        // Description et nom de catégorie
        self::assertSame(['Table ronde'], $this->names($this->client->request('GET', '/recherche?q=plateaux')));
        self::assertSame(['Table ronde'], $this->names($this->client->request('GET', '/recherche?q=tables')));
        self::assertSame('tables', $this->client->getCrawler()->filter('header input[name="q"]')->attr('value'));

        $this->client->request('GET', '/recherche?q=+');
        self::assertResponseRedirects('/location');
    }

    public function testFiltersAreInUrlAndCombine(): void
    {
        $chaises = $this->category('Chaises', 1);
        $classique = new Style('Classique');
        $boheme = new Style('Bohème');
        $mariage = new EventType('Mariage');
        $this->em->persist($classique);
        $this->em->persist($boheme);
        $this->em->persist($mariage);
        $this->product($chaises, 'Chaise Napoléon', [1 => 300], colors: ['Blanc', 'Or'], styles: [$classique], events: [$mariage]);
        $this->product($chaises, 'Chaise bistrot', [1 => 200], colors: ['Blanc'], styles: [$boheme]);
        $this->product($chaises, 'Chaise noire', [1 => 250], colors: ['Noir'], styles: [$classique]);

        $crawler = $this->client->request('GET', '/location/chaises');
        self::assertSelectorTextSame('.catalog__filter-toggle', 'Filtrer (0)');
        self::assertSame(['Blanc', 'Noir', 'Or'], $crawler->filter('input[name="couleur[]"]')->each(fn (Crawler $i) => $i->attr('value')));
        self::assertCount(0, $crawler->filter('input[name="categorie[]"]'), 'pas de filtre catégorie dans une catégorie');

        // Sans JavaScript : « Appliquer » soumet le formulaire en GET
        $form = $crawler->selectButton('Appliquer')->form();
        $form['couleur'][0]->tick();
        $form['style'][1]->tick(); // Bohème, Classique : par nom
        $crawler = $this->client->submit($form);

        $url = urldecode((string) $this->client->getRequest()->getUri());
        // Le navigateur envoie couleur[]=… ; le client de test numérote les crochets
        self::assertMatchesRegularExpression('/couleur\[\d*\]=Blanc/', $url);
        self::assertMatchesRegularExpression('/style\[\d*\]='.$classique->getId().'/', $url);
        self::assertSame(['Chaise Napoléon'], $this->names($crawler));
        self::assertSelectorTextSame('#catalog-count', '1 produit');
        self::assertSelectorTextSame('.catalog__filter-toggle', 'Filtrer (2)');
        self::assertSelectorTextSame('.catalog-filters__show', 'Voir 1 produit');

        $crawler = $this->client->request('GET', '/location/chaises?evenement[]='.$mariage->getId().'&couleur[]=Noir');
        self::assertSelectorTextContains('.catalog__empty', 'Aucun produit ne correspond.');
        self::assertSame('/location/chaises', $crawler->selectLink('Effacer les filtres')->attr('href'));
        self::assertStringStartsWith('https://wa.me/33612345678?text=', $crawler->filter('.catalog__empty a[href^="https://wa.me/"]')->attr('href'));

        // Recherche : filtre catégorie proposé, recherche conservée
        $crawler = $this->client->request('GET', '/recherche?q=chaise&categorie[]=chaises&couleur[]=noir');
        self::assertSame(['Chaise noire'], $this->names($crawler));
        self::assertSame('chaise', $crawler->filter('#filtres-form input[name="q"]')->attr('value'));
        self::assertCount(1, $crawler->filter('input[name="categorie[]"][checked]'));
    }

    public function testSorts(): void
    {
        $tables = $this->category('Tables', 2);
        $chaises = $this->category('Chaises', 1);
        $this->product($tables, 'Table ronde', [1 => 1500]);
        $this->product($chaises, 'Chaise pliante', [1 => 200, 50 => 150]);
        $this->product($chaises, 'Chaise Napoléon', [1 => 300]);
        $this->product($chaises, 'Chaise sans prix', []);
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("UPDATE product SET created_at = '2026-01-01 10:00:00' WHERE name = 'Chaise pliante'");
        $db->executeStatement("UPDATE product SET created_at = '2026-03-01 10:00:00' WHERE name = 'Table ronde'");
        $db->executeStatement("UPDATE product SET created_at = '2026-02-01 10:00:00' WHERE name = 'Chaise Napoléon'");
        $db->executeStatement("UPDATE product SET created_at = '2025-01-01 10:00:00' WHERE name = 'Chaise sans prix'");

        $url = '/recherche?categorie[]=chaises&categorie[]=tables';
        $popular = ['Chaise Napoléon', 'Chaise pliante', 'Chaise sans prix', 'Table ronde'];
        self::assertSame($popular, $this->names($this->client->request('GET', $url)));
        // Sans prix « dès » : en dernier
        self::assertSame(['Chaise pliante', 'Chaise Napoléon', 'Table ronde', 'Chaise sans prix'], $this->names($this->client->request('GET', $url.'&tri=prix')));
        self::assertSame(['Table ronde', 'Chaise Napoléon', 'Chaise pliante', 'Chaise sans prix'], $this->names($this->client->request('GET', $url.'&tri=nouveautes')));
        $crawler = $this->client->request('GET', $url.'&tri=inconnu');
        self::assertSame($popular, $this->names($crawler));
        self::assertSame('populaires', $crawler->filter('#tri option[selected]')->attr('value'));
    }

    public function testHandTypedAndStaleUrlsDegradeGracefully(): void
    {
        $chaises = $this->category('Chaises', 1);
        $this->category('Archives', 2, visible: false);
        $this->product($chaises, 'Chaise Napoléon', [1 => 300], colors: ['Blanc']);
        $this->product($chaises, 'Chaise noire', [1 => 250], colors: ['Noir']);
        for ($i = 1; $i <= 24; ++$i) {
            $this->product($chaises, "Chaise $i", [1 => 100], persistOnly: true);
        }
        $this->em->flush();

        // Paramètre scalaire et autre casse : ramené à la valeur proposée, cochable/décochable
        $crawler = $this->client->request('GET', '/location/chaises?couleur=blanc');
        self::assertResponseIsSuccessful();
        self::assertSame(['Chaise Napoléon'], $this->names($crawler));
        self::assertSame('Blanc', $crawler->filter('input[name="couleur[]"][checked]')->attr('value'));

        // Valeurs inconnues (id périmé, catégorie masquée) ignorées et non comptées
        $crawler = $this->client->request('GET', '/location/chaises?style[]=999&couleur[]=Rose');
        self::assertSelectorTextSame('#catalog-count', '26 produits');
        self::assertSelectorTextSame('.catalog__filter-toggle', 'Filtrer (0)');
        $this->client->request('GET', '/recherche?categorie[]=archives');
        self::assertResponseRedirects('/location');

        // Aucun mot exploitable = recherche vide
        foreach (['a', '!!'] as $q) {
            $this->client->request('GET', '/recherche?q='.urlencode($q));
            self::assertResponseRedirects('/location');
        }

        // « slug » dans l'URL ne détourne pas « Voir plus » vers une autre catégorie
        $crawler = $this->client->request('GET', '/location/chaises?slug=archives');
        self::assertStringStartsWith('/location/chaises?', $crawler->selectLink('Voir plus')->attr('href'));
    }

    /** @return list<string> */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('.product-card__name')->each(fn (Crawler $n) => $n->text());
    }

    private function category(string $name, int $position, bool $visible = true): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(mb_strtolower($name));
        $category->setPosition($position);
        $category->setPricingType(PricingType::Other);
        $category->setVisible($visible);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /**
     * @param array<int, int>   $tiers  quantité minimale → prix en centimes
     * @param list<string>      $colors
     * @param list<Style>       $styles
     * @param list<EventType>   $events
     */
    private function product(Category $category, string $name, array $tiers, bool $visible = true, array $colors = [], array $styles = [], array $events = [], ?string $description = null, bool $photo = false, bool $persistOnly = false): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(3)));
        $product->setCategory($category);
        $product->setVisible($visible);
        $product->setDescription($description);
        $product->setMinQty(array_key_first($tiers) ?? 1);
        foreach ($tiers as $qty => $price) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }
        foreach ($colors as $colorName) {
            $color = new ProductColor();
            $color->setName($colorName);
            $product->addColor($color);
        }
        array_map($product->addStyle(...), $styles);
        array_map($product->addEventType(...), $events);
        if ($photo) {
            $image = new ProductPhoto();
            $image->setFilename('photo.jpg');
            $image->setAlt($name.' de face');
            $product->addPhoto($image);
        }
        $this->em->persist($product);
        if (!$persistOnly) {
            $this->em->flush();
        }

        return $product;
    }
}
