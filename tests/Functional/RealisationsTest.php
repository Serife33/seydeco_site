<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\EventType;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\Realisation;
use App\Entity\RealisationPhoto;
use App\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Stories 2.5 (réalisations) et 2.6 (lieux partenaires) côté public.
 * Navigateur (vérification manuelle, 390 et 1280 px) : galerie plein écran (ouverture, flèches, glissement, Échap,
 * retour du focus), Façade vidéo YouTube, lecteur MP4, cartes 4:5 sur mobile et 380 px de haut sur desktop.
 */
final class RealisationsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $db = static::getContainer()->get(Connection::class);
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['realisation_product', 'realisation_photo', 'realisation', 'venue', 'event_type', 'price_tier', 'product_photo', 'product', 'category'] as $table) {
            $db->executeStatement("TRUNCATE TABLE $table");
        }
        $db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testListShowsTwelveVisibleCardsInOrderThenSeeMore(): void
    {
        for ($i = 13; $i >= 1; --$i) {
            $this->realisation("Réalisation $i", position: $i);
        }
        $this->realisation('Réalisation masquée', position: 0, visible: false);

        $crawler = $this->client->request('GET', '/realisations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Nos réalisations');
        $cards = $crawler->filter('.realisation-card');
        self::assertCount(12, $cards);
        self::assertSame(['Réalisation 1', 'Réalisation 2'], \array_slice($cards->filter('.product-card__name')->each(fn (Crawler $n) => $n->text()), 0, 2));
        $first = $cards->first();
        self::assertSame(['h2', '/realisations/realisation-1'], [$first->filter('.product-card__name')->nodeName(), $first->filter('a')->attr('href')]);
        self::assertSame('Domaine des Tilleuls, Libourne', $first->filter('.product-card__price')->text());
        self::assertSame(['Photo de Réalisation 1', '480', '600'], [$first->filter('img')->attr('alt'), $first->filter('img')->attr('width'), $first->filter('img')->attr('height')]);
        self::assertStringNotContainsString('Réalisation masquée', $crawler->filter('main')->text());
        // « Voir plus » : simple lien, sans JavaScript ; la page 2 montre les 13
        self::assertSame('/realisations?page=2', $crawler->selectLink('Voir plus')->attr('href'));
        $crawler = $this->client->request('GET', '/realisations?page=2');
        self::assertCount(13, $crawler->filter('.realisation-card'));
        self::assertCount(0, $crawler->selectLink('Voir plus'));
        // Sans lieu visible : pas de liste « Nos lieux partenaires »
        self::assertSelectorNotExists('.venues');
        // Navigation : Réalisations en 2e position, rubrique en cours
        $nav = $crawler->filter('header .site-nav a');
        self::assertSame(['Réalisations', 'page'], [$nav->eq(1)->text(), $nav->eq(1)->attr('aria-current')]);
    }

    public function testEmptyList(): void
    {
        $this->client->request('GET', '/realisations');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Nos réalisations arrivent bientôt.');
    }

    public function testRealisationPage(): void
    {
        $category = $this->category('Chaises');
        $chaise = $this->product($category, 'Chaise Napoléon');
        $masque = $this->product($category, 'Chaise masquée', visible: false);
        $venue = $this->venue('Château de la Rivière');
        $realisation = $this->realisation('Mariage de Léa', photos: 2, venue: $venue, products: [$chaise, $masque]);
        $realisation->setEventType(new EventType('Mariage'));
        $realisation->setText('Une table d\'honneur blanche et or.');
        $this->em->persist($realisation->getEventType());
        $this->em->flush();

        $crawler = $this->client->request('GET', '/realisations/mariage-de-lea');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Mariage de Léa');
        self::assertSame('Château de la Rivière, Fronsac · Mariage · 13/06/2026', $crawler->filter('.realisation-page__meta')->text());
        self::assertSame('/lieux/chateau-de-la-riviere', $crawler->filter('.realisation-page__meta a')->attr('href'));
        self::assertSelectorTextContains('.realisation-page__text', 'Une table d\'honneur blanche et or.');
        self::assertSame('Mariage de Léa — Château de la Rivière, Fronsac | Seydeco', $crawler->filter('title')->text());
        self::assertSame("Une table d'honneur blanche et or.", $crawler->filter('meta[name="description"]')->attr('content'));

        // Photos pleines : chacune ouvre la galerie plein écran et a son « Épingler »
        $photos = $crawler->filter('[data-controller="gallery"] .realisation-photos > li[data-gallery-target="item"]');
        self::assertCount(2, $photos);
        self::assertCount(1, $crawler->filter('dialog.lightbox'));
        foreach ($photos as $i => $node) {
            $photo = new Crawler($node);
            self::assertSame('gallery#open', $photo->filter('a')->first()->attr('data-action'));
            self::assertMatchesRegularExpression('~/media/cache/(resolve/)?product_full/~', $photo->attr('data-full'));
            $pin = $photo->filter('a.pin-link');
            self::assertSame(['_blank', 'noopener'], [$pin->attr('target'), $pin->attr('rel')]);
            parse_str((string) parse_url($pin->attr('href'), \PHP_URL_QUERY), $query);
            self::assertSame(['url' => 'http://localhost/realisations/mariage-de-lea', 'media' => $photo->filter('img')->attr('src'), 'description' => 'Photo '.($i + 1).' de Mariage de Léa'], $query);
        }
        self::assertSame('high', $photos->first()->filter('img')->attr('fetchpriority'));
        self::assertSame('lazy', $photos->last()->filter('img')->attr('loading'));

        // Produits utilisés en Cartes produit, produit masqué absent
        self::assertSame(['Chaise Napoléon'], $crawler->filter('.related .product-card__name')->each(fn (Crawler $n) => $n->text()));
        self::assertStringNotContainsString('Chaise masquée', (string) $this->client->getResponse()->getContent());

        // JSON-LD Event valide
        $ld = $this->jsonLd($crawler);
        self::assertSame(['LocalBusiness', 'Event'], array_column($ld, '@type'));
        self::assertSame('Mariage de Léa', $ld[1]['name']);
        self::assertSame('2026-06-13', $ld[1]['startDate']);
        self::assertSame(['@type' => 'Place', 'name' => 'Château de la Rivière', 'address' => ['@type' => 'PostalAddress', 'addressLocality' => 'Fronsac']], $ld[1]['location']);
        self::assertCount(2, $ld[1]['image']);
        self::assertSame($crawler->filter('meta[property="og:image"]')->attr('content'), $ld[1]['image'][0]);
    }

    public function testHiddenVenueNameWithoutLinkAndYoutubeFacade(): void
    {
        $realisation = $this->realisation('Anniversaire', photos: 0, venue: $this->venue('Salle des fêtes', visible: false));
        $realisation->setVideoUrl('https://youtu.be/dQw4w9WgXcQ');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/realisations/anniversaire');

        self::assertSame('Salle des fêtes, Fronsac · 13/06/2026', $crawler->filter('.realisation-page__meta')->text());
        self::assertCount(0, $crawler->filter('.realisation-page__meta a'));
        // Façade : aucun iframe avant le clic
        $facade = $crawler->filter('a.video-facade');
        self::assertSame(['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'], [$facade->attr('href'), $facade->attr('data-video-facade-id-value')]);
        self::assertCount(0, $crawler->filter('iframe'));
        // Sans photo : pas d'image de partage, JSON-LD toujours valide
        self::assertCount(0, $crawler->filter('meta[property="og:image"]'));
        self::assertArrayNotHasKey('image', $this->jsonLd($crawler)[1]);
    }

    public function testHiddenRealisationAndVenueAndUnknownSlugs(): void
    {
        $this->realisation('Mariage ancien', visible: false);
        $this->venue('Ancienne salle', visible: false);

        foreach (['/realisations/mariage-ancien' => "Cette réalisation n'est plus en ligne.", '/lieux/ancienne-salle' => "Ce lieu n'est plus présenté."] as $url => $message) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(200);
            self::assertSelectorTextContains('main', $message);
            self::assertSame('/realisations', $crawler->filter('main a')->last()->attr('href'));
            self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
            self::assertSame(['LocalBusiness'], array_column($this->jsonLd($crawler), '@type'));
        }
        foreach (['/realisations/inconnu', '/lieux/inconnu', '/realisations/mariage-ancien/video'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
        }
    }

    public function testVenuePageAndPartnerList(): void
    {
        $venue = $this->venue('Château de la Rivière', website: 'https://www.chateau-riviere.fr');
        $this->venue('Salle masquée', visible: false);
        $this->realisation('Mariage de Léa', position: 2, venue: $venue);
        $this->realisation('Baptême', position: 1, venue: $venue);
        $this->realisation('Réalisation masquée', venue: $venue, visible: false);
        $this->realisation('Ailleurs');

        $crawler = $this->client->request('GET', '/realisations');
        self::assertSelectorTextSame('.venues h2', 'Nos lieux partenaires');
        self::assertSame([['Château de la Rivière', '/lieux/chateau-de-la-riviere']], $crawler->filter('.venue-list a')->each(fn (Crawler $a) => [$a->text(), $a->attr('href')]));

        $crawler = $this->client->request('GET', '/lieux/chateau-de-la-riviere');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Château de la Rivière');
        self::assertSelectorTextContains('.realisation-page__meta', 'Fronsac');
        self::assertSelectorTextContains('main', 'Un lieu magnifique.');
        self::assertSame('Façade de Château de la Rivière', $crawler->filter('img.content-page__photo')->attr('alt'));
        self::assertSame('https://www.chateau-riviere.fr', $crawler->filter('main a[target="_blank"]')->attr('href'));
        self::assertSame(['Baptême', 'Mariage de Léa'], $crawler->filter('.realisation-card .product-card__name')->each(fn (Crawler $n) => $n->text()));
        // Titre, description et Open Graph propres
        self::assertSame('Château de la Rivière, Fronsac — lieu partenaire | Seydeco', $crawler->filter('title')->text());
        self::assertSame('Un lieu magnifique.', $crawler->filter('meta[property="og:description"]')->attr('content'));
        self::assertMatchesRegularExpression('~^http://localhost/media/cache/(resolve/)?page_photo/~', $crawler->filter('meta[property="og:image"]')->attr('content'));
    }

    /** @return list<array<string, mixed>> chaque JSON-LD de la page, décodé (JSON invalide = échec) */
    private function jsonLd(Crawler $crawler): array
    {
        return $crawler->filter('script[type="application/ld+json"]')->each(fn (Crawler $s) => json_decode($s->text(), true, flags: \JSON_THROW_ON_ERROR));
    }

    /** @param list<Product> $products */
    private function realisation(string $title, int $position = 0, bool $visible = true, int $photos = 1, ?Venue $venue = null, array $products = []): Realisation
    {
        $realisation = new Realisation();
        $realisation->setTitle($title);
        $realisation->initSlug((string) static::getContainer()->get('slugger')->slug($title)->lower());
        $realisation->setEventDate(new \DateTimeImmutable('2026-06-13'));
        $realisation->setVenueName('Domaine des Tilleuls');
        $realisation->setCity('Libourne');
        $realisation->setVenue($venue);
        $realisation->applyVenue();
        $realisation->setPosition($position);
        $realisation->setVisible($visible);
        for ($i = 1; $i <= $photos; ++$i) {
            $photo = new RealisationPhoto();
            $photo->setFilename("photo-$i.jpg");
            $photo->setAlt(1 === $photos ? "Photo de $title" : "Photo $i de $title");
            $photo->setPosition($i);
            $realisation->addPhoto($photo);
        }
        array_map($realisation->addProduct(...), $products);
        $this->em->persist($realisation);
        $this->em->flush();

        return $realisation;
    }

    private function venue(string $name, bool $visible = true, ?string $website = null): Venue
    {
        $venue = new Venue();
        $venue->setName($name);
        $venue->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
        $venue->setCity('Fronsac');
        $venue->setText('Un lieu magnifique.');
        $venue->setPhoto('lieu.jpg');
        $venue->setPhotoAlt("Façade de $name");
        $venue->setWebsite($website);
        $venue->setVisible($visible);
        $this->em->persist($venue);
        $this->em->flush();

        return $venue;
    }

    private function category(string $name): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(mb_strtolower($name));
        $category->setPricingType(PricingType::Chairs);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function product(Category $category, string $name, bool $visible = true): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
        $product->setCategory($category);
        $product->setVisible($visible);
        $product->addPriceTier(new PriceTier(1, 300));
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
