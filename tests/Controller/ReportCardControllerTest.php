<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Service\ReportCardManager;
use App\Tests\TestUsers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class ReportCardControllerTest extends WebTestCase
{
    public function testGenerationComputesAverageRankAppreciationAndDecision(): void
    {
        $client = $this->login();
        [$classId, $periodId, $students] = $this->classWithTwoStudents();
        $this->grade($classId, $periodId, $students[0], 16.0);
        $this->grade($classId, $periodId, $students[1], 7.0);

        $this->generate($client, $classId, $periodId);

        self::assertSelectorTextContains('.notice.success', 'bulletin(s) calcule(s).');

        $best = $this->card($students[0], $periodId);
        self::assertSame(16.0, (float) $best['average']);
        self::assertSame(1, (int) $best['rank']);
        self::assertSame('Excellent', $best['appreciation']);
        self::assertSame('Admis', $best['decision']);
        self::assertSame('Calcule', $best['status']);

        $last = $this->card($students[1], $periodId);
        self::assertSame(7.0, (float) $last['average']);
        self::assertSame(2, (int) $last['rank']);
        self::assertSame('Insuffisant', $last['appreciation']);
        self::assertSame('Redouble', $last['decision']);
    }

    public function testGradesAreNormalizedOnTwentyAndWeightedByEvaluationCoefficient(): void
    {
        $client = $this->login();
        [$classId, $periodId, $students] = $this->classWithTwoStudents();
        $this->grade($classId, $periodId, $students[0], 30.0, scale: 40.0, coefficient: 3.0);
        $this->grade($classId, $periodId, $students[0], 5.0, scale: 10.0, coefficient: 1.0);

        $this->generate($client, $classId, $periodId);

        // (15 * 3 + 10 * 1) / 4 = 13.75
        self::assertSame(13.75, (float) $this->card($students[0], $periodId)['average']);
    }

    public function testStudentWithoutGradeHasNoAverageAndNoDecision(): void
    {
        $client = $this->login();
        [$classId, $periodId, $students] = $this->classWithTwoStudents();
        $this->grade($classId, $periodId, $students[0], 12.0);

        $this->generate($client, $classId, $periodId);

        $card = $this->card($students[1], $periodId);
        self::assertNull($card['average']);
        self::assertNull($card['rank']);
        self::assertNull($card['decision']);
        self::assertSame('Notes insuffisantes', $card['appreciation']);
    }

    public function testGenerationIsIdempotent(): void
    {
        $client = $this->login();
        [$classId, $periodId, $students] = $this->classWithTwoStudents();
        $this->grade($classId, $periodId, $students[0], 11.0);

        $this->generate($client, $classId, $periodId);
        $this->generate($client, $classId, $periodId);

        self::assertSame(
            count($students),
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM report_cards WHERE period_id = ?', [$periodId])
        );
    }

    public function testGenerationIsRefusedWithoutCsrfToken(): void
    {
        $client = $this->login();
        [$classId, $periodId] = $this->classWithTwoStudents();

        $client->request('POST', '/symfony/report-cards', ['class_id' => $classId, 'period_id' => $periodId]);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'Session expiree');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM report_cards'));
    }

    public function testGenerationIsRefusedWithoutClass(): void
    {
        $client = $this->login();
        $crawler = $client->request('GET', '/symfony/report-cards');
        $client->request('POST', '/symfony/report-cards', [
            '_token' => $this->tokenOf($crawler, 'report_cards_generate'),
            'class_id' => '0',
            'period_id' => '0',
        ]);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'Selectionnez une classe et une periode.');
    }

    public function testPublicationOnlyAffectsComputedCardsOfTheClass(): void
    {
        $client = $this->login();
        [$classId, $periodId, $students] = $this->classWithTwoStudents();
        $this->grade($classId, $periodId, $students[0], 14.0);
        $this->generate($client, $classId, $periodId);

        $this->publish($client, $classId, $periodId);

        self::assertSelectorTextContains('.notice.success', 'bulletin(s) publie(s).');
        $card = $this->card($students[0], $periodId);
        self::assertSame('Publie', $card['status']);
        self::assertNotNull($card['published_at']);
    }

    public function testPublicationIsRefusedWhenNoCardHasBeenComputed(): void
    {
        $client = $this->login();
        [$classId, $periodId] = $this->classWithTwoStudents();

        $this->publish($client, $classId, $periodId);

        self::assertSelectorTextContains('.notice.error', 'Aucun bulletin calcule a publier');
    }

    public function testDetailShowsSubjectAveragesAndAbsences(): void
    {
        $client = $this->login();
        [$classId, $periodId, $students] = $this->classWithTwoStudents();
        $this->grade($classId, $periodId, $students[0], 15.0);
        $this->generate($client, $classId, $periodId);
        $cardId = (int) $this->card($students[0], $periodId)['id'];

        $crawler = $client->request('GET', '/symfony/report-cards/'.$cardId);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('tbody tr')->count());
        self::assertSelectorTextContains('body', 'Decision');
    }

    public function testUnknownReportCardReturnsNotFound(): void
    {
        $client = $this->login();
        $client->request('GET', '/symfony/report-cards/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAccountantCannotReachReportCards(): void
    {
        $client = static::createClient();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::COMPTABLE);
        self::assertNotNull($user);
        $client->loginUser($user);

        $client->request('GET', '/symfony/report-cards');

        self::assertResponseStatusCodeSame(403);
    }

    private function generate(KernelBrowser $client, int $classId, int $periodId): void
    {
        $crawler = $client->request('GET', '/symfony/report-cards');
        $client->request('POST', '/symfony/report-cards', [
            '_token' => $this->tokenOf($crawler, 'report_cards_generate'),
            'class_id' => (string) $classId,
            'period_id' => (string) $periodId,
        ]);
        $client->followRedirect();
    }

    private function publish(KernelBrowser $client, int $classId, int $periodId): void
    {
        $crawler = $client->request('GET', '/symfony/report-cards');
        $client->request('POST', '/symfony/report-cards', [
            '_token' => $this->tokenOf($crawler, 'report_cards_publish'),
            'action' => 'publish',
            'class_id' => (string) $classId,
            'period_id' => (string) $periodId,
        ]);
        $client->followRedirect();
    }

    private function tokenOf(Crawler $crawler, string $formToken): string
    {
        $selector = $formToken === 'report_cards_publish' ? 'form.publish-form' : 'form.form-grid';

        return (string) $crawler->filter($selector.' input[name="_token"]')->first()->attr('value');
    }

    /**
     * @return array<string, mixed>
     */
    private function card(int $studentId, int $periodId): array
    {
        $card = $this->connection()->fetchAssociative(
            'SELECT * FROM report_cards WHERE student_id = ? AND period_id = ?',
            [$studentId, $periodId]
        );
        self::assertIsArray($card);

        return $card;
    }

    private function grade(
        int $classId,
        int $periodId,
        int $studentId,
        float $value,
        float $scale = 20.0,
        float $coefficient = 1.0,
    ): void {
        $connection = $this->connection();
        $connection->insert('evaluations', [
            'class_id' => $classId,
            'subject_id' => $connection->fetchOne('SELECT id FROM subjects ORDER BY id LIMIT 1'),
            'period_id' => $periodId,
            'title' => 'Evaluation de test',
            'evaluation_type' => 'Devoir',
            'evaluation_date' => '2026-01-15',
            'scale' => $scale,
            'coefficient' => $coefficient,
            'status' => 'Complete',
        ]);
        $connection->insert('grades', [
            'evaluation_id' => (int) $connection->lastInsertId(),
            'student_id' => $studentId,
            'value' => $value,
            'status' => 'Saisie',
        ]);
    }

    /**
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function classWithTwoStudents(): array
    {
        $connection = $this->connection();
        $registrations = $connection->fetchAllAssociative(
            "SELECT id, student_id, class_id FROM registrations
             WHERE school_year_id = ? AND status = 'Validee' ORDER BY student_id LIMIT 2",
            [ReportCardManager::SCHOOL_YEAR_ID]
        );
        self::assertCount(2, $registrations);

        $classId = (int) $registrations[0]['class_id'];
        $connection->update('registrations', ['class_id' => $classId], ['id' => $registrations[1]['id']]);

        $students = array_map('intval', $connection->fetchFirstColumn(
            "SELECT student_id FROM registrations
             WHERE class_id = ? AND school_year_id = ? AND status = 'Validee' ORDER BY student_id",
            [$classId, ReportCardManager::SCHOOL_YEAR_ID]
        ));

        $periodId = (int) $connection->fetchOne(
            'SELECT id FROM periods WHERE school_year_id = ? ORDER BY start_date LIMIT 1',
            [ReportCardManager::SCHOOL_YEAR_ID]
        );

        return [$classId, $periodId, $students];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function login(): KernelBrowser
    {
        $client = static::createClient();
        $connection = $this->connection();
        $connection->executeStatement('DELETE FROM report_cards');
        $connection->executeStatement('DELETE FROM grades');
        $connection->executeStatement('DELETE FROM evaluations');
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::ENSEIGNANT);
        self::assertNotNull($user);
        $client->loginUser($user);

        return $client;
    }
}
