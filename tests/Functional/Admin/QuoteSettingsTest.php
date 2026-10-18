<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\PricingType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;

/** Stories 3.1 + 3.2 : Plus → Réglages (Devis, Livraison, Installation, Conditions, Paiement), écrits dans `Setting`. */
final class QuoteSettingsTest extends CatalogTestCase
{
    private const string IBAN = 'FR76 3000 6000 0112 3456 7890 189';

    private Connection $db;
    /** @var array<string, string> */
    private array $before;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = static::getContainer()->get(Connection::class);
        $this->before = $this->db->fetchAllKeyValue('SELECT name, value FROM setting');
    }

    protected function tearDown(): void
    {
        // Base partagée : chaque réglage retrouve sa valeur
        foreach ($this->before as $name => $value) {
            $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
        }
        parent::tearDown();
    }

    /** @param list<string> $names @return array<string, string> */
    private function settings(array $names): array
    {
        return $this->db->fetchAllKeyValue("SELECT name, value FROM setting WHERE name IN ('".implode("', '", $names)."') ORDER BY name");
    }

    public function testSettingsMenuListsSectionsWithoutDeadLinks(): void
    {
        $this->createCategory('Chaises', 1, PricingType::Chairs);
        $crawler = $this->client->request('GET', '/admin/plus');
        $crawler = $this->client->click($crawler->selectLink('Réglages')->link());

        self::assertSelectorTextSame('h1', 'Réglages');
        self::assertSelectorExists('.admin-nav a[aria-current="page"][href="/admin/plus"]');
        $rows = $crawler->filter('.admin-list--menu .admin-list__row');
        self::assertSame(['Créneaux au dépôt', 'Devis', 'Livraison', 'Installation', 'Conditions', 'Paiement', 'Sécurité'], $rows->filter('.admin-list__name')->each(fn (Crawler $n) => $n->text()));
        self::assertSame(
            ['/admin/reglages/creneaux', '/admin/reglages/devis', '/admin/reglages/livraison', '/admin/reglages/installation', '/admin/reglages/conditions', '/admin/reglages/paiement'],
            $rows->filter('a')->each(fn (Crawler $a) => $a->attr('href')),
        );
        // Rubrique pas encore construite : sans lien
        foreach ([6] as $i) {
            self::assertCount(0, $rows->eq($i)->filter('a'));
            self::assertSame('Bientôt disponible.', $rows->eq($i)->filter('.admin-list__meta')->text());
        }
        // Créneaux au dépôt est une liste (SlotAdminTest) ; les autres rubriques, un formulaire
        foreach (array_diff($rows->filter('a')->each(fn (Crawler $a) => $a->attr('href')), ['/admin/reglages/creneaux']) as $href) {
            $this->client->request('GET', $href);
            self::assertResponseIsSuccessful($href);
            self::assertSelectorCount(1, 'form button[type="submit"]');
            self::assertSelectorTextSame('form button[type="submit"]', 'Enregistrer');
            self::assertSelectorExists('button[data-turbo-submits-with="Envoi…"]');
            self::assertSelectorTextSame('[data-save-error][hidden]', 'Non enregistré. Réessayer.');
        }
    }

    public function testQuoteSettingsAreSavedInCentsAndReadBySite(): void
    {
        $crawler = $this->client->request('GET', '/admin/reglages/devis');
        self::assertSame(['%', 'jours', 'jours', '€', '€ / chaise'], $crawler->filter('.admin-money')->each(fn (Crawler $n) => $n->text()));
        // Valeurs de départ : acompte 50 %, supplément nettoyage 0,50 €
        self::assertInputValueSame('quote_settings[deposit_percent]', '50');
        self::assertInputValueSame('quote_settings[cleaning_fee]', '0,50');

        $this->client->submitForm('Enregistrer', [
            'quote_settings[deposit_percent]' => '30',
            'quote_settings[quote_reminder_days]' => '7',
            'quote_settings[quote_validity_days]' => '30',
            'quote_settings[deposit_amount]' => '300,00',
            'quote_settings[cleaning_fee]' => '',
            'quote_settings[notification_email]' => ' demandes@example.com ',
        ]);

        self::assertResponseRedirects('/admin/reglages/devis');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.admin-notice', 'Réglages enregistrés.');
        self::assertSame([
            'cleaning_fee' => '',
            'deposit_amount' => '30000',
            'deposit_percent' => '30',
            'notification_email' => 'demandes@example.com',
            'quote_reminder_days' => '7',
            'quote_validity_days' => '30',
        ], $this->settings(['deposit_percent', 'quote_reminder_days', 'quote_validity_days', 'deposit_amount', 'cleaning_fee', 'notification_email']));

        $crawler = $this->client->request('GET', '/comment-ca-marche');
        $rules = array_combine($crawler->filter('.info-block dt')->each(fn (Crawler $n) => $n->text()), $crawler->filter('.info-block dd')->each(fn (Crawler $n) => $n->text()));
        self::assertSame('30 % du devis, à la réservation', $rules['Acompte']);
        self::assertSame("300,00\u{A0}€", $rules['Caution']);
    }

    public function testQuoteSettingsRefuseWrongValuesUnderTheField(): void
    {
        $this->client->request('GET', '/admin/reglages/devis');
        $this->client->submitForm('Enregistrer', [
            'quote_settings[deposit_percent]' => '101',
            'quote_settings[quote_reminder_days]' => '30',
            'quote_settings[quote_validity_days]' => '30',
            'quote_settings[deposit_amount]' => '-5',
            'quote_settings[cleaning_fee]' => 'gratuit',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#quote_settings_deposit_percent_error1', 'L\'acompte doit être entre 1 et 100 %.');
        self::assertSelectorTextSame('#quote_settings_quote_reminder_days_error1', 'Le délai de relance doit être plus court que la durée de validité.');
        self::assertSelectorTextSame('#quote_settings_deposit_amount_error1', 'Le montant ne peut pas être négatif.');
        self::assertSelectorTextSame('#quote_settings_cleaning_fee_error1', 'Saisir un montant en euros, par exemple 45,00.');
        $reminder = $this->client->getCrawler()->filter('#quote_settings_quote_reminder_days');
        self::assertSame('true', $reminder->attr('aria-invalid'));
        self::assertStringContainsString('quote_settings_quote_reminder_days_error1', (string) $reminder->attr('aria-describedby'));
        self::assertInputValueSame('quote_settings[deposit_percent]', '101');

        foreach (['0' => 'L\'acompte doit être entre 1 et 100 %.', '2,5' => 'Saisir un nombre entier, par exemple 30.', '-10' => 'L\'acompte doit être entre 1 et 100 %.'] as $deposit => $message) {
            $this->client->submitForm('Enregistrer', ['quote_settings[deposit_percent]' => (string) $deposit, 'quote_settings[quote_validity_days]' => '-3']);
            self::assertSelectorTextSame('#quote_settings_deposit_percent_error1', $message);
            self::assertSelectorTextSame('#quote_settings_quote_validity_days_error1', 'Saisir un nombre de jours entre 1 et 365.');
        }
        self::assertSame($this->before['deposit_percent'], $this->settings(['deposit_percent'])['deposit_percent'], 'Rien enregistré');
    }

    public function testDeliverySettingsAreSavedAndShownOnSite(): void
    {
        $crawler = $this->client->request('GET', '/admin/reglages/livraison');
        self::assertSame('170', $crawler->filter('#delivery_settings_truck_capacity')->attr('value'));
        self::assertStringContainsString('€ / km', $crawler->filter('#delivery_settings_delivery_price_per_km')->ancestors()->first()->text());

        $this->client->submitForm('Enregistrer', [
            'delivery_settings[delivery_price_per_km]' => '1,20',
            'delivery_settings[delivery_min_chairs]' => '60',
            'delivery_settings[delivery_max_km]' => '50',
            'delivery_settings[truck_capacity]' => '150',
            'delivery_settings[depot_address]' => ' 12 rue du Port, 33530 Bassens ',
            'delivery_settings[depot_city]' => 'Bassens',
        ]);

        self::assertResponseRedirects('/admin/reglages/livraison');
        self::assertSame([
            'delivery_max_km' => '50',
            'delivery_min_chairs' => '60',
            'delivery_price_per_km' => '120',
            'depot_address' => '12 rue du Port, 33530 Bassens',
            'depot_city' => 'Bassens',
            'truck_capacity' => '150',
        ], $this->settings(['delivery_price_per_km', 'delivery_min_chairs', 'delivery_max_km', 'truck_capacity', 'depot_address', 'depot_city']));

        // Pages qui affichaient déjà ces valeurs : à jour sans autre changement
        $crawler = $this->client->request('GET', '/comment-ca-marche');
        $rules = array_combine($crawler->filter('.info-block dt')->each(fn (Crawler $n) => $n->text()), $crawler->filter('.info-block dd')->each(fn (Crawler $n) => $n->text()));
        self::assertSame("1,20\u{A0}€ / km par la route depuis Bassens, dès 60 chaises, 50 km max", $rules['Livraison']);
        self::assertStringContainsString('50 km', $this->client->request('GET', '/')->filter('script[type="application/ld+json"]')->text());
    }

    public function testDeliverySettingsRefuseWrongValues(): void
    {
        $this->client->request('GET', '/admin/reglages/livraison');
        $this->client->submitForm('Enregistrer', [
            'delivery_settings[delivery_price_per_km]' => '-1',
            'delivery_settings[delivery_min_chairs]' => '80,5',
            'delivery_settings[delivery_max_km]' => '0',
            'delivery_settings[truck_capacity]' => '-170',
            'delivery_settings[depot_address]' => ' , - ',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#delivery_settings_delivery_price_per_km_error1', 'Le montant ne peut pas être négatif.');
        self::assertSelectorTextSame('#delivery_settings_delivery_min_chairs_error1', 'Saisir un nombre entier, par exemple 30.');
        self::assertSelectorTextSame('#delivery_settings_delivery_max_km_error1', 'Saisir un nombre entre 1 et 100 000.');
        self::assertSelectorTextSame('#delivery_settings_truck_capacity_error1', 'Saisir un nombre entre 1 et 100 000.');
        self::assertSelectorTextSame('#delivery_settings_depot_address_error1', 'Saisir l\'adresse complète, par exemple 12 rue du Port, 33530 Bassens.');
        self::assertSame('170', $this->settings(['truck_capacity'])['truck_capacity'], 'Rien enregistré');
    }

    public function testInstallPricesPerCategory(): void
    {
        $chairs = $this->createCategory('Chaises', 1, PricingType::Chairs);
        $this->createCategory('Vaisselle', 2);

        $crawler = $this->client->request('GET', '/admin/reglages/installation');
        self::assertSame(['Chaises', 'Vaisselle'], $crawler->filter('form label')->each(fn (Crawler $l) => $l->text()));
        self::assertStringContainsString('€ / chaise', $crawler->filter('.admin-money')->eq(0)->text());
        self::assertStringContainsString('€ / article', $crawler->filter('.admin-money')->eq(1)->text());

        $this->client->submitForm('Enregistrer', ['form[categories][0][installPrice]' => '-1', 'form[categories][1][installPrice]' => 'deux']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#form_categories_0_installPrice_error1', 'Le montant ne peut pas être négatif.');
        self::assertSelectorTextSame('#form_categories_1_installPrice_error1', 'Saisir un montant en euros, par exemple 45,00.');
        self::assertNull($this->em()->find(Category::class, $chairs->getId())->getInstallPrice(), 'Rien enregistré');

        $this->client->submitForm('Enregistrer', ['form[categories][0][installPrice]' => '0,50', 'form[categories][1][installPrice]' => '']);
        self::assertResponseRedirects('/admin/reglages/installation');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.admin-notice', 'Tarifs enregistrés.');
        $em = $this->em();
        self::assertSame([50, null], array_map(fn (Category $c) => $c->getInstallPrice(), $em->getRepository(Category::class)->findBy([], ['position' => 'ASC'])));
    }

    public function testInstallScreenWithoutCategory(): void
    {
        $this->client->request('GET', '/admin/reglages/installation');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucune catégorie pour l\'instant');
        self::assertSelectorNotExists('main form');
    }

    public function testTermsCannotBeEmpty(): void
    {
        $this->client->request('GET', '/admin/reglages/conditions');
        $this->client->submitForm('Enregistrer', [
            'terms_settings[return_terms]' => '   ',
            'terms_settings[pickup_terms]' => 'Véhicule adapté, sangles, quelqu\'un sur place.',
            'terms_settings[fuel_terms]' => '',
        ]);
        self::assertResponseStatusCodeSame(422);
        foreach (['return_terms', 'fuel_terms'] as $field) {
            self::assertSelectorTextSame("#terms_settings_{$field}_error1", 'Ce texte apparaît sur chaque devis : il ne peut pas être vide.');
        }
        self::assertSelectorNotExists('#terms_settings_pickup_terms_error1');

        $this->client->submitForm('Enregistrer', [
            'terms_settings[return_terms]' => 'Matériel rendu complet et propre.',
            'terms_settings[fuel_terms]' => 'Tarif au km révisable selon le carburant.',
        ]);
        self::assertResponseRedirects('/admin/reglages/conditions');
        self::assertSame([
            'fuel_terms' => 'Tarif au km révisable selon le carburant.',
            'pickup_terms' => 'Véhicule adapté, sangles, quelqu\'un sur place.',
            'return_terms' => 'Matériel rendu complet et propre.',
        ], $this->settings(['return_terms', 'pickup_terms', 'fuel_terms']));
    }

    public function testPaymentSettingsValidateIbanAndKeepOneMethod(): void
    {
        $crawler = $this->client->request('GET', '/admin/reglages/paiement');
        self::assertSelectorTextContains('main', 'Vos coordonnées bancaires ne s\'affichent qu\'après votre validation d\'un devis.');
        self::assertSame(['Virement', 'Espèces', 'Wero'], $crawler->filter('fieldset legend + .admin-checks label')->each(fn (Crawler $l) => $l->text()));
        self::assertCount(3, $crawler->filter('fieldset input[type="checkbox"][checked]'), 'Trois moyens cochés au départ');

        $form = $crawler->selectButton('Enregistrer')->form();
        $form['payment_settings[iban]'] = 'FR76 3000 6000 0112 3456 7890 180';
        $form['payment_settings[bic]'] = 'XYZ';
        foreach ($form['payment_settings[payment_methods]'] as $box) {
            $box->untick();
        }
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#payment_settings_iban_error1', 'Cet IBAN n\'est pas valide : vérifiez chaque caractère.');
        self::assertSelectorTextSame('#payment_settings_bic_error1', 'Ce BIC n\'est pas valide : il compte 8 ou 11 caractères.');
        self::assertSelectorTextSame('fieldset .admin-field__error', 'Cochez au moins un moyen de paiement.');
        self::assertSame('virement,especes,wero', $this->settings(['payment_methods'])['payment_methods'], 'Rien enregistré');

        $form = $this->client->getCrawler()->selectButton('Enregistrer')->form();
        $form['payment_settings[bank_holder]'] = 'Seydeco';
        $form['payment_settings[iban]'] = self::IBAN;
        $form['payment_settings[bic]'] = 'AGRIFRPP';
        $form['payment_settings[payment_methods]'][0]->tick();
        $form['payment_settings[payment_methods]'][2]->tick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/reglages/paiement');
        self::assertSame([
            'bank_holder' => 'Seydeco',
            'bic' => 'AGRIFRPP',
            'iban' => self::IBAN,
            'payment_methods' => 'virement,wero',
        ], $this->settings(['bank_holder', 'iban', 'bic', 'payment_methods']));

        // Coordonnées bancaires : nulle part sur le site public ni dans les journaux (FR-7)
        $this->db->executeStatement("UPDATE setting SET value = '1' WHERE name = 'quote_online'");
        foreach (['/', '/comment-ca-marche', '/mon-devis', '/location', '/contact', '/conditions-generales-de-location'] as $url) {
            $this->client->request('GET', $url);
            $html = (string) $this->client->getResponse()->getContent();
            foreach ([self::IBAN, str_replace(' ', '', self::IBAN), 'AGRIFRPP'] as $secret) {
                self::assertStringNotContainsString($secret, $html, "Coordonnées bancaires sur $url");
            }
        }
        $log = static::getContainer()->getParameter('kernel.logs_dir').'/test.log';
        if (is_file($log)) {
            self::assertStringNotContainsString(str_replace(' ', '', self::IBAN), str_replace(' ', '', (string) file_get_contents($log)));
        }
    }
}
