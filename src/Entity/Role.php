<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'roles')]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(length: 80, unique: true)]
    private string $name;

    #[ORM\Column]
    private string $description = '';

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): void
    {
        $this->description = $description;
    }

    /**
     * Nom du role metier converti en role de securite Symfony, par exemple
     * "Scolarite" devient "ROLE_SCOLARITE".
     */
    public function getSecurityRole(): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT', $this->name);
        $normalized = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $ascii !== false ? $ascii : $this->name));

        return 'ROLE_' . trim($normalized, '_');
    }
}
