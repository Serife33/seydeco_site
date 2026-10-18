<?php

namespace App\Tests\Functional;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\QuoteLineType;
use App\Entity\QuoteRequest;
use App\Entity\Slot;
use App\Entity\StatEvent;
use App\Tests\Double\FakeDistanceCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Stories 3.11 + 3.12 : coordonnées et consentements (étapes 3 et 4 de Finaliser), puis envoi de la demande.
 * Le serveur revalide et recalcule tout ; la demande, sa version, ses lignes, le numéro et la statistique sont créés d'un bloc.
 */
final class QuoteRequestTest extends WebTestCase
{
    private const array SETTINGS = [
        'quote_online' => '1',
        'depot_address' => '12 rue du Dépôt, 33530 Bassens',
        'delivery_price_per_km' => '100',
        'delivery_min_chairs' => '80',
        'delivery_max_km' => '70',
        'truck_capacity' => '170',
        'cleaning_fee' => '50',
        'deposit_percent' => '50',
        'deposit_amount' => '30000',
        'return_terms' => 'Rendre le matériel propre.',
        'payment_methods' => 'virement,especes',
        'iban' => 'FR7630006000011234567890189',
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
    private Product $chaise;
    private \DateTimeImmutable $event;

    protected function setUp(): void
    {
        $this->client = static::createClient();
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
        $container->get('cache.rate_limiter_dbal')->clear();
        FakeDistanceCalculator::reset();
        $this->em = $container->get(EntityManagerInterface::class);

        foreach (range(1, 7) as $weekday) {
            $slot = new Slot();
            $slot->setWeekday($weekday);
            $slot->setStartsAt(new \DateTimeImmutable('1970-01-01 09:30'));
            $slot->setEndsAt(new \DateTimeImmutable('1970-01-01 12:00'));
            $this->em->persist($slot);
        }
        $category = new Category();
        $category->setName('Chaises');
        $category->initSlug('chaises');
        $category->setPosition(1);
        $category->setPricingType(PricingType::Chairs);
        $this->em->persist($category);
        $this->chaise = $this->product($category, 'Chaise Napoléon', [15 => 350], breakage: 2500);
        $this->event = new \DateTimeImmutable('today +20 days', new \DateTimeZone('Europe/Paris'));
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->setting($name, $value);
        }
        parent::tearDown();
    }

    public function testContactAndConsentStepsMarkup(): void
    {
        $crawler = $this->client->request('GET', '/mon-devis/finaliser');

        self::assertResponseIsSuccessful();
        $step = $crawler->filter('[data-title="Coordonnées"]');
        // Obligatoires : libellé au-dessus, autocomplete, message sous le champ relié par aria-describedby
        foreach (['firstName' => ['Prénom', 'given-name'], 'lastName' => ['Nom', 'family-name'], 'phone' => ['Téléphone', 'tel'], 'email' => ['Email', 'email']] as $name => [$label, $autocomplete]) {
            $input = $step->filter("#demande_$name");
            self::assertSame([$autocomplete, 'required', "demande_$name-erreur"], [$input->attr('autocomplete'), $input->attr('required'), $input->attr('aria-describedby')], $name);
            self::assertSame($label, $step->filter("label[for=demande_$name]")->text());
            self::assertSame($name, $step->filter("#demande_$name-erreur")->attr('data-error-for'));
        }
        self::assertSame(QuoteRequest::EMAIL_MISSING, $step->filter('#demande_email')->attr('data-missing'));
        // Facultatifs repliés
        self::assertSame(['Je suis un professionnel', 'Accès particulier', 'Comment nous avez-vous connus ?'], $step->filter('details:not([open]) > summary')->each(fn (Crawler $s) => $s->text()));
        self::assertSame(['companyName', 'siret', 'billingAddress'], $step->filter('details')->eq(0)->filter('[data-error-for]')->each(fn (Crawler $p) => $p->attr('data-error-for')));
        self::assertSame(QuoteRequest::SIRET_INVALID, $step->filter('#demande_siret')->attr('data-invalid'));
        self::assertSame(['', 'Instagram', 'TikTok', 'Google', 'Bouche-à-oreille', 'Lieu partenaire', 'Autre'], $step->filter('#demande_source option')->each(fn (Crawler $o) => $o->attr('value') ? $o->text() : ''));

        // Étape 4 : Encadré Conditions juste avant les deux cases non cochées ; bouton toujours actif
        $recap = $crawler->filter('[data-title="Récapitulatif et conditions"]');
        self::assertCount(1, $recap->filter('.conditions-panel + .finalize-consents'));
        self::assertSelectorTextContains('.conditions-panel', 'Chaise Napoléon : casse 25');
        $boxes = $recap->filter('.finalize-consents input[type=checkbox]');
        self::assertSame([[null, null], [null, null]], $boxes->each(fn (Crawler $b) => [$b->attr('checked'), $b->attr('required')]));
        self::assertSame(['/conditions-generales-de-location', '/politique-de-confidentialite'], $recap->filter('.finalize-consents a')->each(fn (Crawler $a) => $a->attr('href')));
        self::assertSame(['_blank', '_blank'], $recap->filter('.finalize-consents a')->each(fn (Crawler $a) => $a->attr('target')));
        $send = $recap->filter('[data-finalize-target="send"]');
        self::assertSame(['Obtenir mon devis', null], [$send->text(), $send->attr('disabled')]);
        self::assertSame('/mon-devis/envoyer', $crawler->filter('form.finalize__form')->attr('action'));
        // Champ piège hors écran ; aucune coordonnée bancaire
        self::assertSame('-1', $crawler->filter('.trap[aria-hidden="true"] input')->attr('tabindex'));
        self::assertStringNotContainsString('FR7630006000011234567890189', (string) $this->client->getResponse()->getContent());
    }

