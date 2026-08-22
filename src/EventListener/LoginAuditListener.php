<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Journalise les connexions reussies dans audit_logs et met a jour la date de
 * derniere connexion, comme demande par les regles de securite du projet.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final class LoginAuditListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Abidjan')))->format('Y-m-d H:i:s');

        $this->connection->update('users', ['last_login_at' => $now], ['id' => $user->getId()]);
        $this->connection->insert('audit_logs', [
            'user_id' => $user->getId(),
            'action' => 'connexion',
            'entity_type' => 'users',
            'entity_id' => $user->getId(),
            'created_at' => $now,
        ]);
    }
}
