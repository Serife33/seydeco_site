<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\HomeHero;
use App\Entity\PriceTier;
use App\Entity\Product;
use App\Entity\ProductPhoto;
use App\Entity\Realisation;
use App\Entity\RealisationPhoto;
use App\Entity\Review;
use App\Tests\Functional\Admin\CatalogTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Story 2.10 (et rendu public du hero, story 2.9) : page d'accueil complète.
 * Navigateur (vérification manuelle, 390 et 1280 px) : rythme blanc / crème, 4 colonnes / 2 × 2 des engagements,
 * hero une et deux photos (empilées sur mobile), bande sombre, encadré L'entreprise.
 */
final class HomeTest extends CatalogTestCase
{
    private Connection $db;
    /** @var array<string, string> */
    private array $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = static::getContainer()->get(Connection::class);
        $this->settings = $this->db->fetchAllKeyValue('SELECT name, value FROM setting');
        SiteLayoutTest::resetSettings($this->db);
        $this->setting('whatsapp_phone', '06 12 34 56 78');
    }

    protected function tearDown(): void
    {
        foreach ($this->settings as $name => $value) {
            $this->setting($name, $value);
        }
        parent::tearDown();
    }

    private function setting(string $name, string $value): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
    }

    private function product(string $name, Category $category, bool $featured = true, int $position = 0, bool $visible = true): Product
    {
        $em = $this->em();
        $product = new Product();
        $product->setName($name);
        $product->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
        $product->setCategory($em->find(Category::class, $category->getId()));
        $product->addPriceTier(new PriceTier(1, 300));
        $photo = new ProductPhoto();
        $photo->setFilename('photo.jpg');
        $photo->setAlt($name);
        $product->addPhoto($photo);
        $product->setFeatured($featured);
        $product->setFeaturedPosition($position);
        $product->setVisible($visible);
        $em->persist($product);
        $em->flush();

        return $product;
    }

    private function realisation(string $title, int $position, bool $visible = true): void
    {
        $realisation = new Realisation();
        $realisation->setTitle($title);
        $realisation->initSlug((string) static::getContainer()->get('slugger')->slug($title)->lower());
        $realisation->setEventDate(new \DateTimeImmutable('2026-06-13'));
        $realisation->setVenueName('Domaine des Tilleuls');
        $realisation->setCity('Libourne');
        $realisation->setPosition($position);
        $realisation->setVisible($visible);
        $photo = new RealisationPhoto();
        $photo->setFilename('photo.jpg');
        $photo->setAlt('Table dressée');
        $realisation->addPhoto($photo);
        $em = $this->em();
        $em->persist($realisation);
        $em->flush();
    }

    /** @return list<string> titres h2 des sections de l'accueil, dans l'ordre de la page */
    private function sections(Crawler $crawler): array
    {
        return $crawler->filter('main h2')->each(fn (Crawler $h) => $h->text());
    }

    public function testFullHomeInFixedOrder(): void
    {
        $chairs = $this->createCategory('Chaises', 1);
        $this->product('Chaise Napoléon', $chairs);
        $this->realisation('Mariage de Léa', 1);
        $review = new Review();
        $review->setAuthor('Inès');
        $review->setRating(5);
        $review->setText('Matériel parfait.');
        $review->setReviewedOn(new \DateTimeImmutable('2026-06-20'));
        $em = $this->em();
        $em->persist($review);
        $em->flush();
        $this->db->executeStatement("INSERT INTO announcement (text, starts_on, ends_on, position, visible) VALUES ('Nouveau', CURDATE(), CURDATE(), 0, 1)");

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSame('Aller au contenu', $crawler->filter('a')->first()->text());
        self::assertStringNotContainsString('Site en préparation', $crawler->text());
        // Bandeau → en-tête → contenu → pied
        self::assertCount(1, $crawler->filter('body > .announcement-bar + header.site-header + main#contenu + footer.site-footer'));
        // Un seul titre h1 : celui du hero (textes par défaut tant que rien n'est publié)
        self::assertSame(['Location de matériel de réception'], $crawler->filter('h1')->each(fn (Crawler $h) => $h->text()));
        self::assertSame('Voir le catalogue', $crawler->filter('.hero .button-primary')->text());
        self::assertSame('/location', $crawler->filter('.hero .button-primary')->attr('href'));
        self::assertSame(
            ['Le catalogue', 'Les incontournables', 'Nos engagements', 'Nos réalisations', 'Comment ça marche', 'Ce que disent nos clientes', 'Votre événement approche ?', "L'entreprise"],
            $this->sections($crawler),
        );
        self::assertSame('/location/chaises', $crawler->filter('.category-tile a')->attr('href'));
        self::assertCount(1, $crawler->filter('.home-section--cream .product-card'));
        self::assertCount(1, $crawler->filter('.how-it-works'));
        self::assertCount(1, $crawler->filter('.reviews .review'));
        // Une seule bande sombre ; aucun carrousel automatique ni ordre de tabulation imposé
        self::assertCount(1, $crawler->filter('.dark-band'));
        self::assertCount(0, $crawler->filter('[tabindex]:not([tabindex="-1"])'));
        // SEO de l'accueil (story 2.2) : titre, description, Open Graph, LocalBusiness, + un Review par avis affiché
        self::assertSelectorTextSame('title', 'Location de matériel de réception pour vos événements | Seydeco');
        self::assertSame('http://localhost/', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertNotEmpty($crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('website', $crawler->filter('meta[property="og:type"]')->attr('content'));
        $types = $crawler->filter('script[type="application/ld+json"]')->each(fn (Crawler $s) => json_decode($s->text(), true, flags: \JSON_THROW_ON_ERROR)['@type']);
        self::assertSame(['LocalBusiness', 'Review'], $types);
    }

    public function testSectionsWithoutContentAreOmitted(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSame(['Nos engagements', 'Comment ça marche', 'Votre événement approche ?', "L'entreprise"], $this->sections($crawler));
        self::assertCount(0, $crawler->filter('.hero__photos, .reviews, .category-tiles, .product-grid'));
    }

    public function testFeaturedProductsInSerifeOrderWithoutHiddenOnes(): void
    {
        $chairs = $this->createCategory('Chaises', 1);
        $hidden = $this->createCategory('Archives', 2);
        $em = $this->em();
        $em->find(Category::class, $hidden->getId())->setVisible(false);
        $em->flush();
        $this->product('Table ronde', $chairs, position: 2);
        $this->product('Chaise Napoléon', $chairs, position: 1);
        $this->product('Nappe masquée', $chairs, position: 0, visible: false);
        $this->product('Vieille chaise', $hidden, position: 0);
        $this->product('Fauteuil', $chairs, featured: false);

        $crawler = $this->client->request('GET', '/');

        self::assertSame(['Chaise Napoléon', 'Table ronde'], $crawler->filter('.home-section--cream .product-card__name')->each(fn (Crawler $n) => $n->text()));
        // Carte produit du catalogue : prix « dès », bouton du devis (ici WhatsApp, devis en ligne éteint), photo différée
        self::assertStringContainsString('dès 3,00', $crawler->filter('.home-section--cream .product-card__price')->text());
        self::assertCount(2, $crawler->filter('.home-section--cream a[data-role="whatsapp"]'));
        self::assertSame('lazy', $crawler->filter('.home-section--cream img')->attr('loading'));
    }

    public function testCommitmentsFromSettingsAndHonestShowcase(): void
    {
        $this->setting('founded_year', '2026');
        $this->setting('delivery_max_km', '70');
        $this->setting('depot_city', 'Bassens');

        $crawler = $this->client->request('GET', '/');

        $titles = fn (Crawler $c) => $c->filter('.commitments__list h3')->each(fn (Crawler $h) => $h->text());
        self::assertSame(['Depuis 2026', 'Toujours joignable', "Livraison jusqu'à 70 km", 'Réponse rapide sur WhatsApp'], $titles($crawler));
        self::assertStringContainsString('Autour de Bassens', $crawler->filter('.commitments')->text());
        // Non cliquables, icônes trait 32 px décoratives
        self::assertCount(0, $crawler->filter('.commitments a'));
        self::assertCount(4, $crawler->filter('.commitments svg[width="32"][aria-hidden="true"]'));
        self::assertStringNotContainsString('devis tout de suite', $crawler->filter('.dark-band')->text());

        $this->setting('quote_online', '1');
        $this->setting('delivery_max_km', '50');
        $crawler = $this->client->request('GET', '/');
        self::assertSame(['Depuis 2026', 'Toujours joignable', "Livraison jusqu'à 50 km", 'Devis immédiat en ligne'], $titles($crawler));
    }

    public function testFirstThreeVisibleRealisationsWithAccentLink(): void
    {
        $this->realisation('Quatrième', 4);
        $this->realisation('Masquée', 0, visible: false);
        $this->realisation('Deuxième', 2);
        $this->realisation('Première', 1);
        $this->realisation('Troisième', 3);

        $crawler = $this->client->request('GET', '/');

        self::assertSame(['Première', 'Deuxième', 'Troisième'], $crawler->filter('.realisation-card .product-card__name')->each(fn (Crawler $n) => $n->text()));
        self::assertSame('/realisations', $crawler->selectLink('Voir nos réalisations')->attr('href'));
    }

    public function testDarkBandAndCompanyBox(): void
    {
        $this->setting('company_text', "Seydeco loue du **beau** matériel.\n\n<script>alert(1)</script>");
        $this->setting('instagram_url', 'https://www.instagram.com/seydeco');
        $this->setting('phone', '05 56 00 00 00');

        $crawler = $this->client->request('GET', '/');

        self::assertSame('/location', $crawler->filter('.dark-band a.button-inverse')->attr('href'));
        $box = $crawler->filter('.company-box');
        self::assertSame("L'entreprise", $box->filter('h2')->text());
        self::assertSame('beau', $box->filter('.rich-text strong')->text());
        self::assertStringNotContainsString('<script>alert', (string) $this->client->getResponse()->getContent());
        self::assertSame(['Instagram (nouvel onglet)'], $box->filter('.company-box__social a')->each(fn (Crawler $a) => $a->text()));
        self::assertSame(['https://wa.me/33612345678?text='.rawurlencode("Bonjour, j'ai une question sur Seydeco"), 'tel:+33556000000'], $box->filter('.contact-buttons a')->each(fn (Crawler $a) => $a->attr('href')));
    }

    public function testPublishedHeroWithTwoPhotosAndPriorityLoading(): void
    {
        $em = $this->em();
        $hero = new HomeHero();
        $hero->setTitle('Mariages d\'été');
        $hero->setPhoto('a.jpg');
        $hero->setPhotoAlt('Table d\'honneur');
        $hero->setPhoto2('b.jpg');
        $hero->setPhoto2Alt('Chaises blanches');
        $em->persist($hero);
        // Un brouillon n'est jamais montré au public
        $draft = HomeHero::draftOf($hero);
        $draft->setTitle('Brouillon secret');
        $em->persist($draft);
        $em->flush();

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextSame('h1', 'Mariages d\'été');
        self::assertStringNotContainsString('Brouillon secret', (string) $this->client->getResponse()->getContent());
        $images = $crawler->filter('.hero__photos--two img');
        self::assertSame(['Table d\'honneur', 'Chaises blanches'], $images->each(fn (Crawler $i) => $i->attr('alt')));
        self::assertSame(['high', null], $images->each(fn (Crawler $i) => $i->attr('fetchpriority')));
        self::assertSame(['1200', '900', null], [$images->attr('width'), $images->attr('height'), $images->attr('loading')]);
        self::assertStringContainsString('hero_half_600', $images->attr('srcset'));
        self::assertStringContainsString('page_photo', $crawler->filter('meta[property="og:image"]')->attr('content'));

        $em = $this->em();
        $em->find(HomeHero::class, $hero->getId())->setPhoto2(null);
        $em->flush();
        $crawler = $this->client->request('GET', '/');
        self::assertSame(['1600', 'high'], [$crawler->filter('.hero__photos img')->attr('width'), $crawler->filter('.hero__photos img')->attr('fetchpriority')]);
    }
}
