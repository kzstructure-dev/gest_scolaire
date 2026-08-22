<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\TestUsers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testUnauthenticatedVisitorIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/symfony/students');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testLoginPageIsPublic(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="_username"]'));
        self::assertCount(1, $crawler->filter('input[name="_password"]'));
    }

    public function testInvalidCredentialsKeepTheVisitorOnTheLoginPage(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, TestUsers::ADMIN, 'mauvais-mot-de-passe');

        self::assertResponseRedirects('http://localhost/login');
        $client->followRedirect();
        self::assertSelectorExists('.error');
    }

    public function testSuccessfulLoginRecordsTheConnection(): void
    {
        $client = static::createClient();

        /** @var Connection $connection */
        $connection = static::getContainer()->get(Connection::class);
        $userId = (int) $connection->fetchOne('SELECT id FROM users WHERE email = ?', [TestUsers::SCOLARITE]);
        $before = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'connexion' AND user_id = ?",
            [$userId]
        );

        $this->submitLogin($client, TestUsers::SCOLARITE, TestUsers::PASSWORD);

        self::assertResponseRedirects();
        self::assertNotNull($connection->fetchOne('SELECT last_login_at FROM users WHERE id = ?', [$userId]));
        self::assertSame($before + 1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'connexion' AND user_id = ?",
            [$userId]
        ));
    }

    public function testLoggedInUserIsDisplayedInTheTopBar(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, TestUsers::COMPTABLE, TestUsers::PASSWORD);
        $client->request('GET', '/symfony/finance');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.profile', 'Awa Traore · Comptable');
    }

    public function testLogoutClosesTheSession(): void
    {
        $client = static::createClient();
        $this->submitLogin($client, TestUsers::ADMIN, TestUsers::PASSWORD);

        $client->request('GET', '/logout');
        self::assertResponseRedirects('http://localhost/login');

        $client->request('GET', '/symfony/students');
        self::assertResponseRedirects('http://localhost/login');
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $client->submit($crawler->selectButton('Se connecter')->form([
            '_username' => $email,
            '_password' => $password,
        ]));
    }
}
