<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Review;

/** Story 2.8 côté admin : Plus → Contenus → Avis. */
final class ReviewAdminTest extends CatalogTestCase
{
    public function testCreateReviewsFromMoreContents(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        $this->client->click($crawler->selectLink('Avis')->link());
        self::assertSelectorTextSame('h1', 'Avis');
        self::assertSelectorTextContains('main', 'Aucun avis.');
        self::assertSelectorExists('main a[href="/admin/coordonnees"]');

        $this->create(' Inès B. ', '5', 'Superbe décoration, merci !', '2026-09-12');
        self::assertResponseRedirects('/admin/avis');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.admin-notice', 'Avis enregistré.');
        $this->create('Karim D.', '4', 'Très bon service.', '2026-08-01');

        $reviews = $this->em()->getRepository(Review::class)->findBy([], ['position' => 'ASC']);
        self::assertSame(
            [['Inès B.', 5, 'Superbe décoration, merci !', '2026-09-12', 1, true], ['Karim D.', 4, 'Très bon service.', '2026-08-01', 2, true]],
            array_map(fn (Review $r) => [$r->getAuthor(), $r->getRating(), $r->getText(), $r->getReviewedOn()->format('Y-m-d'), $r->getPosition(), $r->isVisible()], $reviews),
        );
        $crawler = $this->client->request('GET', '/admin/avis');
        self::assertSame('Note de 5 sur 5 · 12/09/2026', $crawler->filter('.admin-list__meta')->first()->text());
    }

    public function testInvalidReviewIsRefusedWithMessages(): void
    {
        $this->create('', '', '  ', '');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#review_author ~ .admin-field__error', 'Saisir le prénom et l\'initiale.');
        self::assertSelectorTextSame('#review_rating ~ .admin-field__error', 'Choisir la note.');
        self::assertSelectorTextSame('#review_reviewedOn ~ .admin-field__error', 'Saisir la date de l\'avis.');
        self::assertSelectorTextContains('main', 'Saisir le texte de l\'avis.');
        self::assertSame(0, $this->em()->getRepository(Review::class)->count([]));
    }

    public function testHideShowAndReorderWithoutDelete(): void
    {
        $this->create('Inès B.', '5', 'Parfait.', '2026-09-12');
        $this->create('Karim D.', '4', 'Bien.', '2026-08-01');
        $crawler = $this->client->request('GET', '/admin/avis');
        self::assertStringNotContainsStringIgnoringCase('supprimer', $crawler->filter('main')->text());

        $this->client->submit($crawler->filter('button[aria-label="Descendre l\'avis de Inès B., 12/09/2026"]')->form());
        self::assertResponseRedirects('/admin/avis');
        $crawler = $this->client->followRedirect();
        self::assertSame(['Karim D.', 'Inès B.'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));

        $this->client->submit($crawler->filter('button[aria-label="Masquer l\'avis de Inès B., 12/09/2026"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Masqué', $crawler->filter('.admin-list__row')->last()->text());
    }

    private function create(string $author, string $rating, string $text, string $date): void
    {
        $this->client->request('GET', '/admin/avis/ajouter');
        $this->client->submitForm('Enregistrer', ['review[author]' => $author, 'review[rating]' => $rating, 'review[text]' => $text, 'review[reviewedOn]' => $date]);
    }
}
