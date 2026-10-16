<?php

namespace App\Entity;

use App\Repository\AdminUserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: AdminUserRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_admin_user_email', fields: ['email'])]
class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface, TwoFactorInterface
{
    public const string AUTH_CODE_TTL = '+15 minutes';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column]
    private string $password;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $authCode = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $authCodeExpiresAt = null;

    public function __construct(string $email, string $hashedPassword)
    {
        $this->email = $email;
        $this->password = $hashedPassword;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** Un seul rôle possible : le compte unique de Serife. */
    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // @deprecated, à retirer avec Symfony 8
    }

    public function isEmailAuthEnabled(): bool
    {
        return true;
    }

    public function getEmailAuthRecipient(): string
    {
        return $this->email;
    }

    /** Un code expiré est traité comme absent : scheb le refuse alors comme un code faux. */
    public function getEmailAuthCode(): ?string
    {
        if (null === $this->authCodeExpiresAt || $this->authCodeExpiresAt < new \DateTimeImmutable()) {
            return null;
        }

        return $this->authCode;
    }

    public function setEmailAuthCode(string $authCode): void
    {
        $this->authCode = $authCode;
        $this->authCodeExpiresAt = new \DateTimeImmutable(self::AUTH_CODE_TTL);
    }

    public function clearEmailAuthCode(): void
    {
        $this->authCode = null;
        $this->authCodeExpiresAt = null;
    }

    /** Ne jamais garder le code ni le hash du mot de passe dans la session sérialisée. */
    public function __serialize(): array
    {
        return ['id' => $this->id, 'email' => $this->email, 'password' => hash('crc32c', $this->password)];
    }

    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->email = $data['email'];
        $this->password = $data['password'];
    }
}
