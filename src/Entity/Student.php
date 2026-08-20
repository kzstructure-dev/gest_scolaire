<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'students')]
class Student
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private int $id;

    #[ORM\Column(name: 'first_name', length: 120)]
    private string $firstName;

    #[ORM\Column(name: 'last_name', length: 120)]
    private string $lastName;

    #[ORM\Column(name: 'class_name', length: 80)]
    private string $className;

    #[ORM\Column(length: 40)]
    private string $cycle = 'Secondaire';

    #[ORM\Column(length: 30)]
    private string $phone = '';

    #[ORM\Column(length: 40)]
    private string $status = 'Inscrit';

    public function getId(): int
    {
        return $this->id;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getCycle(): string
    {
        return $this->cycle;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function getStatus(): string
    {
        return $this->status;
    }
}