    public function testValidRequestIsSavedInOneGoWithNumberAndStat(): void
    {
        FakeDistanceCalculator::$meters = 10_400;
        $data = $this->submit([
            'companyName' => 'Salle des fêtes SAS', 'siret' => '123 456 789 00012', 'access' => '2e étage sans ascenseur', 'source' => 'instagram',
        ], [
            'mode' => 'delivery', 'address' => '3 place de la Bourse, Bordeaux', 'options' => ['pickup' => true],
            'period' => ['deliveryDate' => $this->day('-1 day'), 'collectDate' => $this->day('+1 day')],
            // Montants et distance venus du navigateur : ignorés
            'total' => 1, 'distance' => 1,
        ]);

        self::assertSame(['redirect' => '/mon-devis/confirmation'], $data);
        self::assertEmailCount(0);
        $request = $this->onlyRequest();
        self::assertSame('SDDEV-'.(new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris')))->format('Ym').'001', $request->getNumber());
        self::assertSame('nouvelle', $request->getStatus());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $request->getToken());
        self::assertNotSame($request->getToken(), $request->getStatKey());
        self::assertSame(['Inès', 'Martin', '06 12 34 56 78', 'ines@example.com', 'Salle des fêtes SAS', '12345678900012', '2e étage sans ascenseur', 'instagram'], [
            $request->getFirstName(), $request->getLastName(), $request->getPhone(), $request->getEmail(), $request->getCompanyName(), $request->getSiret(), $request->getAccess(), $request->getSource(),
        ]);
        self::assertNotNull($request->getTermsAcceptedAt());
        self::assertNotNull($request->getPrivacyAcceptedAt());

        $version = $request->getCurrentVersion();
        self::assertSame([$version], $request->getVersions()->toArray());
        $paris = new \DateTimeZone('Europe/Paris');
        self::assertSame([$this->day('+0 day'), $this->day('-1 day').' 00:00', $this->day('+1 day').' 23:59'], [
            $version->getEventDate()->format('Y-m-d'), $version->getStartAt()->setTimezone($paris)->format('Y-m-d H:i'), $version->getEndAt()->setTimezone($paris)->format('Y-m-d H:i'),
        ]);
        self::assertSame(['delivery', true, '3 place de la Bourse, Bordeaux'], [$version->getMode(), $version->isCollect(), $version->getAddress()]);
        // Recalculé au serveur : 100 × 3,50 € + livraison et récupération 11 km × 2 × 1 € = 22 € chacune
        $lines = array_map(fn ($l) => [$l->getType(), $l->getLabel(), $l->getQuantity(), $l->getUnitPrice(), $l->getAmount()], $version->getLines()->toArray());
        self::assertSame([
            [QuoteLineType::Product, 'Chaise Napoléon', 100, 350, 35000],
            [QuoteLineType::Delivery, 'Livraison', null, null, 2200],
            [QuoteLineType::Pickup, 'Récupération', null, null, 2200],
        ], $lines);
        self::assertSame([39400, 19700], [$version->getTotal(), $version->getDeposit()]);
        $product = $version->getLines()->first();
        self::assertSame([$this->chaise->getId(), 2500], [$product->getProduct()->getId(), $product->getBreakagePrice()]);
        // Contexte figé
        $context = $version->getPricingContext();
        self::assertSame([5000, 100, 170, 10_400, 11, 1, 80, 70, 30000], [
            $context['depositBasisPoints'], $context['pricePerKm'], $context['truckCapacity'], $context['distanceMeters'], $context['km'], $context['trips'],
            $context['minChairs'], $context['maxKm'], $context['depositAmount'],
        ]);
        self::assertSame(['Rendre le matériel propre.', ['Virement', 'Espèces']], [$context['conditions']['returnTerms'], $context['conditions']['paymentMethods']]);
        self::assertArrayHasKey('installPrices', $context);
        self::assertArrayHasKey('tablesWithChairsDiscount', $context);
        self::assertStringNotContainsString('FR763000', json_encode($context));

        // Statistique anonyme, une seule, sans donnée personnelle
        $stats = $this->db->fetchAllAssociative('SELECT * FROM stat_event');
        self::assertCount(1, $stats);
        self::assertSame([$request->getStatKey(), StatEvent::QUOTE_CREATED, 'instagram', 1, 39400], [$stats[0]['stat_key'], $stats[0]['type'], $stats[0]['source'], (int) $stats[0]['pro'], (int) $stats[0]['amount']]);
        self::assertSame([['id' => $this->chaise->getId(), 'name' => 'Chaise Napoléon', 'quantity' => 100]], json_decode($stats[0]['products'], true));
        self::assertStringNotContainsString('Martin', json_encode($stats));

        // Confirmation : numéro, sans mise en cache ni indexation
        $crawler = $this->client->request('GET', '/mon-devis/confirmation');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1.headline-xl', 'Votre devis '.$request->getNumber().' est prêt.');
        self::assertSelectorTextSame('.quote-confirmation .label-caps', 'Sous réserve de disponibilité');
        self::assertSelectorTextContains('.quote-confirmation', 'Serife vérifie la date et vous envoie le lien de votre devis à jour.');
        self::assertStringNotContainsString('Félicitations', $crawler->text());
        self::assertSame('noindex', $crawler->filter('meta[name=robots]')->attr('content'));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString($request->getNumber(), (string) $this->client->getRequest()->getUri());

        // Numéro suivant pour la demande suivante ; un réglage changé ne touche pas le devis existant
        $this->setting('delivery_price_per_km', '200');
        $this->submit();
        $this->em->clear();
        $numbers = $this->db->fetchFirstColumn('SELECT number FROM quote_request ORDER BY id');
        self::assertStringEndsWith('002', $numbers[1]);
        self::assertSame(100, $this->em->find(QuoteRequest::class, $request->getId())->getCurrentVersion()->getPricingContext()['pricePerKm']);
    }

    public function testWorkflowDeclaresAllPlacesAndHasNoStatusSetter(): void
    {
        /** @var WorkflowInterface $workflow */
        $workflow = static::getContainer()->get('state_machine.quote_request');

        self::assertSame(['nouvelle', 'validee', 'relancee', 'reservee', 'confirmee', 'terminee', 'expiree', 'annulee'], array_keys($workflow->getDefinition()->getPlaces()));
        self::assertSame(['nouvelle'], $workflow->getDefinition()->getInitialPlaces());
        self::assertFalse(method_exists(QuoteRequest::class, 'setStatus'));
        $request = new QuoteRequest();
        self::assertTrue($workflow->can($request, 'valider'));
        self::assertFalse($workflow->can($request, 'confirmer'));
    }

    public function testInvalidContactAndMissingConsentsSaveNothing(): void
    {
        $data = $this->submit(['firstName' => ' ', 'email' => 'ines@', 'phone' => '12', 'siret' => '1234', 'acceptTerms' => null, 'acceptPrivacy' => null], status: 422);

        self::assertEqualsCanonicalizing([
            ['firstName', 'Indiquez votre prénom.'],
            ['phone', QuoteRequest::PHONE_INVALID],
            ['email', QuoteRequest::EMAIL_INVALID],
            ['siret', QuoteRequest::SIRET_INVALID],
            ['acceptTerms', 'Cochez cette case pour accepter les conditions de location.'],
            ['acceptPrivacy', 'Cochez cette case pour accepter l\'utilisation de vos coordonnées.'],
        ], $data['errors']);
        self::assertSame(['email', QuoteRequest::EMAIL_MISSING], $this->submit(['email' => ''], status: 422)['errors'][0]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM quote_request'));
    }

    public function testServerRechecksDateProductsDeliveryAndSlots(): void
    {
        $errors = fn (array $draft) => $this->submit([], $draft, 422)['errors'];

        self::assertSame([['date', 'Choisissez une date au moins 24 h à l\'avance.']], $errors(['date' => (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris')))->format('Y-m-d')]));
        self::assertSame([['returnSlot', 'Choisissez un créneau de retour proposé : au plus tôt le jour de l\'événement.']], $errors(['period' => ['pickupSlot' => $this->day('-1 day').'T09:30', 'returnSlot' => $this->day('+5 days').'T09:30']]));
        self::assertSame([['mode', 'Choisissez le retrait au dépôt ou la livraison.']], $errors(['mode' => null]));
        // Livraison sous le minimum de chaises : éligibilité recalculée
        self::assertSame([['mode', 'Livraison dès 80 chaises : vous en avez 60.']], $errors([
            'lines' => [['productId' => $this->chaise->getId(), 'quantity' => 60]], 'mode' => 'delivery', 'address' => '1 rue Proche, Bassens',
            'period' => ['deliveryDate' => $this->day('+0 day'), 'returnSlot' => $this->day('+1 day').'T09:30'],
        ]));
        // Produit masqué depuis : plus aucun produit visible
        $this->db->executeStatement('UPDATE product SET visible = 0');
        self::assertSame('lines', $errors([])[0][0]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM quote_request'));
    }

    public function testExpiredFormIsRefused(): void
    {
        $data = $this->submit(['_token' => 'faux'], status: 422, origin: 'https://ailleurs.example');

        self::assertSame('form', $data['errors'][0][0]);
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM quote_request'));
    }

    public function testTrapFieldGetsNeutralAnswerAndSavesNothing(): void
    {
        self::assertSame(['redirect' => '/mon-devis/confirmation'], $this->submit(['website' => 'http://spam.example']));
        self::assertSame(0, (int) $this->db->fetchOne('SELECT COUNT(*) FROM quote_request'));

        $this->client->request('GET', '/mon-devis/confirmation');
        self::assertResponseRedirects('/mon-devis');
    }

    public function testFiveRequestsPerHourPerIp(): void
    {
        foreach (range(1, 5) as $i) {
            self::assertArrayHasKey('redirect', $this->submit(), (string) $i);
        }

        self::assertSame(['error' => 'Trop de demandes envoyées. Réessayez dans une heure, ou écrivez-nous sur WhatsApp.'], $this->submit(status: 429));
        self::assertSame(5, (int) $this->db->fetchOne('SELECT COUNT(*) FROM quote_request'));
    }

    public function testFailureSavesNothingHalfway(): void
    {
        // Numérotation pleine : l'erreur arrive dans la transaction, rien ne reste
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $this->db->executeStatement('INSERT INTO document_counter (type, year, month, last) VALUES (?, ?, ?, 999)', ['SDDEV', (int) $now->format('Y'), (int) $now->format('n')]);

        self::assertSame(['error' => 'Le devis n\'a pas pu être créé. Réessayez, ou écrivez-nous sur WhatsApp.'], $this->submit(status: 500));
        foreach (['quote_request', 'quote_version', 'quote_line', 'stat_event'] as $table) {
            self::assertSame(0, (int) $this->db->fetchOne("SELECT COUNT(*) FROM $table"), $table);
        }
    }

    public function testClosedQuoteRefusesRequests(): void
    {
        $this->setting('quote_online', '0');

        self::assertSame(['error' => 'Le devis en ligne est momentanément fermé.'], $this->submit(status: 503));
    }

    /**
     * Envoi tel que le fait le contrôleur finalize : coordonnées du formulaire + `draft` JSON. Retrait par défaut.
     *
     * @param array<string, mixed> $fields valeur null = champ absent (case non cochée)
     * @param array<string, mixed> $draft
     *
     * @return array<string, mixed>
     */
    private function submit(array $fields = [], array $draft = [], int $status = 200, string $origin = 'http://localhost'): array
    {
        $draft += [
            'lines' => [['productId' => $this->chaise->getId(), 'quantity' => 100]],
            'date' => $this->day('+0 day'),
            'mode' => 'pickup',
            'address' => '',
            'options' => [],
            'period' => ['pickupSlot' => $this->day('-1 day').'T09:30', 'returnSlot' => $this->day('+1 day').'T09:30'],
        ];
        $fields = array_filter($fields + [
            'firstName' => 'Inès', 'lastName' => 'Martin', 'phone' => '06 12 34 56 78', 'email' => 'ines@example.com',
            'acceptTerms' => '1', 'acceptPrivacy' => '1', 'website' => '', 'draft' => json_encode($draft), '_token' => 'csrf-token',
        ], fn ($v) => null !== $v);
        $this->client->request('POST', '/mon-devis/envoyer', ['demande' => $fields], server: ['HTTP_ORIGIN' => $origin, 'HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame($status);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
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

    /** @param array<int, int> $tiers quantité minimale → prix en centimes */
    private function product(Category $category, string $name, array $tiers, ?int $breakage = null): Product
    {
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower($name).'-'.bin2hex(random_bytes(3)));
        $product->setCategory($category);
        $product->setVisible(true);
        $product->setMinQty(array_key_first($tiers));
        $product->setBreakagePrice($breakage);
        foreach ($tiers as $qty => $price) {
            $product->addPriceTier(new PriceTier($qty, $price));
        }
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
