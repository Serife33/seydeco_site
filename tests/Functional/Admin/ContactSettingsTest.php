<?php

namespace App\Tests\Functional\Admin;

use App\Tests\Functional\SiteLayoutTest;
use Doctrine\DBAL\Connection;

/** Story 2.1 : Plus → Contenus → Coordonnées et réseaux, écrits dans `Setting`. */
final class ContactSettingsTest extends AdminTestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = static::getContainer()->get(Connection::class);
        $this->whatsapp = (string) $this->db->fetchOne("SELECT value FROM setting WHERE name = 'whatsapp_phone'");
        $this->loginFully();
    }

    private string $whatsapp;

    protected function tearDown(): void
    {
        SiteLayoutTest::resetSettings($this->db);
        $this->db->executeStatement("UPDATE setting SET value = ? WHERE name = 'whatsapp_phone'", [$this->whatsapp]);
        parent::tearDown();
    }

    public function testSerifeSavesDetailsAndNetworks(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        $this->client->click($crawler->selectLink('Coordonnées et réseaux')->link());
        self::assertSelectorTextSame('h1', 'Coordonnées et réseaux');
        self::assertSelectorExists('.admin-nav a[aria-current="page"][href="/admin/plus"]');

        $this->client->submitForm('Enregistrer', [
            'contact_settings[phone]' => ' 05 56 00 00 00 ',
            'contact_settings[whatsapp_phone]' => '06 12 34 56 78',
            'contact_settings[address]' => "12 rue des Fleurs\n33000 Bordeaux",
            'contact_settings[siret]' => '123 456 789 00012',
            'contact_settings[instagram_url]' => 'https://www.instagram.com/seydeco',
            'contact_settings[tiktok_url]' => 'https://www.tiktok.com/@seydeco',
            'contact_settings[facebook_url]' => 'https://fr-fr.facebook.com/seydeco',
            'contact_settings[youtube_url]' => '',
            'contact_settings[pinterest_url]' => 'https://www.pinterest.fr/seydeco/',
        ]);

        self::assertResponseRedirects('/admin/coordonnees');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.admin-notice', 'Coordonnées enregistrées.');
        self::assertSame([
            'address' => "12 rue des Fleurs\n33000 Bordeaux",
            'facebook_url' => 'https://fr-fr.facebook.com/seydeco',
            'instagram_url' => 'https://www.instagram.com/seydeco',
            'phone' => '05 56 00 00 00',
            'pinterest_url' => 'https://www.pinterest.fr/seydeco/',
            'siret' => '123 456 789 00012',
            'tiktok_url' => 'https://www.tiktok.com/@seydeco',
            'whatsapp_phone' => '06 12 34 56 78',
            'youtube_url' => '',
        ], $this->db->fetchAllKeyValue("SELECT name, value FROM setting WHERE name IN ('".implode("', '", [...SiteLayoutTest::CONTACT_KEYS, 'whatsapp_phone'])."') ORDER BY name"));

        // Relu par le site public via SettingsProvider ; YouTube vide : affiché nulle part
        $crawler = $this->client->request('GET', '/');
        self::assertSame(['Instagram', 'TikTok', 'Facebook', 'Pinterest'], $crawler->filter('.site-footer__social a')->each(fn ($a) => trim(str_replace('(nouvel onglet)', '', $a->text()))));
        self::assertSame('tel:+33556000000', $crawler->filter('footer a[href^="tel:"]')->attr('href'));
    }

    public function testLinkFromAnotherNetworkIsRefusedAndInputsKept(): void
    {
        $this->client->request('GET', '/admin/coordonnees');
        $this->client->submitForm('Enregistrer', [
            'contact_settings[address]' => '12 rue des Fleurs',
            'contact_settings[instagram_url]' => 'https://facebook.com/x',
            'contact_settings[youtube_url]' => 'https://youtube.com.evil.example/x',
            'contact_settings[siret]' => '123',
            'contact_settings[pinterest_url]' => 'https://pinterest.evil.example/x',
            'contact_settings[phone]' => '((( ... )))',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#contact_settings_instagram_url ~ .admin-field__error', "Ce lien ne vient pas d'Instagram.");
        self::assertSelectorTextSame('#contact_settings_youtube_url ~ .admin-field__error', 'Ce lien ne vient pas de YouTube.');
        self::assertSelectorTextSame('#contact_settings_siret ~ .admin-field__error', 'Le SIRET compte 14 chiffres.');
        self::assertSelectorTextSame('#contact_settings_pinterest_url ~ .admin-field__error', 'Ce lien ne vient pas de Pinterest.');
        self::assertSelectorTextSame('#contact_settings_phone ~ .admin-field__error', 'Saisir un numéro, par exemple 06 12 34 56 78.');
        self::assertInputValueSame('contact_settings[instagram_url]', 'https://facebook.com/x');
        self::assertSelectorTextSame('#contact_settings_address', '12 rue des Fleurs');
        self::assertSame('', $this->db->fetchOne("SELECT value FROM setting WHERE name = 'address'"), 'Rien enregistré');
    }
}
