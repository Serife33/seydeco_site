<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\Pack;
use App\Entity\PackItem;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\ProductPhoto;
use App\Entity\Realisation;
use App\Entity\Venue;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Stories 2.1 (en-tête, navigation, contacts, pied de page) et 2.2 (titre, description, Open Graph, JSON-LD, indexation).
 * Navigateur (vérification manuelle, 390 et 1280 px) : disposition des trois en-têtes, menu mobile (focus piégé, Échap,
 * retour du focus sur « Menu »), en-tête masqué en descendant et réaffiché en remontant sur mobile, non collant sur desktop.
 */
final class SiteLayoutTest extends WebTestCase
{
    public const array CONTACT_KEYS = ['phone', 'email', 'address', 'siret', 'instagram_url', 'tiktok_url', 'facebook_url', 'youtube_url', 'pinterest_url', 'google_reviews_url'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['realisation_product', 'realisation_photo', 'realisation', 'venue', 'related_product', 'pack_item', 'pack', 'price_tier', 'product_photo', 'product_color', 'product', 'category'] as $table) {
            $this->db->executeStatement("TRUNCATE TABLE $table");
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        self::resetSettings($this->db);
        $this->setting('whatsapp_phone', '06 12 34 56 78');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        self::resetSettings($this->db);
        parent::tearDown();
    }

    /** Base partagée : les autres tests attendent des coordonnées vides, un site fermé et le devis en ligne éteint. */
    public static function resetSettings(Connection $db): void
    {
        $db->executeStatement("UPDATE setting SET value = '' WHERE name IN ('".implode("', '", self::CONTACT_KEYS)."')");
        $db->executeStatement("UPDATE setting SET value = '0' WHERE name IN ('search_engines_open', 'quote_online')");
    }

    // --- Story 2.1 ---

