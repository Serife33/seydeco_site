<?php

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeTest extends WebTestCase
{
    public function testHomePageRenders(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('h1', 'Seydeco');
        self::assertSelectorTextContains('body', 'Location de matériel de réception');
        self::assertSelectorTextContains('body', 'Site en préparation');
        // Le lien d'évitement est le premier lien de la page
        self::assertSame('Aller au contenu', $crawler->filter('a')->first()->text());
        self::assertCount(1, $crawler->filter('main#contenu'));
    }
}
