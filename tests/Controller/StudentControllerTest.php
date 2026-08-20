<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\TestUsers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class StudentControllerTest extends WebTestCase
{
    public function testScolariteRegistersAStudentWithHisRegistration(): void
    {
        $client = $this->loginAsScolarite();
        $crawler = $client->request('GET', '/symfony/students');

        $client->submit($crawler->filter('form[method="post"]')->form([
            'first_name' => 'Aya',
            'last_name' => 'Kouame',
            'class_name' => $this->firstClassName($crawler),
            'phone' => '07 01 02 03 04',
        ]));

        self::assertResponseRedirects('/symfony/students');
        $client->followRedirect();
        self::assertSelectorTextContains('.notice.success', 'Inscription enregistree');
        self::assertSelectorTextContains('table', 'Aya Kouame');

        $connection = $this->connection();
        $studentId = (int) $connection->fetchOne('SELECT id FROM students WHERE last_name = ? ORDER BY id DESC', ['Kouame']);
        self::assertNotSame(0, $studentId);
        self::assertNotFalse($connection->fetchOne(
            'SELECT registration_number FROM registrations WHERE student_id = ?',
            [$studentId]
        ));
    }

    public function testRegistrationIsRejectedWhenTheFormIsIncomplete(): void
    {
        $client = $this->loginAsScolarite();
        $crawler = $client->request('GET', '/symfony/students');
        $client->request('POST', '/symfony/students', [
            '_token' => $this->tokenOf($crawler, 'form[method="post"]'),
            'first_name' => 'Aya',
            'last_name' => '',
            'class_name' => $this->firstClassName($crawler),
        ]);

        $client->followRedirect();
        self::assertSelectorTextContains('.notice.error', 'obligatoires');
    }

    public function testRegistrationIsRejectedWhenThePhoneNumberIsInvalid(): void
    {
        $client = $this->loginAsScolarite();
        $crawler = $client->request('GET', '/symfony/students');
        $client->request('POST', '/symfony/students', [
            '_token' => $this->tokenOf($crawler, 'form[method="post"]'),
            'first_name' => 'Aya',
            'last_name' => 'Kouame',
            'class_name' => $this->firstClassName($crawler),
            'phone' => 'appelle-moi',
        ]);

        $client->followRedirect();
        self::assertSelectorTextContains('.notice.error', 'telephone est invalide');
    }

    public function testRegistrationIsRejectedWithoutCsrfToken(): void
    {
        $client = $this->loginAsScolarite();
        $before = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM students');

        $client->request('POST', '/symfony/students', [
            'first_name' => 'Sans',
            'last_name' => 'Jeton',
            'class_name' => $this->firstClassName($client->request('GET', '/symfony/students')),
        ]);

        $client->followRedirect();
        self::assertSelectorTextContains('.notice.error', 'Session expiree');
        self::assertSame($before, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM students'));
    }

    public function testEditingAStudentMovesHisRegistrationAndKeepsATransferTrace(): void
    {
        $client = $this->loginAsScolarite();
        $connection = $this->connection();
        $studentId = $this->createStudent($client);
        $registration = $connection->fetchAssociative('SELECT class_id FROM registrations WHERE student_id = ?', [$studentId]);
        self::assertIsArray($registration);

        $crawler = $client->request('GET', '/symfony/students/'.$studentId.'/edit');
        self::assertResponseIsSuccessful();

        $targetClass = $connection->fetchAssociative(
            'SELECT id, name FROM classes WHERE id != ? ORDER BY name LIMIT 1',
            [$registration['class_id']]
        );
        self::assertIsArray($targetClass);

        $client->submit($crawler->filter('form[method="post"]')->form([
            'first_name' => 'Aya',
            'last_name' => 'Kouame-Diallo',
            'class_name' => $targetClass['name'],
            'phone' => '05 05 05 05 05',
            'status' => 'A verifier',
        ]));

        self::assertResponseRedirects('/symfony/students');
        $client->followRedirect();
        self::assertSelectorTextContains('.notice.success', 'mise a jour');

        $student = $connection->fetchAssociative('SELECT * FROM students WHERE id = ?', [$studentId]);
        self::assertIsArray($student);
        self::assertSame('Kouame-Diallo', $student['last_name']);
        self::assertSame('A verifier', $student['status']);
        self::assertSame((int) $targetClass['id'], (int) $connection->fetchOne(
            'SELECT class_id FROM registrations WHERE student_id = ?',
            [$studentId]
        ));
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM transfers WHERE student_id = ?', [$studentId]));
    }

    public function testEditingAnUnknownStudentReturnsNotFound(): void
    {
        $client = $this->loginAsScolarite();
        $client->request('GET', '/symfony/students/999999/edit');

        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingAStudentAlsoRemovesHisRegistration(): void
    {
        $client = $this->loginAsScolarite();
        $studentId = $this->createStudent($client);

        $crawler = $client->request('GET', '/symfony/students');
        $client->submit($crawler->filter('form[action="/symfony/students/'.$studentId.'/delete"]')->form());

        self::assertResponseRedirects('/symfony/students');
        $connection = $this->connection();
        self::assertFalse($connection->fetchOne('SELECT id FROM students WHERE id = ?', [$studentId]));
        self::assertFalse($connection->fetchOne('SELECT id FROM registrations WHERE student_id = ?', [$studentId]));
        self::assertSame(1, (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_logs WHERE action = 'suppression' AND entity_type = 'students' AND entity_id = ?",
            [$studentId]
        ));
    }

    public function testAccountantCannotReachTheStudentForms(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(TestUsers::COMPTABLE));

        $client->request('GET', '/symfony/students/1/edit');
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', '/symfony/students/1/delete');
        self::assertResponseStatusCodeSame(403);
    }

    private function createStudent(KernelBrowser $client): int
    {
        $crawler = $client->request('GET', '/symfony/students');
        $client->submit($crawler->filter('form[method="post"]')->form([
            'first_name' => 'Aya',
            'last_name' => 'Kouame',
            'class_name' => $this->firstClassName($crawler),
            'phone' => '07 01 02 03 04',
        ]));
        $client->followRedirect();

        return (int) $this->connection()->fetchOne('SELECT MAX(id) FROM students');
    }

    private function firstClassName(Crawler $crawler): string
    {
        return $crawler->filter('select[name="class_name"] option')->eq(1)->attr('value') ?? '';
    }

    private function tokenOf(Crawler $crawler, string $selector): string
    {
        return (string) $crawler->filter($selector.' input[name="_token"]')->first()->attr('value');
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function loginAsScolarite(): KernelBrowser
    {
        $client = static::createClient();
        $client->loginUser($this->user(TestUsers::SCOLARITE));

        return $client;
    }

    private function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertNotNull($user);

        return $user;
    }
}
