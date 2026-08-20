<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(name: 'establishment_id')]
    private int $establishmentId = 1;

    #[ORM\ManyToOne(targetEntity: Role::class)]
    #[ORM\JoinColumn(name: 'role_id', referencedColumnName: 'id', nullable: false)]
    private Role $role;

    #[ORM\Column(name: 'first_name', length: 120)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 120)]
    private string $lastName;

    #[ORM\Column(length: 180, unique: true)]
    private string $email;

    #[ORM\Column(name: 'password_hash')]
    private string $passwordHash;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(name: 'last_login_at', nullable: true)]
    private ?string $lastLoginAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getEstablishmentId(): int
    {
        return $this->establishmentId;
    }

    public function setEstablishmentId(int $establishmentId): void
    {
        $this->establishmentId = $establishmentId;
    }

    public function getRole(): Role
    {
        return $this->role;
    }

    public function setRole(Role $role): void
    {
        $this->role = $role;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): void
    {
        $this->firstName = $firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): void
    {
        $this->lastName = $lastName;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $this->email = $email;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): void
    {
        $this->active = $active;
    }

    public function getLastLoginAt(): ?string
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?string $lastLoginAt): void
    {
        $this->lastLoginAt = $lastLoginAt;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function setPassword(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_values(array_unique(['ROLE_USER', $this->role->getSecurityRole()]));
    }

    public function eraseCredentials(): void
    {
    }
}
