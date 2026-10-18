<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\FaqItem;
use App\Entity\HomeHero;
use App\Entity\Pack;
use App\Entity\PackItem;
use App\Entity\PriceTier;
use App\Entity\Product;
use App\Entity\ProductPhoto;
use App\Entity\Realisation;
use App\Entity\RealisationPhoto;
use App\Entity\Venue;
use App\EventListener\PublicCacheListener;
use App\Tests\Functional\Admin\CatalogTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Stories 2.11 (bandeau cookies, consentement) et 2.12 (sitemap, audit des pages publiques, cache HTTP).
 * Navigateur (vérification manuelle, 390 et 1280 px) : bandeau affiché puis masqué après un choix (6 mois),
 * Personnaliser / Gérer mes cookies, formulaire de la page Gestion des cookies, Façade YouTube refusée puis acceptée.
 */
final class CookiesSitemapTest extends CatalogTestCase
{
    use PagesSnapshot;

    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = static::getContainer()->get(Connection::class);
        $this->snapshotPages($this->db);
    }

    protected function tearDown(): void
    {
        $this->restorePages($this->db);
        parent::tearDown();
    }

    /** Un contenu de chaque sorte, visible, avec photos et vidéo YouTube ; puis visite anonyme (sans la session admin). */
    private function createSite(): void
    {
        $em = $this->em();
        $category = $em->find(Category::class, $this->createCategory('Chaises', 1)->getId());
        foreach (['Chaise Napoléon', 'Chaise pliante', 'Chaise ancienne'] as $i => $name) {
            $product = new Product();
            $product->setName($name);
            $product->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
            $product->setCategory($category);
            $product->addPriceTier(new PriceTier(1, 300 + $i));
            $photo = new ProductPhoto();
            $photo->setFilename("chaise-$i.jpg");
            $photo->setAlt($name);
            $product->addPhoto($photo);
            $product->setFeatured(true);
            $product->setVisible(2 !== $i);
            $em->persist($product);
            $products[] = $product;
        }
        $products[0]->setVideoUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $pack = new Pack();
        $pack->setName('Pack 100 invités');
        $pack->initSlug('pack-100-invites');
        $pack->setPhoto('pack.jpg');
        $pack->setPhotoAlt('Chaises et tables du pack');
        $pack->addItem(new PackItem($products[0], 100));
        $em->persist($pack);
        $venue = new Venue();
        $venue->setName('Château de la Rivière');
        $venue->initSlug('chateau-de-la-riviere');
        $venue->setCity('Fronsac');
        $venue->setPhoto('lieu.jpg');
        $venue->setPhotoAlt('Le château');
        $em->persist($venue);
        $realisation = new Realisation();
        $realisation->setTitle('Mariage de Léa');
        $realisation->initSlug('mariage-de-lea');
        $realisation->setEventDate(new \DateTimeImmutable('2026-06-13'));
        $realisation->setVenue($venue);
        $realisation->applyVenue();
        $realisation->setVideoUrl('https://youtu.be/dQw4w9WgXcQ');
        foreach (['Table d\'honneur', 'Chaises en terrasse'] as $i => $alt) {
            $photo = new RealisationPhoto();
            $photo->setFilename("lea-$i.jpg");
            $photo->setAlt($alt);
            $realisation->addPhoto($photo);
        }
        $realisation->addProduct($products[0]);
        $em->persist($realisation);
        $faq = new FaqItem();
        $faq->setQuestion('Livrez-vous le dimanche ?');
        $faq->setAnswer('Oui, sur demande.');
        $em->persist($faq);
        $hero = new HomeHero();
        $hero->setPhoto('hero.jpg');
        $hero->setPhotoAlt('Table de mariage dressée');
        $em->persist($hero);
        $em->flush();
        $this->db->executeStatement("UPDATE page SET photo = 'apropos.jpg', photo_alt = 'Serife dans son dépôt' WHERE slug = 'a-propos'");
        // Visiteuse anonyme : ni cookie ni session du back-office
        $this->client->restart();
    }

    /** @return list<string> adresses du sitemap */
    private function sitemap(): array
    {
        $this->client->request('GET', '/sitemap.xml');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/xml; charset=UTF-8');
        $xml = new \SimpleXMLElement((string) $this->client->getResponse()->getContent());

        return array_map(fn (\SimpleXMLElement $url) => (string) $url->loc, iterator_to_array($xml->url, false));
    }

    // --- Story 2.12 ---

    public function testSitemapListsVisibleContentWithLastModification(): void
    {
        $this->createSite();

        $urls = $this->sitemap();

        self::assertSame([
            'http://localhost/', 'http://localhost/location', 'http://localhost/realisations', 'http://localhost/comment-ca-marche', 'http://localhost/questions-frequentes',
            'http://localhost/location/chaises',
            'http://localhost/location/chaises/chaise-napoleon', 'http://localhost/location/chaises/chaise-pliante',
            'http://localhost/location/packs/pack-100-invites',
            'http://localhost/realisations/mariage-de-lea',
            'http://localhost/lieux/chateau-de-la-riviere',
            'http://localhost/a-propos', 'http://localhost/contact', 'http://localhost/mentions-legales', 'http://localhost/conditions-generales-de-location', 'http://localhost/politique-de-confidentialite', 'http://localhost/gestion-des-cookies',
        ], $urls);
        $lastmods = [];
        foreach ((new \SimpleXMLElement((string) $this->client->getResponse()->getContent()))->url as $url) {
            $lastmods[(string) $url->loc] = isset($url->lastmod) ? (string) $url->lastmod : null;
        }
        self::assertSame(date('Y-m-d'), $lastmods['http://localhost/location/chaises/chaise-napoleon']);
        self::assertSame(date('Y-m-d'), $lastmods['http://localhost/']);
        // Comment ça marche vient des réglages : sans date
        self::assertNull($lastmods['http://localhost/comment-ca-marche']);
        self::assertCount(16, array_filter($lastmods));
    }

    public function testHiddenItemLeavesSitemapButOldUrlStillAnswers(): void
    {
        $this->createSite();
        $this->db->executeStatement("UPDATE product SET visible = 0 WHERE slug = 'chaise-pliante'");
        $this->db->executeStatement("UPDATE realisation SET visible = 0 WHERE slug = 'mariage-de-lea'");
        $this->db->executeStatement("UPDATE page SET visible = 0 WHERE slug = 'mentions-legales'");

        $urls = $this->sitemap();

        foreach (['/location/chaises/chaise-pliante', '/realisations/mariage-de-lea', '/mentions-legales'] as $path) {
            self::assertNotContains('http://localhost'.$path, $urls);
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertSelectorExists('meta[name="robots"][content="noindex"]');
        }
        // Lieu sans réalisation visible : toujours listé ; catégorie masquée : ses produits sortent aussi
        self::assertContains('http://localhost/lieux/chateau-de-la-riviere', $urls);
        $this->db->executeStatement('UPDATE category SET visible = 0');
        self::assertSame([], array_values(array_filter($this->sitemap(), fn (string $url) => str_contains($url, '/location/'))));
    }

    /** Audit de chaque page du sitemap : images, titres et descriptions uniques, URL propres, aucun cookie ni tiers, cache public court. */
    public function testEveryPageOfTheSitemapPassesTheAudit(): void
    {
        $this->createSite();
        $filterSets = static::getContainer()->getParameter('liip_imagine.filter_sets');
        $webp = function (string $url, string $where) use ($filterSets): void {
            self::assertSame(1, preg_match('~^http://localhost/media/cache/(?:resolve/)?(\w+)/~', $url, $m), "Image hors LiipImagine sur $where : $url");
            self::assertSame('webp', $filterSets[$m[1]]['format'] ?? null, "Filtre non WebP sur $where : $url");
        };
        $titles = $descriptions = [];
        $images = 0;

        foreach ($this->sitemap() as $url) {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            self::assertMatchesRegularExpression('~^/([a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*)*)?$~', $path, 'URL non propre');
            self::assertStringNotContainsString('?', $url);
            $crawler = $this->client->request('GET', $path);
            $response = $this->client->getResponse();
            self::assertResponseIsSuccessful($path);

            // Aucun cookie (ni session) ; cache public de 5 minutes
            self::assertSame([], $response->headers->getCookies(), "Cookie posé sur $path");
            self::assertTrue($response->headers->hasCacheControlDirective('public'), "Cache public sur $path : ".$response->headers->get("Cache-Control"));
            self::assertSame((string) PublicCacheListener::MAX_AGE, $response->headers->getCacheControlDirective('max-age'), $path);

            // Rien de tiers avant consentement : scripts, styles, cadres, médias et importmap servis par le site
            $html = (string) $response->getContent();
            self::assertStringNotContainsString('<iframe', $html, $path);
            $resources = $crawler->filter('script[src], link[rel="stylesheet"], link[rel="preload"], link[rel="modulepreload"], iframe, video[src], source[src]')
                ->each(fn (Crawler $n) => $n->attr('src') ?? $n->attr('href'));
            $importmap = $crawler->filter('script[type="importmap"]');
            self::assertCount(1, $importmap, $path);
            array_push($resources, ...array_values(json_decode($importmap->text(), true, flags: \JSON_THROW_ON_ERROR)['imports']));
            foreach ($resources as $resource) {
                self::assertMatchesRegularExpression('~^(/(?!/)|http://localhost/|data:)~', $resource, "Ressource tierce sur $path");
            }
            // Bandeau cookies présent, masqué tant que le contrôleur consent ne l'affiche pas
            self::assertCount(1, $crawler->filter('body[data-controller~="consent"] .bottom-bars > .cookie-banner[hidden]'), $path);

            $titles[$path] = $crawler->filter('title')->text();
            $descriptions[$path] = $crawler->filter('meta[name="description"]')->attr('content');

            // Images : alt, dimensions, WebP LiipImagine (+ srcset pour les grandes), lazy hors du premier écran
            $eager = 0;
            $lazySeen = false;
            foreach ($crawler->filter('img[src]') as $img) {
                ++$images;
                $where = $path.' ('.$img->getAttribute('src').')';
                self::assertTrue($img->hasAttribute('alt'), "alt absent sur $where");
                self::assertMatchesRegularExpression('/^\d+$/', $img->getAttribute('width'), "largeur sur $where");
                self::assertMatchesRegularExpression('/^\d+$/', $img->getAttribute('height'), "hauteur sur $where");
                $webp($img->getAttribute('src'), $where);
                // Grandes images, affichées selon la largeur de l'écran ; vignettes et cartes (≤ 480 px) déjà en double densité
                if ((int) $img->getAttribute('width') >= 800) {
                    self::assertNotSame('', $img->getAttribute('srcset'), "srcset absent sur $where");
                    foreach (explode(',', $img->getAttribute('srcset')) as $candidate) {
                        $webp(explode(' ', trim($candidate))[0], $where);
                    }
                }
                if ('lazy' === $img->getAttribute('loading')) {
                    $lazySeen = true;
                } else {
                    // Chargées tout de suite : seulement les premières images de la page (premier écran)
                    self::assertFalse($lazySeen, "Image sans lazy après une image lazy sur $where");
                    self::assertLessThanOrEqual(4, ++$eager, "Trop d'images sans lazy sur $path");
                }
            }
        }

        self::assertSame(array_unique($titles), $titles, 'Titre en double');
        self::assertSame(array_unique($descriptions), $descriptions, 'Description en double');
        self::assertGreaterThan(10, $images);
    }

    public function testPersonalPagesAreNeverPubliclyCached(): void
    {
        $this->client->request('GET', '/admin/haut-accueil/apercu');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->client->getResponse()->headers->hasCacheControlDirective('public'));
        self::assertTrue($this->client->getResponse()->headers->hasCacheControlDirective('private'));

        $this->client->restart();
        foreach (['/mon-devis', '/admin/connexion'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
            self::assertFalse($this->client->getResponse()->headers->hasCacheControlDirective('public'), $path);
        }
        // Page introuvable : pas de cache public
        $this->client->request('GET', '/page-inconnue');
        self::assertResponseStatusCodeSame(404);
        self::assertFalse($this->client->getResponse()->headers->hasCacheControlDirective('public'));
    }

    // --- Story 2.11 ---

    public function testCookieBannerAndCookiesPage(): void
    {
        $this->client->restart();
        $crawler = $this->client->request('GET', '/');

        $banner = $crawler->filter('.cookie-banner');
        self::assertSame('Cookies', $banner->attr('aria-label'));
        // « Accepter » et « Refuser » de même poids, puis le lien « Personnaliser »
        self::assertSame(['Accepter', 'Refuser'], $banner->filter('button.button-secondary')->each(fn (Crawler $b) => $b->text()));
        self::assertCount(2, $banner->filter('button'));
        self::assertSame('/gestion-des-cookies', $banner->filter('a')->attr('href'));
        self::assertSame('Personnaliser', $banner->filter('a')->text());
        // « Gérer mes cookies » dans le pied de page
        self::assertSame('/gestion-des-cookies', $crawler->filter('.site-footer a:contains("Gérer mes cookies")')->attr('href'));

        $crawler = $this->client->request('GET', '/gestion-des-cookies');
        $form = $crawler->filter('main form.cookie-settings[data-consent-target="form"][hidden]');
        self::assertCount(1, $form);
        self::assertSame(["Mesure d'audience", 'Vidéos YouTube'], $form->filter('input[type="checkbox"] + label')->each(fn (Crawler $l) => $l->text()));
        self::assertSame('Enregistrer', $form->filter('button[type="submit"]')->text());
        // Rien n'est envoyé au serveur : ni méthode, ni adresse, ni jeton
        self::assertNull($form->attr('action'));
        self::assertCount(0, $form->filter('input[type="hidden"]'));

        // Page masquée : ni lien « Personnaliser » ni « Gérer mes cookies » (aucun lien mort)
        $this->db->executeStatement("UPDATE page SET visible = 0 WHERE slug = 'gestion-des-cookies'");
        $crawler = $this->client->request('GET', '/');
        self::assertCount(0, $crawler->filter('.cookie-banner a, .site-footer a[href="/gestion-des-cookies"]'));
    }

    public function testYoutubeFacadeShowsProviderBeforeClick(): void
    {
        $this->createSite();

        $crawler = $this->client->request('GET', '/location/chaises/chaise-napoleon');

        self::assertCount(1, $crawler->filter('a.video-facade[data-controller="video-facade"]'));
        self::assertSame('La vidéo est fournie par YouTube', $crawler->filter('.product-video__note')->text());
        self::assertStringNotContainsString('youtube-nocookie', (string) $this->client->getResponse()->getContent());
    }
}
