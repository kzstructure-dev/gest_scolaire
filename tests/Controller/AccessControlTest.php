<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\TestUsers;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class AccessControlTest extends WebTestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: int}>
     */
    public static function moduleAccessProvider(): iterable
    {
        $modules = [
            'tableau de bord' => '/',
            'eleves' => '/symfony/students',
            'classes' => '/symfony/classes',
            'presences' => '/symfony/attendance',
            'notes' => '/symfony/evaluations',
            'bulletins' => '/symfony/report-cards',
            'finances' => '/symfony/finance',
        ];

        $expectations = [
            TestUsers::ADMIN => ['tableau de bord', 'eleves', 'classes', 'presences', 'notes', 'bulletins', 'finances'],
            TestUsers::SCOLARITE => ['tableau de bord', 'eleves', 'classes', 'presences', 'notes', 'bulletins'],
            TestUsers::ENSEIGNANT => ['tableau de bord', 'classes', 'presences', 'notes', 'bulletins'],
            TestUsers::COMPTABLE => ['tableau de bord', 'finances'],
        ];

        foreach ($expectations as $email => $allowed) {
            foreach ($modules as $module => $path) {
                $granted = in_array($module, $allowed, true);
                $status = $granted ? Response::HTTP_OK : Response::HTTP_FORBIDDEN;

                yield sprintf('%s %s %s', $email, $granted ? 'accede a' : 'ne voit pas', $module) => [$email, $path, $status];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moduleAccessProvider')]
    public function testModuleAccessDependsOnTheRole(string $email, string $path, int $expectedStatus): void
    {
        $client = static::createClient();
        $client->loginUser($this->user($email));
        $client->request('GET', $path);

        self::assertResponseStatusCodeSame($expectedStatus);
    }

    public function testSidebarOnlyLinksToAuthorisedModules(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(TestUsers::COMPTABLE));
        $crawler = $client->request('GET', '/');

        $links = $crawler->filter('nav a')->extract(['href']);

        self::assertContains('/symfony/finance', $links);
        self::assertNotContains('/symfony/students', $links);
        self::assertNotContains('/symfony/evaluations', $links);
    }

    public function testDashboardGreetsTheLoggedInUser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(TestUsers::ENSEIGNANT));
        $client->request('GET', '/');

        self::assertSelectorTextContains('h1', 'Bonjour Kouassi,');
    }

    private function user(string $email): \App\Entity\User
    {
        $user = static::getContainer()
            ->get(\App\Repository\UserRepository::class)
            ->findOneByEmail($email);

        self::assertNotNull($user, sprintf('Le compte de test "%s" est absent.', $email));

        return $user;
    }
}
