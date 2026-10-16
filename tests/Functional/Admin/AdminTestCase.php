<?php

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

abstract class AdminTestCase extends WebTestCase
{
    protected const string EMAIL = 'serife@example.com';
    protected const string PASSWORD = 'Un-mot-de-passe-solide-42';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        // Base de test partagée : on repart d'un état propre (compte + compteurs anti-essais)
        $em = $container->get(EntityManagerInterface::class);
        $em->createQuery('DELETE FROM '.AdminUser::class)->execute();
        $container->get('cache.rate_limiter_dbal')->clear();

        $admin = new AdminUser(self::EMAIL, '');
        $admin->setPassword($container->get('security.user_password_hasher')->hashPassword($admin, self::PASSWORD));
        $em->persist($admin);
        $em->flush();
        $em->clear();
    }

    /** Soumet email + mot de passe et suit les redirections (écran du code, ou Connexion en erreur). */
    protected function submitLogin(string $email = self::EMAIL, string $password = self::PASSWORD): void
    {
        $this->client->request('GET', '/admin/connexion');
        $this->client->submitForm('Se connecter', ['_username' => $email, '_password' => $password]);
        $this->followAllRedirects();
    }

    protected function followAllRedirects(): void
    {
        while ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }
    }

    /** Code contenu dans le dernier email envoyé pendant la dernière requête. */
    protected function lastSentCode(): string
    {
        $message = self::getMailerMessage(\count(self::getMailerMessages()) - 1);
        self::assertInstanceOf(Email::class, $message);
        self::assertSame(1, preg_match('/<strong>(\d{6})<\/strong>/', (string) $message->getHtmlBody(), $m), 'Code à 6 chiffres absent de l\'email');

        return $m[1];
    }

    protected function submitCode(string $code): void
    {
        $this->client->submitForm('Valider', ['_auth_code' => $code]);
    }

    /** Connexion complète : mot de passe puis code reçu par email. */
    protected function loginFully(): void
    {
        $this->submitLogin();
        $this->submitCode($this->lastSentCode());
    }
}
