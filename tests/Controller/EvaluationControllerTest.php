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

final class EvaluationControllerTest extends WebTestCase
{
    public function testTeacherCreatesAnEvaluationAndLandsOnTheGradeSheet(): void
    {
        $client = $this->login();
        [$classId] = $this->classWithStudent();

        $client->request('POST', '/symfony/evaluations', $this->payload($client, $classId));

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Devoir de mathematiques');

        $evaluation = $this->connection()->fetchAssociative('SELECT * FROM evaluations ORDER BY id DESC LIMIT 1');
        self::assertIsArray($evaluation);
        self::assertSame('Brouillon', $evaluation['status']);
        self::assertSame(20.0, (float) $evaluation['scale']);
    }

    public function testEvaluationIsRejectedWhenTheScaleIsNotPositive(): void
    {
        $client = $this->login();
        [$classId] = $this->classWithStudent();

        $client->request('POST', '/symfony/evaluations', $this->payload($client, $classId, ['scale' => '0']));
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'bareme et le coefficient');
    }

    public function testEvaluationIsRejectedWhenTheSubjectDoesNotExist(): void
    {
        $client = $this->login();
        [$classId] = $this->classWithStudent();

        $client->request('POST', '/symfony/evaluations', $this->payload($client, $classId, ['subject_id' => '999999']));
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'matiere selectionnee n\'existe pas');
    }

    public function testEvaluationIsRejectedWithoutCsrfToken(): void
    {
        $client = $this->login();
        [$classId] = $this->classWithStudent();
        $before = (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM evaluations');

        $payload = $this->payload($client, $classId);
        unset($payload['_token']);
        $client->request('POST', '/symfony/evaluations', $payload);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'Session expiree');
        self::assertSame($before, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM evaluations'));
    }

    public function testGradesAreSavedNormalizedAndTraced(): void
    {
        $client = $this->login();
        [$classId, $studentId] = $this->classWithStudent();
        $evaluationId = $this->createEvaluation($client, $classId, ['scale' => '40']);

        $crawler = $client->request('GET', '/symfony/evaluations/'.$evaluationId);
        $client->submit($crawler->filter('form[method="post"]')->form([
            'value['.$studentId.']' => '30',
            'appreciation['.$studentId.']' => 'Bon travail',
        ]));

        self::assertResponseRedirects();
        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('.notice.success', '1 note(s) enregistree(s).');
        self::assertStringContainsString('15', $crawler->filter('tbody tr')->first()->filter('td')->eq(2)->text());

        $grade = $this->connection()->fetchAssociative(
            'SELECT * FROM grades WHERE evaluation_id = ? AND student_id = ?',
            [$evaluationId, $studentId]
        );
        self::assertIsArray($grade);
        self::assertSame(30.0, (float) $grade['value']);
        self::assertSame('Bon travail', $grade['appreciation']);
        self::assertSame($this->user()->getId(), (int) $grade['validated_by']);
    }

    public function testGradeAboveTheScaleIsRefusedAndNothingIsStored(): void
    {
        $client = $this->login();
        [$classId, $studentId] = $this->classWithStudent();
        $evaluationId = $this->createEvaluation($client, $classId);

        $crawler = $client->request('GET', '/symfony/evaluations/'.$evaluationId);
        $client->submit($crawler->filter('form[method="post"]')->form(['value['.$studentId.']' => '25']));
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'comprise entre 0 et 20');
        self::assertFalse($this->connection()->fetchOne(
            'SELECT id FROM grades WHERE evaluation_id = ?',
            [$evaluationId]
        ));
    }

    public function testEmptyGradeRemovesThePreviousOne(): void
    {
        $client = $this->login();
        [$classId, $studentId] = $this->classWithStudent();
        $evaluationId = $this->createEvaluation($client, $classId);

        $this->submitGrades($client, $evaluationId, ['value['.$studentId.']' => '12']);
        self::assertNotFalse($this->connection()->fetchOne('SELECT id FROM grades WHERE evaluation_id = ?', [$evaluationId]));

        $this->submitGrades($client, $evaluationId, ['value['.$studentId.']' => '']);
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM grades WHERE evaluation_id = ?', [$evaluationId]));
    }

    public function testGradesOfStudentsOutsideTheClassAreIgnored(): void
    {
        $client = $this->login();
        [$classId] = $this->classWithStudent();
        $evaluationId = $this->createEvaluation($client, $classId);
        $intruder = (int) $this->connection()->fetchOne(
            'SELECT s.id FROM students s
             LEFT JOIN registrations r ON r.student_id = s.id AND r.class_id = ?
             WHERE r.id IS NULL LIMIT 1',
            [$classId]
        );

        $crawler = $client->request('GET', '/symfony/evaluations/'.$evaluationId);
        $client->request('POST', '/symfony/evaluations/'.$evaluationId, [
            '_token' => $this->tokenOf($crawler),
            'value' => [$intruder => '18'],
        ]);
        $client->followRedirect();

        self::assertFalse($this->connection()->fetchOne(
            'SELECT id FROM grades WHERE evaluation_id = ? AND student_id = ?',
            [$evaluationId, $intruder]
        ));
    }

    public function testDeletingAnEvaluationRemovesItsGrades(): void
    {
        $client = $this->login();
        [$classId, $studentId] = $this->classWithStudent();
        $evaluationId = $this->createEvaluation($client, $classId);
        $this->submitGrades($client, $evaluationId, ['value['.$studentId.']' => '11']);

        $crawler = $client->request('GET', '/symfony/evaluations');
        $client->submit($crawler->filter('form[action="/symfony/evaluations/'.$evaluationId.'/delete"]')->form());
        $client->followRedirect();

        self::assertFalse($this->connection()->fetchOne('SELECT id FROM evaluations WHERE id = ?', [$evaluationId]));
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM grades WHERE evaluation_id = ?', [$evaluationId]));
    }

    public function testUnknownEvaluationReturnsNotFound(): void
    {
        $client = $this->login();
        $client->request('GET', '/symfony/evaluations/999999');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function payload(KernelBrowser $client, int $classId, array $overrides = []): array
    {
        $crawler = $client->request('GET', '/symfony/evaluations');
        $connection = $this->connection();

        return array_merge([
            '_token' => $this->tokenOf($crawler),
            'title' => 'Devoir de mathematiques',
            'class_id' => (string) $classId,
            'subject_id' => (string) $connection->fetchOne('SELECT id FROM subjects WHERE active = 1 ORDER BY id LIMIT 1'),
            'period_id' => (string) $connection->fetchOne('SELECT id FROM periods WHERE school_year_id = 1 ORDER BY start_date LIMIT 1'),
            'evaluation_type' => 'Devoir',
            'evaluation_date' => '2026-01-15',
            'scale' => '20',
            'coefficient' => '2',
        ], $overrides);
    }

    /**
     * @param array<string, string> $overrides
     */
    private function createEvaluation(KernelBrowser $client, int $classId, array $overrides = []): int
    {
        $client->request('POST', '/symfony/evaluations', $this->payload($client, $classId, $overrides));
        $client->followRedirect();

        return (int) $this->connection()->fetchOne('SELECT MAX(id) FROM evaluations');
    }

    /**
     * @param array<string, string> $values
     */
    private function submitGrades(KernelBrowser $client, int $evaluationId, array $values): void
    {
        $crawler = $client->request('GET', '/symfony/evaluations/'.$evaluationId);
        $client->submit($crawler->filter('form[method="post"]')->form($values));
        $client->followRedirect();
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

    private function login(): KernelBrowser
    {
        $client = static::createClient();
        $this->connection()->executeStatement('DELETE FROM grades');
        $this->connection()->executeStatement('DELETE FROM evaluations');
        $client->loginUser($this->user());

        return $client;
    }

    private function user(): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::ENSEIGNANT);
        self::assertNotNull($user);

        return $user;
    }
}
