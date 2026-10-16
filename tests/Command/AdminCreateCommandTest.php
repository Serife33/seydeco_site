<?php

namespace App\Tests\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminCreateCommandTest extends KernelTestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        static::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM '.AdminUser::class)->execute();
        $this->tester = new CommandTester((new Application($kernel))->find('app:admin:create'));
    }

    public function testCreatesAdminWithHashedPassword(): void
    {
        $this->tester->setInputs(['secret-12345', 'secret-12345']);

        self::assertSame(Command::SUCCESS, $this->tester->execute(['email' => 'serife@example.com']));

        $admins = static::getContainer()->get(AdminUserRepository::class)->findAll();
        self::assertCount(1, $admins);
        self::assertSame('serife@example.com', $admins[0]->getEmail());
        self::assertSame(['ROLE_ADMIN'], $admins[0]->getRoles());
        self::assertNotSame('secret-12345', $admins[0]->getPassword());
        self::assertTrue(static::getContainer()->get('security.user_password_hasher')->isPasswordValid($admins[0], 'secret-12345'));
    }

    public function testRefusesSecondAdmin(): void
    {
        $this->tester->setInputs(['secret-12345', 'secret-12345']);
        $this->tester->execute(['email' => 'serife@example.com']);

        $this->tester->setInputs(['autre-456789', 'autre-456789']);
        $status = $this->tester->execute(['email' => 'autre@example.com']);

        self::assertNotSame(Command::SUCCESS, $status);
        self::assertStringContainsString('existe déjà', $this->tester->getDisplay());
        self::assertSame(1, static::getContainer()->get(AdminUserRepository::class)->count());
    }

    public function testRefusesInvalidEmail(): void
    {
        $this->tester->setInputs(['secret-12345', 'secret-12345']);

        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'pas-un-email']));
        self::assertStringContainsString('Adresse email invalide', $this->tester->getDisplay());
        self::assertSame(0, static::getContainer()->get(AdminUserRepository::class)->count());
    }

    public function testRefusesEmptyPassword(): void
    {
        $this->tester->setInputs(['', '']);

        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'serife@example.com']));
        self::assertStringContainsString('au moins 12 caractères', $this->tester->getDisplay());
        self::assertSame(0, static::getContainer()->get(AdminUserRepository::class)->count());
    }

    public function testRefusesPasswordShorterThan12Characters(): void
    {
        $this->tester->setInputs(['onze-carac1', 'onze-carac1']);

        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'serife@example.com']));
        self::assertStringContainsString('au moins 12 caractères', $this->tester->getDisplay());
        self::assertSame(0, static::getContainer()->get(AdminUserRepository::class)->count());
    }

    public function testRefusesMismatchedConfirmation(): void
    {
        $this->tester->setInputs(['secret-12345', 'autre-chose']);

        self::assertSame(Command::FAILURE, $this->tester->execute(['email' => 'serife@example.com']));
        self::assertSame(0, static::getContainer()->get(AdminUserRepository::class)->count());
    }
}
