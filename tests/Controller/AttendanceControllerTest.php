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

final class AttendanceControllerTest extends WebTestCase
{
    private const DATE = '2026-01-12';

    public function testTeacherSavesTheDailySheetAndSeesTheCounters(): void
    {
        $client = $this->loginAs(TestUsers::ENSEIGNANT);
        [$classId, $studentId] = $this->classWithStudent();

        $crawler = $this->openSheet($client, $classId);
        $client->submit($crawler->filter('form[method="post"]')->form([
            'status['.$studentId.']' => 'Absent',
            'reason['.$studentId.']' => 'Maladie',
        ]));

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.notice.success', 'Feuille du '.self::DATE);

        $line = $this->connection()->fetchAssociative(
            'SELECT * FROM attendance WHERE student_id = ? AND class_id = ? AND attendance_date = ?',
            [$studentId, $classId, self::DATE]
        );
        self::assertIsArray($line);
        self::assertSame('Absent', $line['status']);
        self::assertSame('Maladie', $line['reason']);
        self::assertSame(0, (int) $line['justified']);
        self::assertSame(
            $this->user(TestUsers::ENSEIGNANT)->getId(),
            (int) $line['validated_by']
        );
        self::assertStringContainsString('Absences a justifier', $crawler->filter('body')->text());
    }

    public function testSavingTheSheetTwiceUpdatesTheSameLine(): void
    {
        $client = $this->loginAs(TestUsers::ENSEIGNANT);
        [$classId, $studentId] = $this->classWithStudent();

        $this->submitSheet($client, $classId, $studentId, ['status['.$studentId.']' => 'Absent']);
        $this->submitSheet($client, $classId, $studentId, [
            'status['.$studentId.']' => 'Retard',
            'minutes_late['.$studentId.']' => '15',
        ]);

        $lines = $this->connection()->fetchAllAssociative(
            'SELECT status, minutes_late FROM attendance WHERE student_id = ? AND attendance_date = ?',
            [$studentId, self::DATE]
        );
        self::assertCount(1, $lines);
        self::assertSame('Retard', $lines[0]['status']);
        self::assertSame(15, (int) $lines[0]['minutes_late']);
    }

    public function testStudentsOutsideTheClassAreIgnored(): void
    {
        $client = $this->loginAs(TestUsers::ENSEIGNANT);
        [$classId] = $this->classWithStudent();
        $intruder = (int) $this->connection()->fetchOne(
            'SELECT s.id FROM students s
             LEFT JOIN registrations r ON r.student_id = s.id AND r.class_id = ?
             WHERE r.id IS NULL LIMIT 1',
            [$classId]
        );
        self::assertNotSame(0, $intruder);

        $crawler = $this->openSheet($client, $classId);
        $client->request('POST', '/symfony/attendance', [
            '_token' => $this->tokenOf($crawler),
            'date' => self::DATE,
            'class_id' => $classId,
            'status' => [$intruder => 'Absent'],
        ]);
        $client->followRedirect();

        self::assertFalse($this->connection()->fetchOne(
            'SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ?',
            [$intruder, self::DATE]
        ));
    }

