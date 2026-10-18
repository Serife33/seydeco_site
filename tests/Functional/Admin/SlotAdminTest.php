<?php

namespace App\Tests\Functional\Admin;

use App\Entity\DepotClosure;
use App\Entity\Slot;
use App\Service\SlotProvider;
use Symfony\Component\DomCrawler\Crawler;

/** Story 3.3 : Réglages → Créneaux au dépôt et fermetures exceptionnelles. */
final class SlotAdminTest extends AdminTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->createQuery('DELETE FROM '.Slot::class)->execute();
        $em->createQuery('DELETE FROM '.DepotClosure::class)->execute();
        $this->loginFully();
    }

    private function addSlot(int $weekday, string $start, string $end): Crawler
    {
        $this->client->request('GET', '/admin/reglages/creneaux/ajouter?jour='.$weekday);

        return $this->client->submitForm('Enregistrer', ['slot[startsAt]' => $start, 'slot[endsAt]' => $end]);
    }

    public function testScreenShowsEveryDayClosedByDefault(): void
    {
        $crawler = $this->client->request('GET', '/admin/reglages');
        $crawler = $this->client->click($crawler->selectLink('Créneaux au dépôt')->link());

        self::assertSelectorTextSame('h1', 'Créneaux au dépôt');
        self::assertSelectorTextContains('main', 'Une seule grille pour le retrait et le retour, sans limite de clientes par créneau. Les horaires de livraison se fixent dans chaque demande.');
        self::assertSame(['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'], $crawler->filter('.admin-day__name')->each(fn (Crawler $n) => $n->text()));
        self::assertSame(array_fill(0, 7, 'Fermé'), $crawler->filter('.admin-day__slots')->each(fn (Crawler $n) => $n->text()));
        self::assertCount(7, $crawler->filter('.admin-day')->selectLink('Ajouter une plage'));
        self::assertSelectorTextContains('main', 'Aucune fermeture.');
    }

    public function testAddAndRemoveSlot(): void
    {
        $this->addSlot(3, '14:00', '16:30');
        self::assertResponseRedirects('/admin/reglages/creneaux');
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.admin-notice', 'Plage ajoutée.');
        self::assertStringStartsWith('14 h – 16 h 30', $crawler->filter('.admin-day')->eq(2)->filter('.admin-slot')->text());
        self::assertSame('Fermé', $crawler->filter('.admin-day__slots')->eq(0)->text());

        $this->client->submit($crawler->filter('button[aria-label="Retirer la plage Mercredi 14 h – 16 h 30"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertSame('Fermé', $crawler->filter('.admin-day__slots')->eq(2)->text());
    }

    public function testInvalidSlotsAreRefused(): void
    {
        $this->addSlot(1, '16:00', '14:00');
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('main', 'L\'heure de fin doit être après l\'heure de début.');

        $this->addSlot(1, '14:00', '14:00');
        self::assertSelectorTextContains('main', 'L\'heure de fin doit être après l\'heure de début.');

        $this->addSlot(1, '14:00', '16:00');
        $this->addSlot(1, '15:00', '18:00');
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('main', 'Cette plage chevauche celle de lundi (14 h – 16 h).');

        // Bord à bord ou un autre jour : accepté
        $this->addSlot(1, '16:00', '18:00');
        self::assertResponseRedirects();
        $this->addSlot(2, '15:00', '18:00');
        self::assertResponseRedirects();
        self::assertSame(3, static::getContainer()->get('doctrine')->getRepository(Slot::class)->count([]));
    }

    public function testClosuresAreListedRefusedAndRemoved(): void
    {
        $this->client->request('GET', '/admin/reglages/creneaux/fermetures/ajouter');
        $this->client->submitForm('Enregistrer', ['depot_closure[startsOn]' => '2026-12-26', 'depot_closure[endsOn]' => '2026-12-24']);
        self::assertResponseIsUnprocessable();
        self::assertSelectorTextContains('main', 'La date de fin ne peut pas être avant la date de début.');

        $this->client->submitForm('Enregistrer', ['depot_closure[startsOn]' => '2026-12-24', 'depot_closure[endsOn]' => '2026-12-26', 'depot_closure[reason]' => 'Noël']);
        $this->client->request('GET', '/admin/reglages/creneaux/fermetures/ajouter');
        $this->client->submitForm('Enregistrer', ['depot_closure[startsOn]' => '2020-05-01']);
        $crawler = $this->client->followRedirect();

        self::assertSame(['Le 01/05/2020', 'Du 24/12/2026 au 26/12/2026 Noël'], $crawler->filter('section[aria-labelledby="s-closures"] .admin-list__main')->each(fn (Crawler $n) => preg_replace('/\s+/', ' ', trim($n->text()))));

        // Une fermeture passée peut être retirée
        $this->client->submit($crawler->filter('button[aria-label="Retirer la fermeture le 01/05/2020"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-notice', 'Fermeture retirée.');
        self::assertSelectorTextNotContains('main', '01/05/2020');
    }

    public function testSlotProviderAppliesOneDayAndPeriodClosures(): void
    {
        // Une plage chaque jour : seules les fermetures vident une date
        foreach (range(1, 7) as $weekday) {
            $this->addSlot($weekday, '10:00', '12:00');
        }
        $this->client->request('GET', '/admin/reglages/creneaux/fermetures/ajouter');
        $this->client->submitForm('Enregistrer', ['depot_closure[startsOn]' => '2026-12-24', 'depot_closure[endsOn]' => '2026-12-26']);
        $this->client->request('GET', '/admin/reglages/creneaux/fermetures/ajouter');
        $this->client->submitForm('Enregistrer', ['depot_closure[startsOn]' => '2026-12-31']);

        $doctrine = static::getContainer()->get('doctrine');
        $provider = new SlotProvider($doctrine->getRepository(Slot::class), $doctrine->getRepository(DepotClosure::class));
        $open = fn (string $day) => \count($provider->openSlots(new \DateTimeImmutable($day)));
        self::assertSame([1, 0, 0, 0, 1, 0, 1], array_map($open, ['2026-12-23', '2026-12-24', '2026-12-25', '2026-12-26', '2026-12-27', '2026-12-31', '2027-01-01']));
    }
}
