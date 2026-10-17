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

/** Catalogue public : catégories, grille, recherche, filtres, tri, fiche produit (stories 1.8 à 1.11). */
final class CatalogTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['related_product', 'pack_item', 'pack', 'product_style', 'product_event_type', 'style', 'event_type', 'price_tier', 'product_photo', 'product_color', 'product', 'category'] as $table) {
            $db->executeStatement("TRUNCATE TABLE $table");
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $db->executeStatement("UPDATE setting SET value = '06 12 34 56 78' WHERE name = 'whatsapp_phone'");
        $db->executeStatement("UPDATE setting SET value = '' WHERE name = 'deposit_amount'");
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

        foreach (['/location', '/location/chaises', '/recherche?q=chaise', '/location/chaises/'.$product->getSlug()] as $url) {
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

    public function testProductCardLinksToProductPage(): void
    {
        $product = $this->product($this->category('Chaises', 1), 'Chaise Napoléon', [1 => 300]);

        $link = $this->client->request('GET', '/location/chaises')->filter('.product-card__name a');
        self::assertSame('/location/chaises/'.rawurlencode($product->getSlug()), $link->attr('href'));
        // La grille est dans un cadre Turbo : la fiche s'ouvre en page entière
        self::assertSame('_top', $link->attr('data-turbo-frame'));
    }

    public function testProductPageFollowsUxOrderWithTiersAndPracticalInfo(): void
    {
        $chaises = $this->category('Chaises', 1, type: PricingType::Chairs);
        $product = $this->product($chaises, 'Chaise Napoléon', [15 => 350, 50 => 300], description: 'Chaise en résine', photo: true);
        $product->setDimensions('H 92 cm');
        $product->setBreakagePrice(2500);
        $product->setLossPrice(3000);
        $product->setReturnInstructions('Empiler par 6.');
        $second = new ProductPhoto();
        $second->setFilename('dos.jpg');
        $second->setAlt('Chaise Napoléon de dos');
        $product->addPhoto($second);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/location/chaises/'.$product->getSlug());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Chaise Napoléon');
        self::assertSame(['15 à 49', '50 et plus'], $crawler->filter('.price-tiers tbody th')->each(fn (Crawler $c) => $c->text()));
        self::assertSame(["3,50\u{A0}€", "3,00\u{A0}€"], $crawler->filter('.price-tiers tbody td')->each(fn (Crawler $c) => $c->text()));
        self::assertSelectorTextSame('.product-panel__min', 'Minimum 15 chaises');
        self::assertCount(0, $crawler->filter('.product-panel__seats'));

        // Ordre UX-DR27 : galerie, nom, tarifs, minimum, dimensions/description, infos pratiques
        $html = (string) $this->client->getResponse()->getContent();
        $positions = array_map(fn (string $needle) => strpos($html, $needle), ['product-gallery__main', '<h1>', 'price-tiers', 'Minimum 15 chaises', '>Dimensions<', '>Description<', 'Infos pratiques']);
        self::assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);

        $info = $crawler->filter('.info-block dl > div')->each(fn (Crawler $row) => [$row->filter('dt')->text(), $row->filter('dd')->text()]);
        self::assertSame([
            ['Caution', 'Montant indiqué dans votre devis'],
            ['Casse ou perte', "Casse : 25,00\u{A0}€ · Perte : 30,00\u{A0}€ par unité"],
            ['Retrait et retour', 'Au dépôt de Bassens, sur un créneau choisi dans votre demande.'],
            ['Livraison', "1\u{A0}€ / km par la route depuis Bassens, dès 80 chaises, 70 km max"],
            ['Installation', 'Tarif sur demande'],
            ['Consignes', 'Empiler par 6.'],
        ], $info);

        // Contact
        self::assertSame('https://wa.me/33612345678?text='.rawurlencode("Bonjour, j'ai une question sur Chaise Napoléon"), $crawler->selectLink('Nous écrire sur WhatsApp')->attr('href'));
        self::assertSame('tel:+33612345678', $crawler->selectLink('Nous appeler')->attr('href'));