    public function testSheetIsRejectedWithoutCsrfToken(): void
    {
        $client = $this->loginAs(TestUsers::ENSEIGNANT);
        [$classId, $studentId] = $this->classWithStudent();

        $client->request('POST', '/symfony/attendance', [
            'date' => self::DATE,
            'class_id' => $classId,
            'status' => [$studentId => 'Absent'],
        ]);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'Session expiree');
        self::assertFalse($this->connection()->fetchOne(
            'SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ?',
            [$studentId, self::DATE]
        ));
    }

    public function testAbsenceCanBeJustifiedAndLeavesTheRecap(): void
    {
        $client = $this->loginAs(TestUsers::ENSEIGNANT);
        [$classId, $studentId] = $this->classWithStudent();
        $this->submitSheet($client, $classId, $studentId, ['status['.$studentId.']' => 'Absent']);

        $crawler = $this->openSheet($client, $classId);
        $client->submit($crawler->filter('.justify-form')->first()->form(['reason' => 'Certificat medical']));

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('.notice.success', 'Absence justifiee');

        $line = $this->connection()->fetchAssociative(
            'SELECT justified, reason FROM attendance WHERE student_id = ? AND attendance_date = ?',
            [$studentId, self::DATE]
        );
        self::assertIsArray($line);
        self::assertSame(1, (int) $line['justified']);
        self::assertSame('Certificat medical', $line['reason']);
    }

    public function testJustificationRequiresAReason(): void
    {
        $client = $this->loginAs(TestUsers::ENSEIGNANT);
        [$classId, $studentId] = $this->classWithStudent();
        $this->submitSheet($client, $classId, $studentId, ['status['.$studentId.']' => 'Absent']);
        $attendanceId = (int) $this->connection()->fetchOne(
            'SELECT id FROM attendance WHERE student_id = ? AND attendance_date = ?',
            [$studentId, self::DATE]
        );

        $crawler = $this->openSheet($client, $classId);
        $client->request('POST', '/symfony/attendance/'.$attendanceId.'/justify', [
            '_token' => (string) $crawler->filter('.justify-form input[name="_token"]')->first()->attr('value'),
            'class_id' => $classId,
            'date' => self::DATE,
            'reason' => '   ',
        ]);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'motif de justification est obligatoire');
    }

    public function testMonthlyRecapCountsAbsencesAndLateMinutes(): void
    {
        $client = $this->loginAs(TestUsers::ENSEIGNANT);
        [$classId, $studentId] = $this->classWithStudent();
        $this->submitSheet($client, $classId, $studentId, ['status['.$studentId.']' => 'Absent']);
        $this->submitSheet($client, $classId, $studentId, [
            'status['.$studentId.']' => 'Retard',
            'minutes_late['.$studentId.']' => '20',
        ], '2026-01-13');

        $crawler = $client->request('GET', '/symfony/attendance?class_id='.$classId.'&date='.self::DATE);
        $recap = $crawler->filter('h2:contains("Cumul du mois")')->closest('section')->filter('tbody tr')->first();

        self::assertSame('1', trim($recap->filter('td')->eq(1)->text()));
        self::assertSame('1', trim($recap->filter('td')->eq(3)->text()));
        self::assertSame('20', trim($recap->filter('td')->eq(4)->text()));
    }

    public function testAccountantCannotJustifyAnAbsence(): void
    {
        $client = static::createClient();
        $client->loginUser($this->user(TestUsers::COMPTABLE));
        $client->request('POST', '/symfony/attendance/1/justify');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $values
     */
    private function submitSheet(KernelBrowser $client, int $classId, int $studentId, array $values, ?string $date = null): void
    {
        $crawler = $this->openSheet($client, $classId, $date);
        $client->submit($crawler->filter('form[method="post"]')->form($values));
        $client->followRedirect();
    }

    private function openSheet(KernelBrowser $client, int $classId, ?string $date = null): Crawler
    {
        return $client->request('GET', '/symfony/attendance?class_id='.$classId.'&date='.($date ?? self::DATE));
    }

    private function tokenOf(Crawler $crawler): string
    {
        return (string) $crawler->filter('form[method="post"] input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function classWithStudent(): array
    {
        $row = $this->connection()->fetchAssociative(
            "SELECT class_id, student_id FROM registrations
             WHERE school_year_id = 1 AND status = 'Validee' ORDER BY id LIMIT 1"
        );
        self::assertIsArray($row);

        return [(int) $row['class_id'], (int) $row['student_id']];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function loginAs(string $email): KernelBrowser
    {
        $client = static::createClient();
        $this->connection()->executeStatement('DELETE FROM attendance');
        $client->loginUser($this->user($email));

        return $client;
    }

    private function user(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail($email);
        self::assertNotNull($user);

        return $user;
    }
}
