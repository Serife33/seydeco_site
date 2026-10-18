<?php

namespace App\Tests\Functional;

use App\Entity\FaqItem;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Stories 2.3 (À propos, Contact, pages légales, page masquée, 404) et 2.4 (Comment ça marche, FAQ) côté public.
 * Navigateur (vérification manuelle, 390 et 1280 px) : mise en page des pages, des 4 étapes et de la FAQ.
 */
final class ContentPagesTest extends WebTestCase
{
    use PagesSnapshot;

    private KernelBrowser $client;
    private Connection $db;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db = static::getContainer()->get(Connection::class);
        SiteLayoutTest::resetSettings($this->db);
        $this->setting('whatsapp_phone', '06 12 34 56 78');
        $this->snapshotPages($this->db);
    }

    protected function tearDown(): void
    {
        $this->restorePages($this->db);
        SiteLayoutTest::resetSettings($this->db);
        parent::tearDown();
    }

    // --- Story 2.3 ---

    public function testAboutPageWithSeoAndActiveNavigation(): void
    {
        $this->db->executeStatement("UPDATE page SET content = ?, description = ? WHERE slug = 'a-propos'", ["## Serife\n\nPassionnée de **décoration**.", 'Serife et Seydeco, location à Bordeaux.']);

        $crawler = $this->client->request('GET', '/a-propos');

        self::assertResponseIsSuccessful();
        self::assertSame('À propos | Seydeco', $crawler->filter('title')->text());
        self::assertSame('À propos', $crawler->filter('main h1')->text());
        self::assertSame('<h2>Serife</h2>'."\n".'<p>Passionnée de <strong>décoration</strong>.</p>', trim($crawler->filter('main .rich-text')->html()));
        self::assertSame('Serife et Seydeco, location à Bordeaux.', $crawler->filter('meta[name="description"]')->attr('content'));
        self::assertSame('http://localhost/a-propos', $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertSame('À propos | Seydeco', $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertSame('http://localhost/a-propos', $crawler->filter('meta[property="og:url"]')->attr('content'));
        self::assertSame('page', $crawler->filter('header .site-nav a[href="/a-propos"]')->attr('aria-current'));
        self::assertCount(1, $crawler->filter('header .site-nav a[aria-current]'));
    }

    public function testPageWithoutDescriptionUsesContentAndPhotoForOpenGraph(): void
    {
        $this->db->executeStatement("UPDATE page SET content = 'Seydeco est née en 2024 de **l\\'envie** de rendre les fêtes plus belles.', description = NULL, photo = 'about.jpg', photo_alt = 'Serife dresse une table' WHERE slug = 'a-propos'");

        $crawler = $this->client->request('GET', '/a-propos');

        self::assertSame("Seydeco est née en 2024 de l'envie de rendre les fêtes plus belles.", $crawler->filter('meta[name="description"]')->attr('content'));
        $photo = $crawler->filter('main img.content-page__photo');
        self::assertSame(['Serife dresse une table', '1200', '800'], [$photo->attr('alt'), $photo->attr('width'), $photo->attr('height')]);
        self::assertMatchesRegularExpression('~^http://localhost/media/cache/(resolve/)?page_photo/about\.jpg~', $crawler->filter('meta[property="og:image"]')->attr('content'));
    }

    public function testContactPageWithAddressButtonsAndEmailButNoForm(): void
    {
        $this->setting('address', "12 rue des Fleurs\n33530 Bassens");
        $this->setting('email', 'contact@seydeco.fr');

        $crawler = $this->client->request('GET', '/contact');

        self::assertResponseIsSuccessful();
        $details = $crawler->filter('main .contact-details');
        self::assertStringContainsString('12 rue des Fleurs', $details->text());
        self::assertSame(['https://wa.me/33612345678?text='.rawurlencode("Bonjour, j'ai une question sur Seydeco"), 'tel:+33612345678'], $details->filter('.contact-buttons a')->each(fn (Crawler $a) => $a->attr('href')));
        self::assertSame('mailto:contact@seydeco.fr', $details->filter('a[href^="mailto:"]')->attr('href'));
        self::assertSame('page', $crawler->filter('header .site-nav a[href="/contact"]')->attr('aria-current'));
        // Aucun formulaire de contact (seule la recherche du site) ni showroom
        self::assertCount(0, $crawler->filter('main form, main input, main textarea'));
        self::assertStringNotContainsStringIgnoringCase('showroom', (string) $this->client->getResponse()->getContent());

        // Email vide : pas de lien mailto
        $this->setting('email', '');
        $crawler = $this->client->request('GET', '/contact');
        self::assertCount(0, $crawler->filter('a[href^="mailto:"]'));
    }

    public function testFooterLinksToLegalPagesFaqAndExtraPagesInOrder(): void
    {
        $this->db->executeStatement("INSERT INTO page (title, slug, content, position, visible) VALUES ('Nos engagements', 'nos-engagements', 'Texte', 7, 1), ('Brouillon', 'brouillon', 'Texte', 8, 0)");

        $crawler = $this->client->request('GET', '/location');

        $links = $crawler->filter('footer .site-footer__links a');
        self::assertSame(['Questions fréquentes', 'Mentions légales', 'Conditions générales de location', 'Politique de confidentialité', 'Gestion des cookies', 'Nos engagements'], $links->each(fn (Crawler $a) => $a->text()));
        self::assertSame(['/questions-frequentes', '/mentions-legales', '/conditions-generales-de-location', '/politique-de-confidentialite', '/gestion-des-cookies', '/nos-engagements'], $links->each(fn (Crawler $a) => $a->attr('href')));
        // Chaque page de base existe et répond
        foreach ($links->each(fn (Crawler $a) => $a->attr('href')) as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        // La politique de confidentialité mentionne la transmission de l'adresse au calcul de distance
        $crawler = $this->client->request('GET', '/politique-de-confidentialite');
        self::assertStringContainsString('service de calcul de distance', $crawler->filter('main')->text());
        self::assertStringContainsString('À compléter', $crawler->filter('main')->text());
    }

    public function testHiddenPageIsAvailableNoindexWithoutNavigationEntry(): void
    {
        $this->setting('search_engines_open', '1');
        $this->db->executeStatement("UPDATE page SET visible = 0 WHERE slug IN ('a-propos', 'mentions-legales')");

        $crawler = $this->client->request('GET', '/a-propos');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', "Cette page n'est plus disponible.");
        self::assertSame('/', $crawler->filter('main a')->attr('href'));
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertSame(['LocalBusiness'], array_column($this->jsonLd($crawler), '@type'));
        // Plus de lien vers une page masquée
        self::assertSame(['Catalogue', 'Réalisations', 'Comment ça marche', 'Contact'], $crawler->filter('header .site-nav a')->each(fn (Crawler $a) => $a->text()));
        self::assertCount(0, $crawler->filter('footer a[href="/mentions-legales"]'));
    }

    public function testUnknownUrlShowsFriendly404(): void
    {
        // Gabarit d'erreur du site : rendu comme en production (sans la page de débogage)
        self::ensureKernelShutdown();
        $client = static::createClient(['debug' => false]);

        foreach (['/nimporte-quoi', '/location/inconnue', '/Nimporte_Quoi'] as $url) {
            $crawler = $client->request('GET', $url);
            self::assertResponseStatusCodeSame(404, $url);
            self::assertSame('Page introuvable | Seydeco', $crawler->filter('title')->text());
            self::assertSame('/location', $crawler->filter('main a.button-primary')->attr('href'));
            self::assertStringStartsWith('https://wa.me/33612345678', $crawler->filter('main .contact-buttons a')->attr('href'));
            self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
        }
    }

    public function testExtraPageServedOnItsSlugWithoutHidingSiteRoutes(): void
    {
        $this->db->executeStatement("INSERT INTO page (title, slug, content, position, visible) VALUES ('Nos engagements', 'nos-engagements', '<script>alert(1)</script> [x](javascript:alert)', 7, 1)");

        $crawler = $this->client->request('GET', '/nos-engagements');

        self::assertResponseIsSuccessful();
        self::assertSame('Nos engagements', $crawler->filter('main h1')->text());
        // Script saisi : affiché en texte, lien dangereux non rendu
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt; x', $crawler->filter('main .rich-text p')->html());
        self::assertCount(0, $crawler->filter('main .rich-text a, main script'));
        // Les routes du site gardent la main
        $this->client->request('GET', '/location');
        self::assertSelectorTextSame('h1', 'Location');
        $this->client->request('GET', '/robots.txt');
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');
    }

    // --- Story 2.4 ---

    public function testHowItWorksStepsAndRulesFromSettings(): void
    {
        $before = $this->db->fetchAllKeyValue("SELECT name, value FROM setting WHERE name IN ('deposit_amount', 'deposit_percent')");
        $this->setting('deposit_amount', '30000');
        $this->setting('deposit_percent', '30');
        try {
            $crawler = $this->client->request('GET', '/comment-ca-marche');
        } finally {
            array_walk($before, fn (string $value, string $name) => $this->setting($name, $value));
        }

        self::assertResponseIsSuccessful();
        self::assertSame('page', $crawler->filter('header .site-nav a[href="/comment-ca-marche"]')->attr('aria-current'));
        $steps = $crawler->filter('section.how-it-works ol li');
        self::assertSame(['i', 'ii', 'iii', 'iv'], $steps->filter('.accent-numeral')->each(fn (Crawler $n) => $n->text()));
        self::assertSame(['Je compose', "J'obtiens mon devis", 'Je réserve', 'Je profite'], $steps->filter('h3')->each(fn (Crawler $h) => $h->text()));
        self::assertCount(4, $steps->filter('p'));
        // Devis en ligne éteint : aucune promesse de devis en ligne
        self::assertStringContainsString('WhatsApp', $steps->eq(1)->text());

        $rules = array_combine($crawler->filter('.info-block dt')->each(fn (Crawler $n) => $n->text()), $crawler->filter('.info-block dd')->each(fn (Crawler $n) => $n->text()));
        self::assertSame('30 % du devis, à la réservation', $rules['Acompte']);
        self::assertSame("300,00\u{A0}€", $rules['Caution']);
        self::assertStringStartsWith('Au dépôt de Bassens', $rules['Retrait au dépôt']);
        // Valeurs initiales de la migration de la story 1.9
        self::assertSame("1\u{A0}€ / km par la route depuis Bassens, dès 80 chaises, 70 km max", $rules['Livraison']);
        self::assertArrayHasKey('Minimum de commande', $rules);
        self::assertSame('/questions-frequentes', $crawler->filterXPath('//main//a[contains(., "questions fréquentes")]')->attr('href'));
    }

    public function testHowItWorksWithEmptySettingsAndQuoteOnline(): void
    {
        $before = $this->db->fetchAllKeyValue("SELECT name, value FROM setting WHERE name IN ('deposit_percent', 'delivery_price_per_km', 'delivery_min_chairs', 'delivery_max_km')");
        array_walk($before, fn (string $value, string $name) => $this->setting($name, ''));
        $this->setting('quote_online', '1');
        try {
            $crawler = $this->client->request('GET', '/comment-ca-marche');
        } finally {
            array_walk($before, fn (string $value, string $name) => $this->setting($name, $value));
        }

        self::assertSame(['Acompte', 'Caution', 'Retrait au dépôt', 'Minimum de commande'], $crawler->filter('.info-block dt')->each(fn (Crawler $n) => $n->text()));
        self::assertSame('Montant indiqué dans votre devis', $crawler->filter('.info-block dd')->first()->text());
        self::assertStringNotContainsString('WhatsApp', $crawler->filter('section.how-it-works ol li')->eq(1)->text());
    }

    public function testFaqGroupedByThemeAllExpandedWithMatchingJsonLd(): void
    {
        $this->faq('Livrez-vous le dimanche ?', 'Oui, **sur demande**.', 'Livraison');
        $this->faq('Comment réserver ?', "Envoyez votre sélection.\n\n- Devis\n- Acompte");
        $this->faq('Question masquée', 'Réponse', 'Livraison', visible: false);
        $this->faq('Quelle caution ?', 'Voir le [devis](/mon-devis) <b>signé</b>.', 'Caution');
        $this->faq('Jusqu\'où livrez-vous ?', '70 km.', 'Livraison');

        $crawler = $this->client->request('GET', '/questions-frequentes');

        self::assertResponseIsSuccessful();
        self::assertSame('Questions fréquentes sur la location | Seydeco', $crawler->filter('title')->text());
        // Thèmes dans l'ordre de leur première question ; questions sans thème : groupe sans titre
        self::assertSame(['Livraison', 'Caution'], $crawler->filter('.faq-group > h2')->each(fn (Crawler $h) => $h->text()));
        $questions = $crawler->filter('.faq-item__question')->each(fn (Crawler $q) => $q->text());
        self::assertSame(['Livrez-vous le dimanche ?', "Jusqu'où livrez-vous ?", 'Comment réserver ?', 'Quelle caution ?'], $questions);
        // Toutes dépliées : réponses en texte, aucun bouton ni details
        self::assertCount(0, $crawler->filter('main details, main button'));
        $answers = $crawler->filter('.faq-item__answer')->each(fn (Crawler $a) => trim($a->html()));
        self::assertSame('<p>Voir le <a href="/mon-devis">devis</a> &lt;b&gt;signé&lt;/b&gt;.</p>', $answers[3]);

        $ld = $this->jsonLd($crawler);
        self::assertSame(['LocalBusiness', 'FAQPage'], array_column($ld, '@type'));
        self::assertSame('https://schema.org', $ld[1]['@context']);
        self::assertSame(
            array_map(fn (string $q, string $a) => ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]], $questions, $answers),
            $ld[1]['mainEntity'],
        );
        self::assertStringNotContainsString('<b>', (string) $this->client->getResponse()->getContent());
    }

    public function testEmptyFaqShowsComingSoonWithContactsAndNoJsonLd(): void
    {
        $this->faq('Question masquée', 'Réponse', visible: false);

        $crawler = $this->client->request('GET', '/questions-frequentes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Les questions fréquentes arrivent bientôt.');
        self::assertCount(2, $crawler->filter('main .contact-buttons a'));
        self::assertSame(['LocalBusiness'], array_column($this->jsonLd($crawler), '@type'));
    }

    private function faq(string $question, string $answer, ?string $theme = null, bool $visible = true): void
    {
        static $position = 0;
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $item = new FaqItem();
        $item->setQuestion($question);
        $item->setAnswer($answer);
        $item->setTheme($theme);
        $item->setVisible($visible);
        $item->setPosition(++$position);
        $em->persist($item);
        $em->flush();
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(Crawler $crawler): array
    {
        return $crawler->filter('script[type="application/ld+json"]')->each(fn (Crawler $s) => json_decode($s->text(), true, flags: \JSON_THROW_ON_ERROR));
    }

    private function setting(string $name, string $value): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
    }
}