    public function testNavigationShowsOnlyExistingPagesWithActiveLink(): void
    {
        $this->category('Chaises');

        $crawler = $this->client->request('GET', '/location/chaises');

        foreach (['header .site-nav', '#menu-mobile nav'] as $nav) {
            $links = $crawler->filter("$nav a");
            // Ordre UX-DR11
            self::assertSame(['Catalogue', 'Réalisations', 'Comment ça marche', 'À propos', 'Contact'], $links->each(fn (Crawler $a) => $a->text()), "Aucun lien mort ($nav)");
            self::assertSame(['/location', '/realisations', '/comment-ca-marche', '/a-propos', '/contact'], $links->each(fn (Crawler $a) => $a->attr('href')));
            self::assertSame('true', $links->attr('aria-current'), 'Rubrique du catalogue en cours');
            self::assertCount(1, $crawler->filter("$nav a[aria-current]"));
        }
        $crawler = $this->client->request('GET', '/location');
        self::assertSame('page', $crawler->filter('header .site-nav a')->attr('aria-current'));
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('header .site-nav a[aria-current]'));
        // Accueil : trois zones (contacts avant la marque), navigation sous la ligne ; pages internes : sur une ligne
        self::assertCount(1, $crawler->filter('header.site-header--home'));
        self::assertCount(1, $crawler->filter('.site-header__inner > .site-header__contacts + .brand'));
        self::assertCount(1, $crawler->filter('.site-header__inner > .site-header__tools + .site-nav'));
        self::assertSame('S', $crawler->filter('.brand .monogram')->text());
        // Menu mobile : fermé, relié au bouton « Menu »
        self::assertSame(['menu-mobile', 'false'], [$crawler->filter('.site-header__menu')->attr('aria-controls'), $crawler->filter('.site-header__menu')->attr('aria-expanded')]);
        self::assertNotNull($crawler->filter('#menu-mobile')->attr('hidden'));
    }

    public function testContactButtonsInHeaderMenuAndFooterWithPageMessage(): void
    {
        $this->setting('phone', '05 56 00 00 00');
        $product = $this->product('Chaise Napoléon', photo: false);

        foreach (['/' => "Bonjour, j'ai une question sur Seydeco", '/location' => "Bonjour, j'ai une question sur Seydeco", $this->url($product) => "Bonjour, j'ai une question sur Chaise Napoléon"] as $url => $message) {
            $crawler = $this->client->request('GET', $url);
            foreach (['header .site-header__contacts', '#menu-mobile', 'footer'] as $zone) {
                $buttons = $crawler->filter("$zone .contact-buttons a");
                self::assertSame(['https://wa.me/33612345678?text='.rawurlencode($message), 'tel:+33556000000'], $buttons->each(fn (Crawler $a) => $a->attr('href')), "$zone sur $url");
                self::assertSame('WhatsApp (nouvel onglet)', $buttons->first()->text());
                self::assertSame('Appeler le 05 56 00 00 00', $buttons->last()->text());
                self::assertCount(2, $buttons->filter('svg[aria-hidden="true"]'));
            }
        }
        // Jamais de bulle flottante : aucun bouton contact hors en-tête, menu et pied (et le lien texte de la fiche)
        self::assertCount(6, $crawler->filter('.contact-button'));
    }

    public function testPhoneFallsBackToWhatsappAndNoButtonWithoutNumber(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSame('tel:+33612345678', $crawler->filter('footer a[href^="tel:"]')->attr('href'));

        $this->setting('whatsapp_phone', '');
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('a[href^="https://wa.me/"], a[href^="tel:"], .contact-buttons'));
    }

    public function testFooterShowsFilledDetailsAndNetworksOnly(): void
    {
        $this->setting('address', "12 rue des Fleurs\n33000 Bordeaux");
        $this->setting('siret', '123 456 789 00012');
        $this->setting('instagram_url', 'https://www.instagram.com/seydeco');
        $this->setting('facebook_url', 'https://facebook.com/seydeco');

        $crawler = $this->client->request('GET', '/location');

        $footer = $crawler->filter('footer.site-footer');
        self::assertSame('Seydeco', $footer->filter('.wordmark--compact')->text());
        self::assertStringContainsString('12 rue des Fleurs', $footer->filter('address')->text());
        self::assertStringContainsString('33000 Bordeaux', $footer->filter('address')->text());
        self::assertStringContainsString('SIRET 123 456 789 00012', $footer->filter('.site-footer__meta')->text());
        // Lien vide (TikTok, YouTube, Pinterest) : affiché nulle part
        $social = $footer->filter('.site-footer__social a');
        self::assertSame(['Instagram (nouvel onglet)', 'Facebook (nouvel onglet)'], $social->each(fn (Crawler $a) => $a->text()));
        self::assertSame(['https://www.instagram.com/seydeco', 'https://facebook.com/seydeco'], $social->each(fn (Crawler $a) => $a->attr('href')));
        self::assertSame(['_blank', 'noopener'], [$social->attr('target'), $social->attr('rel')]);
        $html = (string) $this->client->getResponse()->getContent();
        foreach (['youtube', 'tiktok', 'pinterest'] as $network) {
            self::assertStringNotContainsString($network, mb_strtolower($html), "$network vide affiché");
        }
        // Aucun script tiers (réseaux, Pinterest, Google) : seuls les scripts du site
        foreach ($crawler->filter('script[src]')->each(fn (Crawler $s) => $s->attr('src')) as $src) {
            self::assertStringStartsWith('/', $src);
        }
        self::assertCount(0, $crawler->filter('iframe'));
    }

    public function testEmptyFooterWithoutDetails(): void
    {
        $this->setting('whatsapp_phone', '');
        $crawler = $this->client->request('GET', '/');

        self::assertCount(0, $crawler->filter('footer .site-footer__social, footer address p'));
        self::assertStringNotContainsString('SIRET', $crawler->filter('footer')->text());
    }

    public function testQuoteCounterOnlyWhenQuoteOnline(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('[data-selection-target="counter"]'));

        $this->setting('quote_online', '1');
        $crawler = $this->client->request('GET', '/location');
        self::assertCount(1, $crawler->filter('header .site-header__tools a[data-selection-target="counter"][href="/mon-devis"]'));
    }

    // --- Story 2.2 ---

    public function testEveryPublicPageHasUniqueTitleDescriptionCanonicalAndOpenGraph(): void
    {
        $chaises = $this->category('Chaises');
        $tables = $this->category('Tables');
        $napoleon = $this->product('Chaise Napoléon', category: $chaises, description: 'Chaise Napoléon III blanche, assise confortable.');
        $pliante = $this->product('Chaise pliante', category: $chaises, photo: false);
        $this->product('Table ronde', category: $tables);
        $this->pack('Pack 100 invités', [[$napoleon, 100]]);
        $hidden = $this->product('Chaise ancienne', category: $chaises, visible: false);
        // Stories 2.5 + 2.6 : réalisation et lieu partenaire
        $venue = new Venue();
        $venue->setName('Château de la Rivière');
        $venue->initSlug('chateau-de-la-riviere');
        $venue->setCity('Fronsac');
        $venue->setPhoto('lieu.jpg');
        $venue->setPhotoAlt('Le château');
        $realisation = new Realisation();
        $realisation->setTitle('Mariage de Léa');
        $realisation->initSlug('mariage-de-lea');
        $realisation->setEventDate(new \DateTimeImmutable('2026-06-13'));
        $realisation->setVenue($venue);
        $realisation->applyVenue();
        $this->em->persist($venue);
        $this->em->persist($realisation);
        $this->em->flush();

        $urls = ['/', '/location', '/location/chaises', '/location/tables', $this->url($napoleon), $this->url($pliante), '/location/packs/pack-100-invites', '/recherche?q=chaise', '/recherche?q=table', $this->url($hidden), '/mon-devis',
            // Stories 2.3 + 2.4 : pages de base (contenu provisoire de la migration), Comment ça marche, FAQ
            '/a-propos', '/contact', '/mentions-legales', '/conditions-generales-de-location', '/politique-de-confidentialite', '/gestion-des-cookies', '/comment-ca-marche', '/questions-frequentes',
            '/realisations', '/realisations/mariage-de-lea', '/lieux/chateau-de-la-riviere'];
        $titles = $descriptions = [];
        foreach ($urls as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            $title = $crawler->filter('title')->text();
            $description = $crawler->filter('meta[name="description"]')->attr('content');
            self::assertStringEndsWith('Seydeco', $title, $url);
            self::assertGreaterThan(30, mb_strlen($description), $url);
            self::assertLessThanOrEqual(160, mb_strlen($description), $url);
            $titles[$url] = $title;
            $descriptions[$url] = $description;
            // Un seul bloc SEO dans le <head>
            self::assertCount(1, $crawler->filter('head title'));
            self::assertCount(1, $crawler->filter('head link[rel="canonical"]'));
            $canonical = $crawler->filter('link[rel="canonical"]')->attr('href');
            self::assertStringStartsWith('http://localhost/', $canonical, $url);
            foreach (['og:title' => $title, 'og:description' => $description, 'og:url' => $canonical] as $property => $expected) {
                self::assertSame($expected, $crawler->filter("meta[property=\"$property\"]")->attr('content'), "$property sur $url");
            }
            self::assertContains($crawler->filter('meta[property="og:type"]')->attr('content'), ['website', 'product'], $url);
        }
        self::assertSame(array_unique($titles), $titles, 'Titre en double');
        self::assertSame(array_unique($descriptions), $descriptions, 'Description en double');

        // Titre construit depuis le contenu, prix « dès »
        self::assertSame("Chaise Napoléon — location dès 3,00\u{A0}€ | Seydeco", $titles[$this->url($napoleon)]);
        self::assertSame('Chaise Napoléon III blanche, assise confortable.', $descriptions[$this->url($napoleon)]);
        // Adresse canonique sans paramètre technique, sauf le mot cherché
        $crawler = $this->client->request('GET', '/location/chaises?tri=prix-croissant&page=2');
        self::assertSame('http://localhost/location/chaises', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('http://localhost/recherche?q=chaise', $this->client->request('GET', '/recherche?q=chaise&page=2')->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testProductPageOpenGraphJsonLdAndPinterest(): void
    {
        $product = $this->product('Chaise Napoléon', description: 'Chaise blanche.');

        $crawler = $this->client->request('GET', $this->url($product));

        self::assertSame('product', $crawler->filter('meta[property="og:type"]')->attr('content'));
        $image = $crawler->filter('meta[property="og:image"]')->attr('content');
        self::assertMatchesRegularExpression('~^http://localhost/media/cache/(resolve/)?product_800/~', $image);
        // Rich Pins
        self::assertSame(['3.00', 'EUR'], [$crawler->filter('meta[property="product:price:amount"]')->attr('content'), $crawler->filter('meta[property="product:price:currency"]')->attr('content')]);

        $ld = $this->jsonLd($crawler);
        self::assertSame(['LocalBusiness', 'Product'], array_column($ld, '@type'));
        $productLd = $ld[1];
        self::assertSame('Chaise Napoléon', $productLd['name']);
        self::assertSame('Chaise blanche.', $productLd['description']);
        self::assertSame([$image], $productLd['image']);
        self::assertSame(['@type' => 'Offer', 'price' => '3.00', 'priceCurrency' => 'EUR', 'url' => 'http://localhost'.$this->url($product)], $productLd['offers']);
        // Jamais de disponibilité ni de stock
        self::assertDoesNotMatchRegularExpression('/availability|stock|inventory/i', (string) json_encode($ld));

        // « Épingler » : lien de partage simple, nouvel onglet, avec image, URL et description
        $pin = $crawler->filter('.product-gallery a.pin-link');
        self::assertSame(['_blank', 'noopener'], [$pin->attr('target'), $pin->attr('rel')]);
        parse_str((string) parse_url($pin->attr('href'), \PHP_URL_QUERY), $query);
        self::assertStringStartsWith('https://www.pinterest.com/pin/create/button/?', $pin->attr('href'));
        self::assertSame(['url' => 'http://localhost'.$this->url($product), 'media' => $image, 'description' => 'Chaise blanche.'], $query);
    }

    public function testProductWithoutPhotoHasNoOgImageAndValidJsonLd(): void
    {
        $product = $this->product('Chaise pliante', photo: false);

        $crawler = $this->client->request('GET', $this->url($product));

        self::assertCount(0, $crawler->filter('meta[property="og:image"], a.pin-link'));
        $ld = $this->jsonLd($crawler);
        self::assertSame('Product', $ld[1]['@type']);
        self::assertArrayNotHasKey('image', $ld[1]);
    }

    public function testHiddenProductIsNoindexWithoutProductJsonLd(): void
    {
        $this->setting('search_engines_open', '1');
        $product = $this->product('Chaise ancienne', visible: false);

        $crawler = $this->client->request('GET', $this->url($product));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', "Ce produit n'est plus proposé");
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(['LocalBusiness'], array_column($this->jsonLd($crawler), '@type'));
    }

    public function testLocalBusinessJsonLdFromSettings(): void
    {
        $this->setting('phone', '05 56 00 00 00');
        $this->setting('address', "12 rue des Fleurs\n33000 Bordeaux");
        $this->setting('instagram_url', 'https://www.instagram.com/seydeco');
        // Réglages de livraison partagés avec d'autres tests : remis à leur valeur à la fin
        $before = $this->db->fetchAllKeyValue("SELECT name, value FROM setting WHERE name IN ('delivery_max_km', 'depot_city')");
        $this->setting('delivery_max_km', '50');
        $this->setting('depot_city', 'Bordeaux');
        try {
            $this->assertLocalBusiness();
        } finally {
            array_walk($before, fn (string $value, string $name) => $this->setting($name, $value));
        }
    }

    private function assertLocalBusiness(): void
    {
        // Un « </script> » saisi ne peut pas sortir de la balise
        $this->setting('tiktok_url', 'https://www.tiktok.com/@seydeco</script><script>alert(1)</script>');

        $crawler = $this->client->request('GET', '/');

        $ld = $this->jsonLd($crawler);
        self::assertCount(1, $ld);
        self::assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'Seydeco',
            'url' => 'http://localhost/',
            'telephone' => '+33556000000',
            'address' => '12 rue des Fleurs, 33000 Bordeaux',
            'areaServed' => 'Dans un rayon de 50 km autour de Bordeaux',
            'sameAs' => ['https://www.instagram.com/seydeco', 'https://www.tiktok.com/@seydeco</script><script>alert(1)</script>'],
        ], $ld[0]);
        self::assertStringNotContainsString('<script>alert', (string) $this->client->getResponse()->getContent());
    }

    public function testSearchEnginesClosedByDefault(): void
    {
        $this->category('Chaises');
        foreach (['/', '/location', '/location/chaises'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'), $url);
        }

        $this->client->request('GET', '/robots.txt');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
        self::assertSame("User-agent: *\nDisallow: /\n", $this->client->getResponse()->getContent());
    }

    public function testSearchEnginesOpen(): void
    {
        $this->setting('search_engines_open', '1');
        $this->category('Chaises');
        foreach (['/', '/location', '/location/chaises'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertCount(0, $crawler->filter('meta[name="robots"]'), $url);
        }
        // Résultats de recherche jamais indexés
        $crawler = $this->client->request('GET', '/recherche?q=chaise');
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));

        $this->client->request('GET', '/robots.txt');
        $robots = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString("Disallow: /\n", $robots);
        self::assertStringContainsString('Sitemap: http://localhost/sitemap.xml', $robots);
    }

    /** @return list<array<string, mixed>> chaque JSON-LD de la page, décodé (JSON invalide = échec) */
    private function jsonLd(Crawler $crawler): array
    {
        return $crawler->filter('script[type="application/ld+json"]')->each(fn (Crawler $s) => json_decode($s->text(), true, flags: \JSON_THROW_ON_ERROR));
    }

    private function setting(string $name, string $value): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
    }

    private function url(Product $product): string
    {
        return '/location/'.$product->getCategory()->getSlug().'/'.$product->getSlug();
    }

    private function category(string $name): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(mb_strtolower($name));
        $category->setPricingType(PricingType::Other);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function product(string $name, ?Category $category = null, bool $visible = true, ?string $description = null, bool $photo = true): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
        $product->setCategory($category ?? $this->category('Chaises'));
        $product->setVisible($visible);
        $product->setDescription($description);
        $product->setMinQty(1);
        $product->addPriceTier(new PriceTier(1, 300));
        if ($photo) {
            $image = new ProductPhoto();
            $image->setFilename('photo.jpg');
            $image->setAlt($name.' de face');
            $product->addPhoto($image);
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }

    /** @param list<array{Product, int}> $items */
    private function pack(string $name, array $items): void
    {
        $pack = new Pack();
        $pack->setName($name);
        $pack->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
        $pack->setPhoto('pack.jpg');
        $pack->setPhotoAlt('Photo de '.$name);
        foreach ($items as [$product, $qty]) {
            $pack->addItem(new PackItem($product, $qty));
        }
        $this->em->persist($pack);
        $this->em->flush();
    }
}
