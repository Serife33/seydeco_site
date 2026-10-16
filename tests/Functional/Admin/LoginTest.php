<?php

namespace App\Tests\Functional\Admin;

use Doctrine\DBAL\Connection;
use Monolog\Handler\TestHandler;
use Symfony\Component\Yaml\Yaml;

final class LoginTest extends AdminTestCase
{
    public function testAnonymousIsSentToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/connexion');
    }

    public function testLoginPageIsInFrenchWithLabelledFields(): void
    {
        $crawler = $this->client->request('GET', '/admin/connexion');

        self::assertResponseIsSuccessful();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('h1', 'Connexion');
        self::assertCount(1, $crawler->filter('label[for=username]'));
        self::assertCount(1, $crawler->filter('label[for=password]'));
    }

    public function testFullLoginWithEmailedCode(): void
    {
        $this->submitLogin();

        self::assertSame('/admin/code', parse_url($this->client->getRequest()->getUri(), \PHP_URL_PATH));
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailHeaderSame($email, 'Subject', 'Votre code de connexion Seydeco');
        $code = $this->lastSentCode();
        self::assertMatchesRegularExpression('/^\d{6}$/', $code);
        self::assertEmailHtmlBodyContains($email, $code);

        self::assertSelectorTextContains('h1', 'Code de connexion');
        $this->submitCode($code);

        self::assertResponseRedirects('/admin');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Bonjour Serife');

        // Code à usage unique : effacé après usage
        $stored = static::getContainer()->get(Connection::class)->fetchOne('SELECT auth_code FROM admin_user');
        self::assertNull($stored);
    }

    public function testHalfWayLoginIsSentBackToCodeScreen(): void
    {
        $this->submitLogin();

        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/code');
    }

    public function testWrongCredentialsShowSingleMessage(): void
    {
        $this->submitLogin(self::EMAIL, 'mauvais');
        self::assertSelectorTextContains('.admin-error', 'Identifiant ou mot de passe incorrect.');

        $this->submitLogin('inconnu@example.com', self::PASSWORD);
        self::assertSelectorTextContains('.admin-error', 'Identifiant ou mot de passe incorrect.');
        self::assertEmailCount(0);
    }

    public function testSixthFailureIn15MinutesIsThrottled(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->submitLogin(self::EMAIL, 'mauvais');
            self::assertSelectorTextContains('.admin-error', 'Identifiant ou mot de passe incorrect.');
        }

        // Même le bon mot de passe est refusé pendant le blocage
        $this->submitLogin();
        self::assertSelectorTextContains('.admin-error', 'Trop de tentatives. Réessayez dans 15 minutes.');
    }

    public function testWrongCodeShowsErrorAndResendButton(): void
    {
        $this->submitLogin();
        $this->submitCode('000000' === $this->lastSentCode() ? '111111' : '000000');

        self::assertResponseRedirects('/admin/code');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-error', 'Code incorrect ou expiré.');
        self::assertSelectorExists('button:contains("Renvoyer un code")');
    }

    public function testExpiredCodeIsRefused(): void
    {
        $this->submitLogin();
        $code = $this->lastSentCode();
        static::getContainer()->get(Connection::class)
            ->executeStatement('UPDATE admin_user SET auth_code_expires_at = ?', [(new \DateTimeImmutable('-16 minutes'))->format('Y-m-d H:i:s')]);

        $this->submitCode($code);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.admin-error', 'Code incorrect ou expiré.');
    }

    public function testResendSendsANewWorkingCode(): void
    {
        $this->submitLogin();

        $this->client->submitForm('Renvoyer un code');
        self::assertResponseRedirects('/admin/code');
        self::assertEmailCount(1);
        $code = $this->lastSentCode();
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-notice', 'Un nouveau code vous a été envoyé.');

        $this->submitCode($code);
        self::assertResponseRedirects('/admin');
    }

    public function testFifthWrongCodeSendsBackToLogin(): void
    {
        $this->submitLogin();
        $wrong = '000000' === $this->lastSentCode() ? '111111' : '000000';

        for ($i = 1; $i <= 4; ++$i) {
            $this->submitCode($wrong);
            self::assertResponseRedirects('/admin/code');
            $this->client->followRedirect();
        }
        $this->submitCode($wrong);

        self::assertResponseRedirects('/admin/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-error', 'Trop de codes incorrects.');
        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/connexion');
    }

    public function testReturnsToRequestedPageAfterReconnecting(): void
    {
        $this->client->request('GET', '/admin/plus');
        self::assertResponseRedirects('/admin/connexion');

        $this->loginFully();

        self::assertResponseRedirects('/admin/plus');
    }

    public function testLogout(): void
    {
        $this->loginFully();
        $this->client->request('GET', '/admin/plus');
        self::assertResponseIsSuccessful();

        $this->client->clickLink('Se déconnecter');
        self::assertResponseRedirects('/admin/connexion');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/admin/connexion');
    }

    public function testDashboardShellHasOnlyHomeAndMore(): void
    {
        $this->loginFully();
        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(['Accueil', 'Plus'], $crawler->filter('nav.admin-nav a')->each(fn ($a) => $a->text()));
        self::assertSame('page', $crawler->filter('nav.admin-nav a')->first()->attr('aria-current'));
    }

    public function testNeitherPasswordNorCodeIsLogged(): void
    {
        $records = [];
        // Le noyau redémarre à chaque requête : on relève le journal (tous canaux) après chacune
        $request = function (string $method, string $uri, array $params = []) use (&$records): void {
            $this->client->request($method, $uri, $params);
            /** @var TestHandler $handler */
            $handler = $this->client->getContainer()->get('monolog.handler.testing');
            foreach ($handler->getRecords() as $record) {
                $records[] = [$record->channel, $record->message.json_encode($record->context, \JSON_PARTIAL_OUTPUT_ON_ERROR).json_encode($record->extra, \JSON_PARTIAL_OUTPUT_ON_ERROR)];
            }
        };
        $loginForm = fn (string $password): array => [
            '_username' => self::EMAIL,
            '_password' => $password,
            '_csrf_token' => $this->client->getCrawler()->filter('input[name=_csrf_token]')->attr('value'),
        ];

        $request('GET', '/admin/connexion');
        $request('POST', '/admin/connexion', $loginForm('mauvais-mot-de-passe-secret'));
        $request('GET', '/admin/connexion');
        $request('POST', '/admin/connexion', $loginForm(self::PASSWORD));
        $request('GET', '/admin');
        $request('GET', '/admin/code');
        $code = $this->lastSentCode();
        $form = $this->client->getCrawler()->selectButton('Valider')->form(['_auth_code' => $code]);
        $request('POST', '/admin/code/verifier', $form->getValues());
        self::assertResponseRedirects('/admin');
        $request('GET', '/admin');
        self::assertNotEmpty($records);

        // On applique les filtres de canaux des vrais handlers « main » de chaque environnement
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/monolog.yaml');
        foreach (['dev', 'test', 'prod'] as $env) {
            $channels = $config['when@'.$env]['monolog']['handlers']['main']['channels'] ?? null;
            $logs = '';
            foreach ($records as [$channel, $line]) {
                if (self::channelIsRouted($channel, $channels)) {
                    $logs .= $line."\n";
                }
            }
            self::assertStringNotContainsString(self::PASSWORD, $logs, "Mot de passe dans les journaux $env");
            self::assertStringNotContainsString('mauvais-mot-de-passe-secret', $logs, "Mot de passe dans les journaux $env");
            self::assertStringNotContainsString($code, $logs, "Code 2FA dans les journaux $env");
        }
    }

    /** @param list<string>|null $channels syntaxe Monolog : ["!a", "!b"] exclut, ["a"] ne garde que a */
    private static function channelIsRouted(string $channel, ?array $channels): bool
    {
        if (!$channels) {
            return true;
        }
        $excluded = array_map(fn (string $c) => ltrim($c, '!'), array_filter($channels, fn (string $c) => str_starts_with($c, '!')));

        return $excluded ? !\in_array($channel, $excluded, true) : \in_array($channel, $channels, true);
    }

    public function testSessionTokenHoldsNeitherPasswordHashNorCode(): void
    {
        $hash = static::getContainer()->get(Connection::class)->fetchOne('SELECT password FROM admin_user');

        $this->submitLogin();
        $code = $this->lastSentCode();
        $halfWay = $this->sessionSecurityToken();
        self::assertStringNotContainsString($hash, $halfWay);
        self::assertStringNotContainsString($code, $halfWay);

        $this->submitCode($code);
        self::assertResponseRedirects('/admin');
        $full = $this->sessionSecurityToken();
        self::assertStringNotContainsString($hash, $full);
        self::assertStringNotContainsString($code, $full);
    }

    private function sessionSecurityToken(): string
    {
        $id = $this->client->getCookieJar()->get('MOCKSESSID')->getValue();
        $raw = file_get_contents(static::getContainer()->getParameter('kernel.cache_dir').'/sessions/'.$id.'.mocksess');
        $token = unserialize($raw)['_sf2_attributes']['_security_main'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    public function testReloginDoesNotGiveFreshCodeAttempts(): void
    {
        $this->submitLogin();
        $wrong = '000000' === $this->lastSentCode() ? '111111' : '000000';
        for ($i = 1; $i <= 4; ++$i) {
            $this->submitCode($wrong);
            $this->followAllRedirects();
        }

        // Reconnexion avant le 5ᵉ essai : le compteur, lié au compte, n'est pas remis à zéro
        $this->client->getCookieJar()->clear();
        $this->submitLogin();
        $this->submitCode($wrong);
        self::assertResponseRedirects('/admin/connexion');

        // Bloqué : même le bon code est refusé jusqu'à la fin de la fenêtre
        $this->submitLogin();
        $this->submitCode($this->lastSentCode());
        self::assertResponseRedirects('/admin/connexion');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-error', 'Trop de codes incorrects. Réessayez dans 15 minutes.');
    }

    public function testLoginWithInvalidCsrfTokenSaysSessionExpired(): void
    {
        $this->client->request('POST', '/admin/connexion', ['_username' => self::EMAIL, '_password' => self::PASSWORD, '_csrf_token' => 'invalide']);
        $this->followAllRedirects();

        self::assertSelectorTextContains('.admin-error', 'Session expirée, réessayez.');
        self::assertEmailCount(0);
    }

    public function testResendWithInvalidTokenSendsNoEmail(): void
    {
        $this->submitLogin();

        $this->client->request('POST', '/admin/code/renvoyer', ['_token' => 'invalide']);
        self::assertResponseRedirects('/admin/code');
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-error', 'Session expirée, réessayez.');
    }

    public function testResendIsLimitedTo3Per15Minutes(): void
    {
        $this->submitLogin();
        for ($i = 1; $i <= 3; ++$i) {
            $this->client->submitForm('Renvoyer un code');
            self::assertEmailCount(1);
            $this->client->followRedirect();
        }

        $this->client->submitForm('Renvoyer un code');
        self::assertEmailCount(0);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-error', 'Trop de demandes de code. Réessayez dans 15 minutes.');
    }
}
