<?php
declare(strict_types=1);
namespace App\Entity;
use Doctrine\ORM\Mapping as ORM;
#[ORM\Entity]
#[ORM\Table(name: 'tariffs')]
class Tariff { #[ORM\Id, ORM\GeneratedValue, ORM\Column] private int $id; public function getId(): int { return $this->id; } }
