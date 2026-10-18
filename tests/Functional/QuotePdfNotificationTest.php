<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\ProductPhoto;
use App\Entity\QuoteRequest;
use App\Entity\Slot;
use App\Tests\Double\FakeDistanceCalculator;
use App\Tests\Functional\Admin\AdminTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * Stories 3.13 + 3.14 : devis PDF par le jeton secret (contenu figé, miniatures locales), email à Serife,
 * pastille des nouvelles demandes, Plus → Demandes et fiche en lecture.
 */
final class QuotePdfNotificationTest extends AdminTestCase
{
    private const string IBAN = 'FR7630006000011234567890189';
    private const array SETTINGS = [
        'quote_online' => '1',
        'notification_email' => 'serife@seydeco.example',
        'depot_address' => '12 rue du Dépôt, 33530 Bassens',
        'address' => '12 rue du Dépôt, 33530 Bassens',
        'phone' => '05 56 00 00 00',
        'email' => 'contact@seydeco.example',
        'siret' => '98765432100011',
        'delivery_price_per_km' => '100',
        'delivery_min_chairs' => '80',
        'delivery_max_km' => '70',
        'truck_capacity' => '170',
        'cleaning_fee' => '',
        'deposit_percent' => '50',
        'deposit_amount' => '30000',
        'return_terms' => 'Rendre le matériel propre.',
        'pickup_terms' => 'Véhicule adapté.',
        'fuel_terms' => 'Tarif révisable selon le carburant.',
        'payment_methods' => 'virement,especes,wero',
        'iban' => self::IBAN,
        'bic' => 'AGRIFRPP',
        'bank_holder' => 'Titulaire Banque',
        'slot_window_days' => '2',
        'slot_window_small_days' => '1',
        'slot_window_small_chairs' => '50',
    ];

    private EntityManagerInterface $em;
    private Connection $db;
    /** @var array<string, string> */
    private array $saved = [];
    private Product $chaise;
    private Product $table;
    private \DateTimeImmutable $event;

