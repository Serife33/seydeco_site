<?php

namespace App\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsCommand(name: 'app:admin:create', description: 'Crée le compte administrateur unique du back-office')]
final class AdminCreateCommand
{
    private const int MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly AdminUserRepository $admins,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Adresse email de connexion')] string $email): int
    {
        if ($this->admins->hasAny()) {
            $io->error('Un compte administrateur existe déjà. Un seul compte est autorisé.');

            return Command::FAILURE;
        }

        $email = trim($email);
        if (\count($this->validator->validate($email, [new NotBlank(), new Email()])) > 0) {
            $io->error('Adresse email invalide.');

            return Command::FAILURE;
        }

        $password = (string) $io->askHidden('Mot de passe');
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $io->error(\sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_PASSWORD_LENGTH));

            return Command::FAILURE;
        }
        if ($password !== (string) $io->askHidden('Confirmez le mot de passe')) {
            $io->error('Les deux mots de passe ne correspondent pas.');

            return Command::FAILURE;
        }

        $admin = new AdminUser($email, '');
        $admin->setPassword($this->hasher->hashPassword($admin, $password));
        $this->em->persist($admin);
        $this->em->flush();

        $io->success(\sprintf('Compte administrateur créé pour %s.', $email));

        return Command::SUCCESS;
    }
}
