<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Announcement;

/** Story 2.7 côté admin : Plus → Contenus → Annonces. */
final class AnnouncementAdminTest extends CatalogTestCase
{
    public function testCreateAnnouncementsWithStatusFromMoreContents(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        $this->client->click($crawler->selectLink('Annonces')->link());
        self::assertSelectorTextSame('h1', 'Annonces');
        self::assertSelectorTextContains('main', 'Aucune annonce.');

        $this->create(' Nouveau : les arches fleuries ', '/location', $this->day('-1 day'), $this->day('+1 day'));
        self::assertResponseRedirects('/admin/annonces');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.admin-notice', 'Annonce enregistrée.');
        $this->create('Fermé en août', 'seydeco.fr/infos', $this->day('+3 days'), $this->day('+10 days'));
        $this->create('Promo de printemps', '', $this->day('-10 days'), $this->day('-1 day'));
        $this->create('Aujourd\'hui seulement', '', $this->day('today'), $this->day('today'));

        $items = $this->em()->getRepository(Announcement::class)->findBy([], ['position' => 'ASC']);
        self::assertSame(
            [['Nouveau : les arches fleuries', '/location', 1, true], ['Fermé en août', 'https://seydeco.fr/infos', 2, true], ['Promo de printemps', null, 3, true], ['Aujourd\'hui seulement', null, 4, true]],
            array_map(fn (Announcement $a) => [$a->getText(), $a->getLink(), $a->getPosition(), $a->isVisible()], $items),
        );
        $crawler = $this->client->request('GET', '/admin/annonces');
        self::assertSame(['En cours', 'À venir', 'Terminée', 'En cours'], $crawler->filter('.admin-list__meta')->each(fn ($n) => explode(' · ', $n->text())[0]));
    }

    public function testInvalidAnnouncementIsRefusedWithMessages(): void
    {
        $this->create('', 'javascript:alert(1)', $this->day('+2 days'), $this->day('+1 day'));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#announcement_text ~ .admin-field__error', 'Saisir le texte de l\'annonce.');
        self::assertSelectorTextContains('#announcement_link ~ .admin-field__error', 'Saisir une adresse du site');
        self::assertSelectorTextSame('#announcement_endsOn ~ .admin-field__error', 'La date de fin ne peut pas être avant la date de début.');

        $this->create(str_repeat('a', 121), '//evil.example', $this->day('today'), '');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#announcement_text ~ .admin-field__error', 'Le texte ne doit pas dépasser 120 caractères.');
        self::assertSelectorTextContains('#announcement_link ~ .admin-field__error', 'Saisir une adresse du site');
        self::assertSelectorTextSame('#announcement_endsOn ~ .admin-field__error', 'Saisir la date de fin.');
        $this->create('Texte', '/\\evil.example', $this->day('today'), $this->day('today'));
        self::assertSelectorTextContains('#announcement_link ~ .admin-field__error', 'Saisir une adresse du site');
        self::assertSame(0, $this->em()->getRepository(Announcement::class)->count([]));
    }

    public function testHideShowAndReorderWithoutDelete(): void
    {
        $this->create('Première', '', $this->day('today'), $this->day('+1 day'));
        $this->create('Deuxième', '', $this->day('today'), $this->day('+1 day'));
        $crawler = $this->client->request('GET', '/admin/annonces');
        self::assertStringNotContainsStringIgnoringCase('supprimer', $crawler->filter('main')->text());

        $this->client->submit($crawler->filter('button[aria-label="Descendre Première"]')->form());
        self::assertResponseRedirects('/admin/annonces');
        $crawler = $this->client->followRedirect();
        self::assertSame(['Deuxième', 'Première'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));

        $this->client->submit($crawler->filter('button[aria-label="Masquer Première"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Masquée', $crawler->filter('.admin-list__row')->last()->text());
        $crawler = $this->client->request('GET', '/location');
        self::assertSame(['Deuxième'], $crawler->filter('.announcement-bar__list:not([aria-hidden]) .announcement-bar__item')->each(fn ($n) => $n->text()));
    }

    private function day(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier))->format('Y-m-d');
    }

    private function create(string $text, string $link, string $start, string $end): void
    {
        $this->client->request('GET', '/admin/annonces/ajouter');
        $this->client->submitForm('Enregistrer', ['announcement[text]' => $text, 'announcement[link]' => $link, 'announcement[startsOn]' => $start, 'announcement[endsOn]' => $end]);
    }
}
