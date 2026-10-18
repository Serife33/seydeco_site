<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\Slot;
use App\Tests\Double\FakeDistanceCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Stories 3.9 + 3.10 : Finaliser, étapes Date et mode puis Créneaux. Les saisies vivent dans le navigateur (vérification manuelle) ;
 * le serveur revérifie la date (24 h), la livraison, l'installation et la période contre les créneaux ouverts.
 */
final class FinalizeTest extends WebTestCase
{
    private const array SETTINGS = [
        'quote_online' => '1',
        'depot_address' => '12 rue du Dépôt, 33530 Bassens',
        'delivery_price_per_km' => '100',
        'delivery_min_chairs' => '80',
        'delivery_max_km' => '70',
        'truck_capacity' => '170',
        'cleaning_fee' => '50',
        'pickup_terms' => '',
        'fuel_terms' => 'Tarif révisable selon le prix du carburant.',
        'whatsapp_phone' => '06 12 34 56 78',
        'slot_window_days' => '2',
        'slot_window_small_days' => '1',
        'slot_window_small_chairs' => '50',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['related_product', 'pack_item', 'pack', 'price_tier', 'product_photo', 'product_color', 'product', 'category', 'distance_cache', 'slot', 'depot_closure'] as $table) {
            $this->db->executeStatement("TRUNCATE TABLE $table");
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (self::SETTINGS as $name => $value) {
            $this->saved[$name] = (string) $this->db->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
            $this->setting($name, $value);
        }
        $container->get('cache.rate_limiter_dbal')->clear();
        FakeDistanceCalculator::reset();
        $this->em = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setting($name, $value);
        }
        parent::tearDown();
    }

    public function testQuoteHasFinalizeButtonUnderConditions(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);

        $crawler = $this->client->request('GET', '/mon-devis/lignes?l[]='.$chaise->getId().'::100');

        self::assertResponseIsSuccessful();
        $button = $crawler->filter('.conditions-panel + .quote-finalize a.button-primary');
        self::assertSame(['Finaliser ma demande', '/mon-devis/finaliser'], [$button->text(), $button->attr('href')]);
    }

    public function testFinalizeShowsFirstStepOnlyWithoutBarNorAmounts(): void
    {
        $crawler = $this->client->request('GET', '/mon-devis/finaliser');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorTextSame('[data-finalize-target="stepLabel"]', 'Étape 1 sur 4 · Date et mode');
        self::assertSame('width: 25%', $crawler->filter('.stepper__bar span')->attr('style'));
        $steps = $crawler->filter('[data-finalize-target="step"]');
        self::assertSame([['Date et mode', null], ['Créneaux', ''], ['Coordonnées', ''], ['Récapitulatif et conditions', '']], $steps->each(fn (Crawler $s) => [$s->attr('data-title'), $s->attr('hidden')]));
        // Pas de Barre d'estimation ; la sélection est lue par le contrôleur selection
        self::assertCount(0, $crawler->filter('.estimate-bar'));
        $root = $crawler->filter('[data-controller="finalize"]');
        self::assertSame(['body', '/mon-devis', '/estimation'], [$root->attr('data-finalize-selection-outlet'), $root->attr('data-finalize-quote-url-value'), $root->attr('data-finalize-estimate-url-value')]);
        self::assertStringNotContainsString('€ ', $crawler->filter('[data-finalize-target="summary"]')->text());

        $date = $crawler->filter('input#f-date');
        self::assertSame(['date', 'f-date-erreur'], [$date->attr('type'), $date->attr('aria-describedby')]);
        self::assertSame(['Retrait au dépôt', 'Livraison en bas de porte'], $crawler->filter('.mode-option__title')->each(fn (Crawler $n) => $n->text()));
        self::assertSame(['pickup', 'delivery'], $crawler->filter('input[name="mode"]')->each(fn (Crawler $n) => $n->attr('value')));
        self::assertSame('street-address', $crawler->filter('input#f-address')->attr('autocomplete'));
        // Réglage du retrait vide : rappel par défaut
        self::assertSelectorTextSame('[data-finalize-target="pickupNote"]', 'Prévoyez un véhicule adapté, des sangles et quelqu\'un sur place pour charger.');
        self::assertSelectorTextContains('.finalize-delivery', 'Tarif révisable selon le prix du carburant. Péages éventuels ajoutés par Serife.');
        self::assertSelectorTextContains('.finalize-cleaning', "Je rends les chaises sans les nettoyer (+0,50\u{A0}€ par chaise)");
        self::assertSelectorTextSame('[data-finalize-target="cleaningNote"]', 'Les chaises sont à rendre propres.');
        self::assertSelectorTextContains('[data-title="Créneaux"]', 'Le choix d\'un créneau ne garantit pas la disponibilité : Serife vérifie et vous recontacte.');
        self::assertSelectorTextContains('[data-finalize-target="noSlot"]', 'Aucun créneau d\'ouverture autour de cette date. Écrivez-nous sur WhatsApp.');
        self::assertStringStartsWith('https://wa.me/33612345678', (string) $crawler->filter('[data-finalize-target="noSlot"] a')->attr('href'));
        self::assertSame(['pickupSlot', 'deliveryDate', 'collectDate', 'returnSlot'], $crawler->filter('.slot-group')->each(fn (Crawler $g) => $g->attr('data-name')));
        self::assertSelectorTextContains('[data-name="deliveryDate"]', 'L\'horaire se fixe avec Serife.');

        // Réglage du retrait rempli : son texte ; supplément vide : pas d'option sans nettoyage
        $this->setting('pickup_terms', 'Venez avec un utilitaire.');
        $this->setting('cleaning_fee', '');
        $this->client->request('GET', '/mon-devis/finaliser');
        self::assertSelectorTextSame('[data-finalize-target="pickupNote"]', 'Venez avec un utilitaire.');
        self::assertSelectorNotExists('.finalize-cleaning');
    }

    public function testClosedQuoteSaysSo(): void
    {
        $this->setting('quote_online', '0');

        $this->client->request('GET', '/mon-devis/finaliser');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.finalize', 'Le devis en ligne est momentanément fermé.');
        self::assertSelectorNotExists('[data-controller="finalize"]');
    }

    public function testEventDateMustBeAtLeastTomorrowInParis(): void
    {
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $lines = [['productId' => $chaise->getId(), 'quantity' => 100]];
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));

        foreach (['-1 day', 'today'] as $day) {
            $data = $this->estimate(['lines' => $lines, 'date' => $today->modify($day)->format('Y-m-d')]);
            self::assertSame('Choisissez une date au moins 24 h à l\'avance.', $data['dateError'], $day);
            self::assertNull($data['slots']);
        }
        self::assertSame('Saisissez une date valide, par exemple 14/06/2027.', $this->estimate(['lines' => $lines, 'date' => '31/12/2030'])['dateError']);
        $data = $this->estimate(['lines' => $lines, 'date' => $today->modify('+1 day')->format('Y-m-d')]);
        self::assertNull($data['dateError']);
        self::assertSame(['pickup' => [], 'return' => []], $data['slots']);
        // Sans date (Mon devis) : rien à vérifier
        self::assertNull($this->estimate(['lines' => $lines])['dateError']);
    }

    public function testDeliveryOptionReasonsInstallationAndCleaning(): void
    {
        $chaises = $this->category('Chaises', PricingType::Chairs);
        $chaises->setInstallPrice(20);
        $tables = $this->category('Tables', PricingType::Tables);
        $chaise = $this->product($chaises, 'Chaise', [15 => 350]);
        $table = $this->product($tables, 'Table', [1 => 1500]);
        $lines = [['productId' => $chaise->getId(), 'quantity' => 60], ['productId' => $table->getId(), 'quantity' => 2]];

        // En retrait, la livraison est déjà jugée : raison chiffrée
        $data = $this->estimate(['lines' => $lines, 'mode' => 'pickup']);
        self::assertSame([['delivery_min_chairs', 'Livraison dès 80 chaises : vous en avez 60.']], array_map(fn (array $r) => [$r['type'], $r['message']], $data['deliveryReasons']));
        self::assertSame(60, $data['chairs']);
        self::assertSame([['label' => 'Chaises', 'text' => "0,20\u{A0}€ l'unité"], ['label' => 'Tables', 'text' => 'tarif sur demande']], $data['installation']);
        // Installation demandée en retrait : refusée par le serveur, aucune ligne
        $data = $this->estimate(['lines' => $lines, 'mode' => 'pickup', 'options' => ['installation' => true, 'cleaning' => true]]);
        self::assertSame(['product', 'product', 'cleaning'], array_column($data['lines'], 'type'));
        self::assertSame(['Nettoyage des chaises', 3000], [$data['lines'][2]['label'], $data['lines'][2]['amount']]);
        self::assertContains('installation_pickup', array_column($data['reasons'], 'type'));

        // Livraison à 84 km : raison chiffrée ; installation sur une ligne à part
        FakeDistanceCalculator::$meters = 83_500;
        $lines[0]['quantity'] = 100;
        $data = $this->estimate(['lines' => $lines, 'mode' => 'delivery', 'address' => '1 rue Loin, Agen', 'options' => ['installation' => true]]);
        self::assertSame(['Livraison jusqu\'à 70 km : votre lieu est à 84 km.'], array_column($data['deliveryReasons'], 'message'));
        self::assertSame([['delivery', 'Livraison', 84], ['installation', 'Installation', null], ['installation', 'Installation : tarif sur demande', null]], array_map(fn (array $l) => [$l['type'], $l['label'], $l['km'] ?? null], \array_slice($data['lines'], 2)));
        self::assertSame(2000, $data['lines'][3]['amount']);
    }

    public function testSlotsAroundEventAndPeriodCheckedByServer(): void
    {
        foreach (range(1, 7) as $weekday) {
            $this->slot($weekday, '09:30', '12:00');
        }
        $chaise = $this->product($this->category('Chaises', PricingType::Chairs), 'Chaise', [15 => 350]);
        $event = new \DateTimeImmutable('today +20 days', new \DateTimeZone('Europe/Paris'));
        $day = fn (string $modify) => $event->modify($modify)->format('Y-m-d');
        $request = fn (int $chairs, array $period = [], string $mode = 'pickup', array $options = []) => $this->estimate([
            'lines' => [['productId' => $chaise->getId(), 'quantity' => $chairs]], 'date' => $event->format('Y-m-d'), 'mode' => $mode,
            'address' => 'pickup' === $mode ? '' : '3 place de la Bourse, Bordeaux', 'options' => $options, 'period' => $period,
        ]);

        // 2 jours avant / après dès 50 chaises, 1 jour en dessous ; aucun nombre de places
        $data = $request(50);
        self::assertSame([$day('-2 days'), $day('-1 day'), $day('+0 day')], array_column($data['slots']['pickup'], 'date'));
        self::assertSame([$day('+0 day'), $day('+1 day'), $day('+2 days')], array_column($data['slots']['return'], 'date'));
        $label = new \IntlDateFormatter('fr_FR', \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'Europe/Paris', null, 'EEEE d MMMM');
        self::assertSame(['date' => $day('+0 day'), 'label' => mb_ucfirst((string) $label->format($event)), 'slots' => [['value' => $day('+0 day').'T09:30', 'label' => '9 h 30 – 12 h']]], $data['slots']['pickup'][2]);
        self::assertSame([$day('-1 day'), $day('+0 day')], array_column($request(49)['slots']['pickup'], 'date'));

        // Fenêtre réglée dans Réglages → Devis
        $this->setting('slot_window_days', '3');
        self::assertCount(4, $request(120)['slots']['return']);

        // Fermeture exceptionnelle : jour absent
        $this->db->executeStatement('INSERT INTO depot_closure (starts_on, ends_on, reason) VALUES (?, ?, ?)', [$day('-1 day'), $day('-1 day'), 'Congés']);
        self::assertSame([$day('-3 days'), $day('-2 days'), $day('+0 day')], array_column($request(120)['slots']['pickup'], 'date'));

        // Période revérifiée : convertie en heure de Paris, ou refusée avec la règle
        $ok = $request(120, ['pickupSlot' => $day('-2 days').'T09:30', 'returnSlot' => $day('+1 day').'T09:30'])['period'];
        $paris = new \DateTimeZone('Europe/Paris');
        self::assertSame([
            (new \DateTimeImmutable($day('-2 days').' 09:30', $paris))->format(\DATE_ATOM),
            (new \DateTimeImmutable($day('+1 day').' 12:00', $paris))->format(\DATE_ATOM),
        ], [$ok['startAt'], $ok['endAt']]);
        self::assertSame(['field' => 'pickupSlot', 'error' => 'Choisissez un créneau de retrait proposé : au plus tard le jour de l\'événement.'], $request(120, ['pickupSlot' => $day('+1 day').'T09:30', 'returnSlot' => $day('+1 day').'T09:30'])['period']);
        self::assertSame('pickupSlot', $request(120, ['pickupSlot' => $day('-1 day').'T09:30'])['period']['field']);
        self::assertSame('returnSlot', $request(120, ['pickupSlot' => $day('+0 day').'T09:30', 'returnSlot' => $day('+0 day').'T09:30'])['period']['field']);

        // Livraison avec récupération : journées entières ; sans récupération, retour au dépôt dans la grille
        $collect = $request(120, ['deliveryDate' => $day('+0 day'), 'collectDate' => $day('+1 day')], 'delivery', ['pickup' => true])['period'];
        self::assertSame([
            (new \DateTimeImmutable($day('+0 day').' 00:00', $paris))->format(\DATE_ATOM),
            (new \DateTimeImmutable($day('+1 day').' 23:59:59', $paris))->format(\DATE_ATOM),
        ], [$collect['startAt'], $collect['endAt']]);
        self::assertSame(['field' => 'returnSlot', 'error' => 'Choisissez un créneau de retour au dépôt.'], $request(120, ['deliveryDate' => $day('+0 day'), 'collectDate' => $day('+1 day')], 'delivery')['period']);
    }

    /** @return array<string, mixed> */
    private function estimate(array $body): array
    {
        $this->client->request('POST', '/estimation', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body));
        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function setting(string $name, string $value): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
    }

    private function slot(int $weekday, string $start, string $end): void
    {
        $slot = new Slot();
        $slot->setWeekday($weekday);
        $slot->setStartsAt(new \DateTimeImmutable("1970-01-01 $start"));
        $slot->setEndsAt(new \DateTimeImmutable("1970-01-01 $end"));
        $this->em->persist($slot);
        $this->em->flush();
    }

    private function category(string $name, PricingType $type = PricingType::Other): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(mb_strtolower($name));
        $category->setPosition(1);
        $category->setPricingType($type);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    /** @param array<int, int> $tiers quantité minimale → prix en centimes */
    private function product(Category $category, string $name, array $tiers): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower($name).'-'.bin2hex(random_bytes(3)));
        $product->setCategory($category);
        $product->setVisible(true);
        $product->setMinQty(array_key_first($tiers));
        foreach ($tiers as $qty => $price) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
