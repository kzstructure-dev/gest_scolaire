<?php

declare(strict_types=1);

namespace App\Tests;

/**
 * Comptes crees par tests/bootstrap.php dans la base SQLite de test.
 */
final class TestUsers
{
    public const PASSWORD = 'MotDePasseDeTest!1';

    public const ADMIN = 'admin@test.local';
    public const SCOLARITE = 'scolarite@test.local';
    public const ENSEIGNANT = 'enseignant@test.local';
    public const COMPTABLE = 'comptable@test.local';

    /**
     * @var array<string, array{0: string, 1: string, 2: string}> email => [role metier, prenoms, nom]
     */
    public const ACCOUNTS = [
        self::ADMIN => ['Administrateur', 'Adama', 'Kone'],
        self::SCOLARITE => ['Scolarite', 'Mariam', 'Bamba'],
        self::ENSEIGNANT => ['Enseignant', 'Kouassi', 'Yao'],
        self::COMPTABLE => ['Comptable', 'Awa', 'Traore'],
    ];
}