    protected function setUp(): void
    {
        parent::setUp();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['quote_line', 'quote_version', 'quote_request', 'document_counter', 'stat_event', 'related_product', 'pack_item', 'pack', 'price_tier', 'product_photo', 'product_color', 'product', 'category', 'distance_cache', 'slot', 'depot_closure'] as $table) {
            $this->db->executeStatement("TRUNCATE TABLE $table");
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (self::SETTINGS as $name => $value) {
            $this->saved[$name] = (string) $this->db->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
            $this->setting($name, $value);
        }
        FakeDistanceCalculator::reset();
        $this->em = $container->get(EntityManagerInterface::class);
        $uploads = $container->getParameter('app.upload_dir');
        (new Filesystem())->mkdir($uploads);

        foreach (range(1, 7) as $weekday) {
            $slot = new Slot();
            $slot->setWeekday($weekday);
            $slot->setStartsAt(new \DateTimeImmutable('1970-01-01 09:30'));
            $slot->setEndsAt(new \DateTimeImmutable('1970-01-01 12:00'));
            $this->em->persist($slot);
        }
        $chairs = $this->category('Chaises', PricingType::Chairs);
        $tables = $this->category('Tables', PricingType::Tables);
        // Photo présente sur le disque pour la chaise, photo manquante pour la table
        $image = imagecreatetruecolor(120, 120);
        imagejpeg($image, $uploads.'/chaise-test.jpg');
        $this->chaise = $this->product($chairs, 'Chaise Napoléon', [15 => 350], 'chaise-test.jpg', breakage: 2500, loss: 4000);
        $this->table = $this->product($tables, 'Table ronde', [1 => 1200], 'absente.jpg');
        $this->event = new \DateTimeImmutable('today +20 days', new \DateTimeZone('Europe/Paris'));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setting($name, $value);
        }
        parent::tearDown();
    }

    public function testRequestNotifiesSerifeAndPdfIsDownloadedByToken(): void
    {
        $this->submit(['companyName' => 'Salle des fêtes SAS', 'siret' => '12345678900012', 'billingAddress' => '1 rue de la Facture, Bordeaux']);

        // Un seul email, vers l'adresse de notification (jamais vers Inès)
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        $quote = $this->onlyRequest();
        self::assertSame(['serife@seydeco.example'], array_map(fn ($a) => $a->getAddress(), $email->getTo()));
        self::assertSame('Nouvelle demande '.$quote->getNumber().' — Inès Martin', $email->getSubject());
        $text = (string) $email->getTextBody();
        self::assertStringContainsString('Date de l\'événement : '.$this->event->format('d/m/Y'), $text);
        self::assertStringContainsString('Mode : Retrait et retour au dépôt', $text);
        self::assertStringContainsString('Chaises : 100', $text);
        self::assertStringContainsString("Total estimé : 350,00\u{A0}€", $text);
        self::assertStringContainsString('http://localhost/admin/demandes/'.$quote->getId(), $text);
        self::assertStringContainsString('/admin/demandes/'.$quote->getId(), (string) $email->getHtmlBody());
        self::assertStringNotContainsString(self::IBAN, $text.$email->getHtmlBody());

        // Confirmation : bouton PDF par le jeton secret, jamais par l'id ou le numéro
        $crawler = $this->client->request('GET', '/mon-devis/confirmation');
        $button = $crawler->filter('.quote-confirmation a.button-primary');
        self::assertSame('Télécharger mon devis (PDF)', $button->text());
        self::assertSame('/devis/'.$quote->getToken().'/pdf', $button->attr('href'));

        $this->client->request('GET', '/devis/'.$quote->getToken().'/pdf');
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertSame('attachment; filename='.$quote->getNumber().'.pdf', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
        self::assertStringNotContainsString(self::IBAN, (string) $response->getContent());

        // Jeton inconnu ou id : 404
        $this->client->request('GET', '/devis/'.str_repeat('0', 32).'/pdf');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/devis/'.$quote->getId().'/pdf');
        self::assertResponseStatusCodeSame(404);
    }

    public function testPdfContentIsFrozenAndHasNoBankDetails(): void
    {
        FakeDistanceCalculator::$meters = 10_400;
        $this->submit(['companyName' => 'Salle des fêtes SAS', 'siret' => '12345678900012', 'billingAddress' => '1 rue de la Facture, Bordeaux'], [
            'lines' => [['productId' => $this->chaise->getId(), 'quantity' => 100], ['productId' => $this->table->getId(), 'quantity' => 2]],
            'mode' => 'delivery', 'address' => '3 place de la Bourse, Bordeaux', 'options' => ['pickup' => true],
            'period' => ['deliveryDate' => $this->day('-1 day'), 'collectDate' => $this->day('+1 day')],
        ]);
        $quote = $this->onlyRequest();
        $html = $this->html($quote);
        $page = new Crawler($html);
        $text = $this->text($html);

        foreach ([
            'Devis '.$quote->getNumber(), 'Du '.$quote->getCreatedAt()->format('d/m/Y'), 'Sous réserve de disponibilité',
            '12 rue du Dépôt, 33530 Bassens', 'Tél. 05 56 00 00 00', 'contact@seydeco.example', 'SIRET 98765432100011',
            'Inès Martin', '06 12 34 56 78', 'ines@example.com', 'Salle des fêtes SAS', '12345678900012', '1 rue de la Facture, Bordeaux',
            'Date '.$this->event->format('d/m/Y'), 'Livraison et récupération par Serife',
            'Livraison Le '.(new \DateTimeImmutable($this->day('-1 day')))->format('d/m/Y').', l\'horaire se fixe avec Serife',
            'Récupération Le '.(new \DateTimeImmutable($this->day('+1 day')))->format('d/m/Y'), '3 place de la Bourse, Bordeaux',
            "Chaise Napoléon 100 3,50\u{A0}€ 350,00\u{A0}€", "Table ronde 2 12,00\u{A0}€ 24,00\u{A0}€",
            'Livraison 11 km, 1 voyage', 'Péages éventuels ajoutés par Serife',
            "Total 414,00\u{A0}€", "Acompte 50 % 207,00\u{A0}€", "Reste à payer 207,00\u{A0}€", "Remise tables avec chaises -4,00\u{A0}€", "Caution 300,00\u{A0}€",
            'Rendre le matériel propre.', 'Tarif révisable selon le carburant.', 'Les chaises sont rendues propres.',
            "Chaise Napoléon : casse 25,00\u{A0}€ par unité, perte 40,00\u{A0}€ par unité",
            'Moyens acceptés : Virement, Espèces, Wero. Pas de paiement en ligne.',
        ] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        foreach ([self::IBAN, 'AGRIFRPP', 'Titulaire Banque', 'IBAN', 'virement bancaire'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $html);
        }
        // Miniatures : fichier local en data:, photo manquante = cadre vide ; aucune URL distante
        $thumbs = $page->filter('.thumb');
        self::assertStringStartsWith('data:image/jpeg;base64,', $thumbs->eq(0)->filter('img')->attr('src'));
        self::assertCount(0, $thumbs->eq(1)->filter('img'));
        self::assertDoesNotMatchRegularExpression('/(src|href)="(https?:)?\/\//', $html);

        // Réglages et prix modifiés après coup : le devis reste identique (contexte figé)
        foreach (['deposit_percent' => '30', 'deposit_amount' => '50000', 'return_terms' => 'Autre texte.', 'payment_methods' => 'especes', 'address' => '1 autre adresse', 'phone' => '07 00 00 00 00'] as $name => $value) {
            $this->setting($name, $value);
        }
        $this->db->executeStatement('UPDATE price_tier SET price = price * 2');
        $this->db->executeStatement('UPDATE product SET breakage_price = 9900');
        self::assertSame($html, $this->html($this->onlyRequest()));
        $this->client->request('GET', '/devis/'.$quote->getToken().'/pdf');
        self::assertResponseIsSuccessful();
    }

    public function testUnknownDistanceAndInstallationOnRequestAreWrittenAsIs(): void
    {
        FakeDistanceCalculator::$meters = null;
        $this->submit([], [
            'mode' => 'delivery', 'address' => 'Adresse introuvable', 'options' => ['installation' => true],
            'period' => ['deliveryDate' => $this->day('-1 day'), 'returnSlot' => $this->day('+1 day').'T09:30'],
        ]);
        $quote = $this->onlyRequest();
        $text = $this->text($this->html($quote));

        self::assertStringContainsString('Livraison : à confirmer par Serife', $text);
        self::assertStringContainsString('Installation : tarif sur demande', $text);
        self::assertStringContainsString("Total hors livraison 350,00\u{A0}€", $text);
        self::assertStringContainsString('Retour au dépôt Le '.(new \DateTimeImmutable($this->day('+1 day')))->format('d/m/Y').', avant 12 h 00', $text);
        self::assertStringNotContainsString("0,00\u{A0}€ ", preg_replace('/\d0,00/u', '', $text));
        // Email : livraison à confirmer
        self::assertStringContainsString('Livraison à confirmer', (string) self::getMailerMessage()->getTextBody());
    }

    public function testMailFailureKeepsTheRequestAndLogsWithoutPersonalData(): void
    {
        // Adresse refusée par Symfony Mailer : même chemin qu'un SMTP en panne
        $this->setting('notification_email', 'pas une adresse');
        $this->client->disableReboot();

        $this->submit();

        self::assertEmailCount(0);
        $quote = $this->onlyRequest();
        $records = array_filter(
            $this->client->getContainer()->get('monolog.handler.testing')->getRecords(),
            fn ($r) => str_contains($r->message, 'nouvelle demande'),
        );
        self::assertCount(1, $records);
        $record = array_values($records)[0];
        self::assertSame('WARNING', $record->level->getName());
        $logged = $record->message.json_encode($record->context);
        foreach (['Inès', 'Martin', 'ines@example.com', 'pas une adresse', $quote->getNumber()] as $personal) {
            self::assertStringNotContainsString($personal, $logged);
        }
        $this->loginFully();
        $this->client->request('GET', '/admin/demandes');
        self::assertSelectorTextContains('.admin-list', $quote->getNumber());
    }

    public function testBadgeListAndReadOnlyCard(): void
    {
        $this->loginFully();
        $crawler = $this->client->request('GET', '/admin');
        self::assertCount(0, $crawler->filter('.admin-nav .admin-count'));
        $crawler = $this->client->request('GET', '/admin/demandes');
        self::assertSelectorTextSame('main h1 + p', 'Aucune demande.');

        $this->submit(['access' => '2e étage', 'source' => 'instagram', 'companyName' => 'Salle SAS']);
        $this->submit(['firstName' => 'Lina', 'lastName' => 'Durand']);
        $first = $this->em->getRepository(QuoteRequest::class)->findOneBy(['firstName' => 'Inès']);

        // Pastille sur Accueil : 2 nouvelles demandes
        $crawler = $this->client->request('GET', '/admin/plus');
        self::assertSame('2 nouvelles demandes', $crawler->filter('.admin-nav a[href="/admin"] .admin-count')->text());
        self::assertSame('/admin/demandes', $crawler->filter('.admin-list a')->first()->attr('href'));

        // Liste : plus récente en premier, nom, date d'événement, numéro, montant, statut
        $crawler = $this->client->request('GET', '/admin/demandes');
        $rows = $crawler->filter('.admin-list__row');
        self::assertSame(['Lina Durand', 'Inès Martin'], $rows->filter('.admin-list__name')->each(fn (Crawler $n) => $n->text()));
        self::assertSame('Le '.$this->event->format('d/m/Y').' · '.$first->getNumber()." · 350,00\u{A0}€", $rows->eq(1)->filter('.admin-list__meta')->text());
        self::assertSame('Nouvelle', $rows->eq(1)->filter('.admin-badge')->text());

        // Fiche en lecture
        $crawler = $this->client->request('GET', '/admin/demandes/'.$first->getId());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1.admin-title', 'Inès Martin');
        self::assertSelectorTextSame('.admin-head .admin-badge', 'Nouvelle');
        $text = $crawler->filter('main')->text();
        foreach (['06 12 34 56 78', 'ines@example.com', 'Salle SAS', '2e étage', 'Instagram', 'Retrait et retour au dépôt', 'Chaise Napoléon × 100', "350,00\u{A0}€", "175,00\u{A0}€"] as $expected) {
            self::assertStringContainsString($expected, $text);
        }
        self::assertSame('/devis/'.$first->getToken().'/pdf', $crawler->filter('main a.admin-button')->attr('href'));
        self::assertCount(0, $crawler->filter('main form'));

        // Demande inexistante
        $crawler = $this->client->request('GET', '/admin/demandes/999999');
        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextSame('h1', 'Cette demande n\'existe plus.');
        self::assertSame('/admin/demandes', $crawler->filter('main a')->last()->attr('href'));
    }

    public function testAdminPagesNeedLogin(): void
    {
        $this->client->request('GET', '/admin/demandes');
        self::assertResponseRedirects('/admin/connexion');
    }

    /** HTML du PDF, tel que PdfRenderer le passe à Dompdf. */
    private function html(QuoteRequest $quote): string
    {
        return static::getContainer()->get(Environment::class)->render('pdf/quote.html.twig', ['quote' => $quote, 'version' => $quote->getCurrentVersion()]);
    }

    /** Texte lisible du HTML, cellules séparées par une espace. */
    private function text(string $html): string
    {
        $body = preg_replace('/<style.*<\/style>/s', '', str_replace('<', ' <', $html));

        return trim(preg_replace('/[ \t\r\n]+/', ' ', html_entity_decode(strip_tags($body))));
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $draft
     */
    private function submit(array $fields = [], array $draft = []): void
    {
        $draft += [
            'lines' => [['productId' => $this->chaise->getId(), 'quantity' => 100]],
            'date' => $this->day('+0 day'),
            'mode' => 'pickup',
            'address' => '',
            'options' => [],
            'period' => ['pickupSlot' => $this->day('-1 day').'T09:30', 'returnSlot' => $this->day('+1 day').'T09:30'],
        ];
        $fields += [
            'firstName' => 'Inès', 'lastName' => 'Martin', 'phone' => '06 12 34 56 78', 'email' => 'ines@example.com',
            'acceptTerms' => '1', 'acceptPrivacy' => '1', 'website' => '', 'draft' => json_encode($draft), '_token' => 'csrf-token',
        ];
        static::getContainer()->get('cache.rate_limiter_dbal')->clear();
        $this->client->request('POST', '/mon-devis/envoyer', ['demande' => $fields], server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
    }

    private function onlyRequest(): QuoteRequest
    {
        $this->em->clear();
        $requests = $this->em->getRepository(QuoteRequest::class)->findAll();
        self::assertCount(1, $requests);

        return $requests[0];
    }

    private function day(string $modify): string
    {
        return $this->event->modify($modify)->format('Y-m-d');
    }

    private function setting(string $name, string $value): void
    {
        $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
    }

    private function category(string $name, PricingType $type): Category
    {
        $category = new Category();
        $category->setName($name);
        $category->initSlug(mb_strtolower($name));
        $category->setPosition(1);
        $category->setPricingType($type);
        $this->em->persist($category);

        return $category;
    }

    /** @param array<int, int> $tiers quantité minimale → prix en centimes */
    private function product(Category $category, string $name, array $tiers, string $photo, ?int $breakage = null, ?int $loss = null): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower($name).'-'.bin2hex(random_bytes(3)));
        $product->setCategory($category);
        $product->setVisible(true);
        $product->setMinQty(array_key_first($tiers));
        $product->setBreakagePrice($breakage);
        $product->setLossPrice($loss);
        if (PricingType::Tables === $category->getPricingType()) {
            $product->setPriceWithChairs(1000);
        }
        foreach ($tiers as $qty => $price) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }
        $file = new ProductPhoto();
        $file->setFilename($photo);
        $file->setAlt($name);
        $product->addPhoto($file);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
