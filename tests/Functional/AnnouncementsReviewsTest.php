<?php

namespace App\Tests\Functional;

use App\Entity\Announcement;
use App\Entity\Review;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Stories 2.7 (bandeau d'annonces) et 2.8 (Bloc avis) côté public.
 * Navigateur (vérification manuelle, 390 et 1280 px) : défilement continu, pause/lecture, arrêt au survol et au focus,
 * « réduire les animations » (une annonce, précédent / suivant), étoiles et citation du Bloc avis.
 */
final class AnnouncementsReviewsTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $db;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->executeStatement('TRUNCATE TABLE announcement');
        $this->db->executeStatement('TRUNCATE TABLE review');
        SiteLayoutTest::resetSettings($this->db);
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->db->executeStatement('TRUNCATE TABLE announcement');
        $this->db->executeStatement('TRUNCATE TABLE review');
        SiteLayoutTest::resetSettings($this->db);
        parent::tearDown();
    }

    // --- Story 2.7 ---

    public function testNoActiveAnnouncementRendersNoBar(): void
    {
        $this->announcement('Terminée hier', '-5 days', '-1 day');
        $this->announcement('Commence demain', '+1 day', '+5 days');
        $this->announcement('Masquée', '-1 day', '+1 day', visible: false);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.announcement-bar'));
    }

    public function testActiveAnnouncementsInOrderAboveHeaderWithPauseButton(): void
    {
        $this->announcement('Dernier jour', '-3 days', 'today', position: 2);
        $this->announcement('Nouveau : les arches <b>fleuries</b>', 'today', '+3 days', position: 1, link: '/location');
        $this->announcement('Terminée hier', '-5 days', '-1 day', position: 0);

        $crawler = $this->client->request('GET', '/location');

        $bar = $crawler->filter('body > .announcement-bar');
        self::assertCount(1, $bar);
        self::assertSame('header', $bar->nextAll()->first()->nodeName(), 'Au-dessus de l\'en-tête');
        $lists = $bar->filter('.announcement-bar__list');
        self::assertCount(2, $lists);
        self::assertSame(['Nouveau : les arches <b>fleuries</b>', 'Dernier jour'], $lists->first()->filter('li')->each(fn (Crawler $li) => $li->text()));
        self::assertSame('/location', $lists->first()->filter('a')->attr('href'));
        self::assertSame(1, $lists->first()->filter('li')->first()->filter('svg')->count(), 'Losange après chaque annonce');
        // Seconde copie pour la boucle : cachée aux lecteurs d'écran, hors tabulation
        self::assertSame('true', $lists->last()->attr('aria-hidden'));
        self::assertSame('-1', $lists->last()->filter('a')->attr('tabindex'));
        $pause = $bar->filter('.announcement-bar__pause');
        self::assertSame(['button', 'false', 'Mettre en pause le défilement'], [$pause->attr('type'), $pause->attr('aria-pressed'), $pause->text()]);
        self::assertSame(['Annonce précédente', 'Annonce suivante'], $bar->filter('.announcement-bar__step')->each(fn (Crawler $b) => $b->text()));
        self::assertStringContainsString('is-current', (string) $lists->first()->filter('li')->first()->attr('class'), 'Annonce montrée en mouvement réduit');
    }

    public function testSingleAnnouncementWithoutPreviousNext(): void
    {
        $this->announcement('Seule annonce', 'today', 'today');

        $crawler = $this->client->request('GET', '/questions-frequentes');

        self::assertCount(1, $crawler->filter('.announcement-bar'));
        self::assertCount(0, $crawler->filter('.announcement-bar__step'));
    }

    // --- Story 2.8 ---

    public function testReviewBlockOnAboutPageWithStarsTextLinkAndJsonLd(): void
    {
        $this->setting('google_reviews_url', 'https://g.page/r/seydeco/review');
        $this->review('Karim D.', 4, "Très bon service.\n<script>alert(1)</script>", position: 2);
        $this->review('Inès B.', 5, 'Superbe décoration !', position: 1);
        $this->review('Avis masqué', 1, 'Masqué', position: 0, visible: false);

        $crawler = $this->client->request('GET', '/a-propos');

        self::assertResponseIsSuccessful();
        $reviews = $crawler->filter('main .reviews .review');
        self::assertCount(2, $reviews);
        $first = $reviews->first();
        self::assertSame('Note de 5 sur 5', $first->filter('.review__stars .visually-hidden')->text());
        self::assertCount(5, $first->filter('.review__star'));
        self::assertCount(1, $reviews->last()->filter('.review__star--empty'));
        self::assertSame('Superbe décoration !', $first->filter('blockquote.review__quote')->text());
        self::assertSame('Inès B. · 12/09/2026', $first->filter('.review__author')->text());
        self::assertStringNotContainsString('<script>alert', (string) $this->client->getResponse()->getContent());

        $link = $crawler->filter('main .reviews__more a');
        self::assertSame(['https://g.page/r/seydeco/review', '_blank', 'noopener'], [$link->attr('href'), $link->attr('target'), $link->attr('rel')]);
        self::assertStringStartsWith('Voir tous nos avis sur Google', $link->text());
        // Aucun script ni widget Google
        self::assertCount(0, $crawler->filter('script[src*="google"], iframe'));

        $ld = $this->jsonLd($crawler);
        self::assertSame(['LocalBusiness', 'Review', 'Review'], array_column($ld, '@type'));
        self::assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'Review',
            'itemReviewed' => ['@type' => 'LocalBusiness', 'name' => 'Seydeco', 'url' => 'http://localhost/'],
            'author' => ['@type' => 'Person', 'name' => 'Inès B.'],
            'reviewRating' => ['@type' => 'Rating', 'ratingValue' => 5, 'bestRating' => 5, 'worstRating' => 1],
            'reviewBody' => 'Superbe décoration !',
            'datePublished' => '2026-09-12',
        ], $ld[1]);
    }

    public function testWithoutReviewsOnlyGoogleLinkOrNothing(): void
    {
        $this->review('Avis masqué', 5, 'Masqué', visible: false);

        $crawler = $this->client->request('GET', '/a-propos');
        self::assertCount(0, $crawler->filter('.reviews'));
        self::assertSame(['LocalBusiness'], array_column($this->jsonLd($crawler), '@type'));

        $this->setting('google_reviews_url', 'https://g.page/r/seydeco/review');
        $crawler = $this->client->request('GET', '/a-propos');
        self::assertCount(1, $crawler->filter('.reviews .reviews__more a'));
        self::assertCount(0, $crawler->filter('.reviews h2, .review'));
    }

    public function testReviewsOnlyOnAboutPage(): void
    {
        $this->review('Inès B.', 5, 'Superbe décoration !');

        $crawler = $this->client->request('GET', '/questions-frequentes');

        self::assertCount(0, $crawler->filter('.reviews'));
        self::assertNotContains('Review', array_column($this->jsonLd($crawler), '@type'));
    }

    private function announcement(string $text, string $start, string $end, int $position = 0, bool $visible = true, ?string $link = null): void
    {
        $announcement = new Announcement();
        $announcement->setText($text);
        $announcement->setLink($link);
        $announcement->setStartsOn(new \DateTimeImmutable($start));
        $announcement->setEndsOn(new \DateTimeImmutable($end));
        $announcement->setPosition($position);
        $announcement->setVisible($visible);
        $this->em->persist($announcement);
        $this->em->flush();
    }

    private function review(string $author, int $rating, string $text, int $position = 0, bool $visible = true): void
    {
        $review = new Review();
        $review->setAuthor($author);
        $review->setRating($rating);
        $review->setText($text);
        $review->setReviewedOn(new \DateTimeImmutable('2026-09-12'));
        $review->setPosition($position);
        $review->setVisible($visible);
        $this->em->persist($review);
        $this->em->flush();
    }

    private function setting(string $name, string $value): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
    }

    /** @return list<array<string, mixed>> */
    private function jsonLd(Crawler $crawler): array
    {
        return $crawler->filter('script[type="application/ld+json"]')->each(fn (Crawler $s) => json_decode($s->text(), true, flags: \JSON_THROW_ON_ERROR));
    }
}