        // Images : seule la principale est chargée tout de suite, en srcset, dimensions réservées
        $main = $crawler->filter('.product-gallery__main img');
        self::assertSame(['800', '800', null], [$main->attr('width'), $main->attr('height'), $main->attr('loading')]);
        self::assertStringContainsString('400w', $main->attr('srcset'));
        self::assertSame(['lazy', 'lazy'], $crawler->filter('.product-gallery__thumbs img')->each(fn (Crawler $i) => $i->attr('loading')));
        self::assertCount(1, $crawler->filter('dialog.lightbox'));
    }

    public function testTablesWithChairsNote(): void
    {
        $tables = $this->category('Tables', 1, type: PricingType::Tables);
        $chaises = $this->category('Chaises', 2, type: PricingType::Chairs);
        $round = $this->product($tables, 'Table ronde', [1 => 1500]);
        $round->setSeats(8);
        $round->setPriceWithChairs(1200);
        $rectangular = $this->product($tables, 'Table rectangulaire', [1 => 2000]);
        $chair = $this->product($chaises, 'Chaise Napoléon', [15 => 250]);
        $this->em->flush();

        $this->client->request('GET', '/location/tables/'.$round->getSlug());
        self::assertSelectorTextSame('.product-panel__chairs.accent-sm', "Avec vos chaises Seydeco : 12,00\u{A0}€ la table au lieu de 15,00\u{A0}€ — dès que vous louez assez de chaises pour garnir toutes vos tables");

        $crawler = $this->client->request('GET', '/location/tables/'.$rectangular->getSlug());
        self::assertCount(0, $crawler->filter('.product-panel__chairs'));

        $this->client->request('GET', '/location/chaises/'.$chair->getSlug());
        self::assertSelectorTextSame('.product-panel__chairs', 'Bon à savoir : les tables rondes sont moins chères louées avec les chaises.');

        // Aucune table visible avec prix avec chaises : pas de rappel sur la fiche chaise
        $this->em->getRepository(Product::class)->find($round->getId())->setVisible(false);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/location/chaises/'.$chair->getSlug());
        self::assertCount(0, $crawler->filter('.product-panel__chairs'));
        $this->em->getRepository(Product::class)->find($round->getId())->setVisible(true);
        $this->em->flush();

        // Le prix « dès » des cartes reste le prix du produit seul
        $crawler = $this->client->request('GET', '/location/tables');
        $card = $crawler->filter('.product-card')->reduce(fn (Crawler $c) => str_contains($c->text(), 'Table ronde'));
        self::assertSame("dès 15,00\u{A0}€", $card->filter('.product-card__price')->text());
    }

    public function testProductPageUsesSettingsAndCategoryValues(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("UPDATE setting SET value = '15000' WHERE name = 'deposit_amount'");
        $db->executeStatement("UPDATE setting SET value = '' WHERE name = 'whatsapp_phone'");
        $tables = $this->category('Tables', 1, type: PricingType::Tables);
        $tables->setInstallPrice(500);
        $product = $this->product($tables, 'Table ronde', [1 => 1500]);
        $product->setSeats(8);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/location/tables/'.$product->getSlug());

        self::assertSelectorTextSame('.product-panel__seats', '8 places');
        self::assertCount(0, $crawler->filter('.product-panel__min'), 'pas de minimum affiché pour 1');
        self::assertSame(['1 et plus'], $crawler->filter('.price-tiers tbody th')->each(fn (Crawler $c) => $c->text()));
        $info = $crawler->filter('.info-block dd')->each(fn (Crawler $c) => $c->text());
        self::assertSame("150,00\u{A0}€", $info[0]);
        self::assertSame('Montant indiqué dans votre devis', $info[1]);
        self::assertSame("5,00\u{A0}€", $info[4]);
        // Numéro non renseigné : liens masqués
        self::assertCount(0, $crawler->filter('a[href^="https://wa.me/"], a[href^="tel:"]'));
    }

    public function testHiddenProductOrCategoryKeepsOldUrlAlive(): void
    {
        $chaises = $this->category('Chaises', 1);
        $hidden = $this->product($chaises, 'Chaise ancienne', [1 => 300], visible: false);
        $this->product($chaises, 'Chaise Napoléon', [1 => 300]);
        $archives = $this->category('Archives', 2, visible: false);
        $archived = $this->product($archives, 'Banc', [1 => 500]);

        $crawler = $this->client->request('GET', '/location/chaises/'.$hidden->getSlug());
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('main', "Ce produit n'est plus proposé");
        self::assertSame(['Chaise Napoléon'], $this->names($crawler));
        self::assertCount(0, $crawler->filter('.price-tiers'));

        $crawler = $this->client->request('GET', '/location/archives/'.$archived->getSlug());
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextContains('main', "Ce produit n'est plus proposé");
        self::assertSame([], $this->names($crawler));
        self::assertCount(0, $crawler->filter('.price-tiers'));
    }

    public function testWrongCategoryRedirectsAndUnknownProductIs404(): void
    {
        $chaises = $this->category('Chaises', 1);
        $this->category('Tables', 2);
        $product = $this->product($chaises, 'Chaise Napoléon', [1 => 300]);

        $this->client->request('GET', '/location/tables/'.$product->getSlug());
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/location/chaises/'.rawurlencode($product->getSlug()));

        $this->client->request('GET', '/location/inconnue/'.$product->getSlug());
        self::assertResponseStatusCodeSame(301);

        $this->client->request('GET', '/location/chaises/inconnu');
        self::assertResponseStatusCodeSame(404);
    }

    public function testColorSwatches(): void
    {
        $product = $this->product($this->category('Chaises', 1), 'Chaise Napoléon', [1 => 300], colors: ['Blanc', 'Or'], photo: true);
        [$white, $gold] = $product->getColors()->getValues();
        $gold->setHex('#C9A13B');
        $photo = new ProductPhoto();
        $photo->setFilename('or.jpg');
        $photo->setAlt('Chaise Napoléon dorée');
        $photo->setColor($gold);
        $product->addPhoto($photo);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/location/chaises/'.$product->getSlug());

        $swatches = $crawler->filter('.color-picker button.swatch');
        self::assertSame(['true', 'false'], $swatches->each(fn (Crawler $b) => $b->attr('aria-pressed')));
        self::assertSame(['Blanc', 'Or'], $swatches->each(fn (Crawler $b) => $b->text()));
        self::assertSame((string) $gold->getId(), $swatches->eq(1)->attr('data-color-id'));
        self::assertSelectorTextSame('[data-color-picker-target="name"]', 'Blanc');
        // Photos rattachées à leur coloris pour le filtre de la galerie
        self::assertSame(['', (string) $gold->getId()], $crawler->filter('[data-gallery-target="item"]')->each(fn (Crawler $li) => $li->attr('data-color')));
        self::assertNotSame((string) $white->getId(), $crawler->filter('[data-gallery-target="item"]')->eq(1)->attr('data-color'));
    }

    public function testVideoFacadesLoadNoThirdPartyBeforeClick(): void
    {
        $chaises = $this->category('Chaises', 1);
        $youtube = $this->product($chaises, 'Chaise YouTube', [1 => 300], photo: true);
        $youtube->setVideoUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $instagram = $this->product($chaises, 'Chaise Instagram', [1 => 300]);
        $instagram->setVideoUrl('https://www.instagram.com/reel/abc123/');
        $file = $this->product($chaises, 'Chaise fichier', [1 => 300]);
        $file->setVideoFile('video.mp4');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/location/chaises/'.$youtube->getSlug());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertCount(0, $crawler->filter('iframe, script[src*="youtube"]'));
        self::assertStringNotContainsString('youtube-nocookie.com', $html, 'lecteur chargé seulement au clic (JS)');
        $facade = $crawler->filter('.video-facade');
        self::assertSame('dQw4w9WgXcQ', $facade->attr('data-video-facade-id-value'));
        self::assertSame('video-facade#play', $facade->attr('data-action'));
        self::assertStringContainsString('Vidéo', $facade->text());
        self::assertSelectorTextSame('.product-video__note', 'La vidéo est fournie par YouTube');

        $crawler = $this->client->request('GET', '/location/chaises/'.$instagram->getSlug());
        $facade = $crawler->filter('.video-facade');
        self::assertSame(['https://www.instagram.com/reel/abc123/', '_blank', null], [$facade->attr('href'), $facade->attr('target'), $facade->attr('data-controller')]);
        self::assertCount(0, $crawler->filter('iframe, script[src*="instagram"]'));

        // Fichier absent du disque : aucun lecteur
        $crawler = $this->client->request('GET', '/location/chaises/'.$file->getSlug());
        self::assertCount(0, $crawler->filter('video'));

        $path = static::getContainer()->getParameter('app.upload_dir').'/video.mp4';
        $fs = new \Symfony\Component\Filesystem\Filesystem();
        $fs->dumpFile($path, 'mp4');
        try {
            $crawler = $this->client->request('GET', '/location/chaises/'.$file->getSlug());
            $video = $crawler->filter('video');
            self::assertSame('none', $video->attr('preload'));
            $this->client->request('GET', $video->attr('src'));
            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'video/mp4');

            // Autre catégorie dans l'URL de la vidéo : 404
            $this->client->request('GET', str_replace('/chaises/', '/tables/', $video->attr('src')));
            self::assertResponseStatusCodeSame(404);

            static::getContainer()->get(Connection::class)->executeStatement('UPDATE product SET visible = 0 WHERE id = ?', [$file->getId()]);
            $this->client->request('GET', $video->attr('src'));
            self::assertResponseStatusCodeSame(404);
        } finally {
            $fs->remove($path);
        }
    }

    public function testPracticalInfoHidesEmptyOrInvalidSettings(): void
    {
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement("UPDATE setting SET value = '150 €' WHERE name = 'deposit_amount'");
        $db->executeStatement("UPDATE setting SET value = '' WHERE name IN ('delivery_min_chairs', 'depot_city')");
        try {
            $product = $this->product($this->category('Chaises', 1), 'Chaise Napoléon', [1 => 300]);
            $crawler = $this->client->request('GET', '/location/chaises/'.$product->getSlug());
            $info = $crawler->filter('.info-block dl > div')->each(fn (Crawler $row) => [$row->filter('dt')->text(), $row->filter('dd')->text()]);
            self::assertSame(['Caution', 'Montant indiqué dans votre devis'], $info[0]);
            self::assertSame(['Retrait et retour', 'Sur un créneau choisi dans votre demande.'], $info[2]);
            self::assertSame(['Livraison', "1\u{A0}€ / km par la route, 70 km max"], $info[3]);

            $db->executeStatement("UPDATE setting SET value = '' WHERE name IN ('delivery_price_per_km', 'delivery_max_km')");
            $crawler = $this->client->request('GET', '/location/chaises/'.$product->getSlug());
            self::assertNotContains('Livraison', $crawler->filter('.info-block dt')->each(fn (Crawler $dt) => $dt->text()));
        } finally {
            $db->executeStatement("UPDATE setting SET value = CASE name WHEN 'delivery_price_per_km' THEN '100' WHEN 'delivery_min_chairs' THEN '80' WHEN 'delivery_max_km' THEN '70' ELSE 'Bassens' END WHERE name IN ('delivery_price_per_km', 'delivery_min_chairs', 'delivery_max_km', 'depot_city')");
        }
    }

    /** @return list<string> */
    private function names(Crawler $crawler): array
    {
        return $crawler->filter('.product-card__name')->each(fn (Crawler $n) => $n->text());
    }

    private function category(string $name, int $position, bool $visible = true, PricingType $type = PricingType::Other): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(mb_strtolower($name));
        $category->setPosition($position);
        $category->setPricingType($type);
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
