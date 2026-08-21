<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Tests\TestUsers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TeacherControllerTest extends WebTestCase
{
    public function testTeacherIsCreatedWithStaffRecordAndGeneratedNumber(): void
    {
        $client = $this->login();

        $this->post($client, 'create', [
            'first_name' => 'Sekou',
            'last_name' => 'Diarra',
            'specialty' => 'Mathematiques',
            'phone' => '0700000000',
            'email' => 'sekou.diarra@test.local',
        ]);

        self::assertSelectorTextContains('.notice.success', 'Enseignant enregistre.');
        $teacher = $this->connection()->fetchAssociative(
            'SELECT t.registration_number, t.specialty, s.staff_type, s.email
             FROM teachers t INNER JOIN staff s ON s.id = t.staff_id
             WHERE s.last_name = ?',
            ['Diarra']
        );
        self::assertIsArray($teacher);
        self::assertSame('Enseignant', $teacher['staff_type']);
        self::assertSame('Mathematiques', $teacher['specialty']);
        self::assertStringStartsWith('ENS-', (string) $teacher['registration_number']);
    }

    public function testTeacherWithoutNameIsRejected(): void
    {
        $client = $this->login();

        $this->post($client, 'create', ['first_name' => '', 'last_name' => 'Sans prenom']);

        self::assertSelectorTextContains('.notice.error', 'Les prenoms et le nom sont obligatoires.');
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM staff WHERE last_name = ?', ['Sans prenom']));
    }

    public function testTeacherWithInvalidEmailIsRejected(): void
    {
        $client = $this->login();

        $this->post($client, 'create', [
            'first_name' => 'Ali',
            'last_name' => 'Coulibaly',
            'email' => 'adresse-invalide',
        ]);

        self::assertSelectorTextContains('.notice.error', 'L\'adresse email est invalide.');
    }

    public function testDuplicateRegistrationNumberIsRejected(): void
    {
        $client = $this->login();
        $this->post($client, 'create', [
            'first_name' => 'Ama',
            'last_name' => 'Kouame',
            'registration_number' => 'ENS-9001',
        ]);

        $this->post($client, 'create', [
            'first_name' => 'Bina',
            'last_name' => 'Toure',
            'registration_number' => 'ENS-9001',
        ]);

        self::assertSelectorTextContains('.notice.error', 'Ce matricule enseignant existe deja.');
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM teachers WHERE registration_number = ?',
            ['ENS-9001']
        ));
    }

    public function testAssignmentIsStoredWithWeeklyHours(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();

        $this->post($client, 'assign', [
            'teacher_id' => (string) $teacherId,
            'class_id' => (string) $classId,
            'subject_id' => (string) $subjectId,
            'weekly_hours' => '4',
        ]);

        self::assertSelectorTextContains('.notice.success', 'Affectation enregistree.');
        self::assertSame(4.0, (float) $this->connection()->fetchOne(
            'SELECT weekly_hours FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
            [$teacherId, $classId, $subjectId]
        ));
    }

    public function testAssignmentWithoutPositiveHoursIsRejected(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();

        $this->post($client, 'assign', [
            'teacher_id' => (string) $teacherId,
            'class_id' => (string) $classId,
            'subject_id' => (string) $subjectId,
            'weekly_hours' => '0',
        ]);

        self::assertSelectorTextContains('.notice.error', 'Le volume horaire doit etre superieur a zero.');
    }

    public function testSubjectAlreadyTaughtByAnotherTeacherInTheClassIsRejected(): void
    {
        $client = $this->login();
        $first = $this->teacher($client, 'Premier');
        $second = $this->teacher($client, 'Second');
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $first, $classId, $subjectId, '3');

        $this->assign($client, $second, $classId, $subjectId, '3');

        self::assertSelectorTextContains('.notice.error', 'deja confiee a un autre enseignant');
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM teacher_assignments WHERE class_id = ? AND subject_id = ?',
            [$classId, $subjectId]
        ));
    }

    public function testWeeklyHoursCeilingIsEnforced(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        $subjects = $this->connection()->fetchFirstColumn('SELECT id FROM subjects WHERE active = 1 ORDER BY id LIMIT 2');
        $classId = $this->classId();
        $this->assign($client, $teacherId, $classId, (int) $subjects[0], '28');

        $this->assign($client, $teacherId, $classId, (int) $subjects[1], '5');

        self::assertSelectorTextContains('.notice.error', 'Charge hebdomadaire depassee');
        self::assertSame(28.0, (float) $this->connection()->fetchOne(
            'SELECT SUM(weekly_hours) FROM teacher_assignments WHERE teacher_id = ?',
            [$teacherId]
        ));
    }

    public function testExistingAssignmentHoursAreUpdated(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $teacherId, $classId, $subjectId, '3');

        $this->assign($client, $teacherId, $classId, $subjectId, '6');

        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id = ?',
            [$teacherId]
        ));
        self::assertSame(6.0, (float) $this->connection()->fetchOne(
            'SELECT weekly_hours FROM teacher_assignments WHERE teacher_id = ?',
            [$teacherId]
        ));
    }

    public function testRemovingAssignmentAlsoRemovesItsCourses(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $teacherId, $classId, $subjectId, '4');
        $this->schedule($client, $teacherId, $classId, $subjectId, 1, '08:00', '10:00');
        $assignmentId = (int) $this->connection()->fetchOne(
            'SELECT id FROM teacher_assignments WHERE teacher_id = ?',
            [$teacherId]
        );

        $this->post($client, 'unassign', ['assignment_id' => (string) $assignmentId]);

        self::assertSelectorTextContains('.notice.success', 'Affectation retiree.');
        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM courses WHERE teacher_id = ?',
            [$teacherId]
        ));
    }

    public function testCourseIsScheduledForAnAssignedTeacher(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $teacherId, $classId, $subjectId, '4');

        $this->schedule($client, $teacherId, $classId, $subjectId, 2, '08:00', '10:00');

        self::assertSelectorTextContains('.notice.success', 'Creneau ajoute a l\'emploi du temps.');
        $course = $this->connection()->fetchAssociative('SELECT * FROM courses WHERE teacher_id = ?', [$teacherId]);
        self::assertIsArray($course);
        self::assertSame(2, (int) $course['weekday']);
        self::assertSame('08:00', $course['start_time']);
    }

    public function testCourseWithoutAssignmentIsRejected(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();

        $this->schedule($client, $teacherId, $classId, $subjectId, 1, '08:00', '10:00');

        self::assertSelectorTextContains('.notice.error', 'Affectez d\'abord cet enseignant');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM courses', []));
    }

    public function testOverlappingSlotForTheSameTeacherIsRejected(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        $subjects = $this->connection()->fetchFirstColumn('SELECT id FROM subjects WHERE active = 1 ORDER BY id LIMIT 2');
        $classes = $this->connection()->fetchFirstColumn('SELECT id FROM classes ORDER BY id LIMIT 2');
        $this->assign($client, $teacherId, (int) $classes[0], (int) $subjects[0], '4');
        $this->assign($client, $teacherId, (int) $classes[1], (int) $subjects[1], '4');
        $this->schedule($client, $teacherId, (int) $classes[0], (int) $subjects[0], 3, '08:00', '10:00');

        $this->schedule($client, $teacherId, (int) $classes[1], (int) $subjects[1], 3, '09:00', '11:00');

        self::assertSelectorTextContains('.notice.error', 'Cet enseignant a deja cours sur ce creneau.');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM courses', []));
    }

    public function testOverlappingSlotForTheSameClassIsRejected(): void
    {
        $client = $this->login();
        $first = $this->teacher($client, 'Premier');
        $second = $this->teacher($client, 'Second');
        $subjects = $this->connection()->fetchFirstColumn('SELECT id FROM subjects WHERE active = 1 ORDER BY id LIMIT 2');
        $classId = $this->classId();
        $this->assign($client, $first, $classId, (int) $subjects[0], '4');
        $this->assign($client, $second, $classId, (int) $subjects[1], '4');
        $this->schedule($client, $first, $classId, (int) $subjects[0], 4, '08:00', '10:00');

        $this->schedule($client, $second, $classId, (int) $subjects[1], 4, '09:30', '11:00');

        self::assertSelectorTextContains('.notice.error', 'Cette classe a deja cours sur ce creneau.');
        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM courses', []));
    }

    public function testAdjacentSlotsAreAccepted(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        $subjects = $this->connection()->fetchFirstColumn('SELECT id FROM subjects WHERE active = 1 ORDER BY id LIMIT 2');
        $classId = $this->classId();
        $this->assign($client, $teacherId, $classId, (int) $subjects[0], '4');
        $this->assign($client, $teacherId, $classId, (int) $subjects[1], '4');
        $this->schedule($client, $teacherId, $classId, (int) $subjects[0], 5, '08:00', '10:00');

        $this->schedule($client, $teacherId, $classId, (int) $subjects[1], 5, '10:00', '12:00');

        self::assertSelectorTextContains('.notice.success', 'Creneau ajoute a l\'emploi du temps.');
        self::assertSame(2, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM courses', []));
    }

    public function testInvalidWeekdayIsRejected(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $teacherId, $classId, $subjectId, '4');

        $this->schedule($client, $teacherId, $classId, $subjectId, 7, '08:00', '10:00');

        self::assertSelectorTextContains('.notice.error', 'Jour de la semaine invalide.');
    }

    public function testEndTimeBeforeStartTimeIsRejected(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $teacherId, $classId, $subjectId, '4');

        $this->schedule($client, $teacherId, $classId, $subjectId, 1, '11:00', '09:00');

        self::assertSelectorTextContains('.notice.error', 'L\'heure de fin doit suivre l\'heure de debut.');
    }

    public function testCourseIsRemovedFromTimetable(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client);
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $teacherId, $classId, $subjectId, '4');
        $this->schedule($client, $teacherId, $classId, $subjectId, 1, '08:00', '10:00');
        $courseId = (int) $this->connection()->fetchOne('SELECT id FROM courses WHERE teacher_id = ?', [$teacherId]);

        $crawler = $client->request('GET', '/symfony/timetable?class_id=' . $classId);
        $token = (string) $crawler->filter('input[value="remove"] ~ input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/symfony/timetable', [
            'action' => 'remove',
            '_token' => $token,
            'class_id' => (string) $classId,
            'course_id' => (string) $courseId,
        ]);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.success', 'Creneau supprime.');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM courses', []));
    }

    public function testTimetableShowsScheduledCourse(): void
    {
        $client = $this->login();
        $teacherId = $this->teacher($client, 'Emploi');
        [$classId, $subjectId] = $this->classAndSubject();
        $this->assign($client, $teacherId, $classId, $subjectId, '4');
        $this->schedule($client, $teacherId, $classId, $subjectId, 1, '08:00', '10:00');

        $client->request('GET', '/symfony/timetable?class_id=' . $classId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.week', '08:00 - 10:00');
        self::assertSelectorTextContains('.week', 'Emploi');
    }

    public function testPostWithoutValidCsrfTokenIsRejected(): void
    {
        $client = $this->login();

        $client->request('POST', '/symfony/teachers', [
            'action' => 'create',
            '_token' => 'invalide',
            'first_name' => 'Sans',
            'last_name' => 'Jeton',
        ]);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'Session expiree, merci de recommencer.');
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM staff WHERE last_name = ?', ['Jeton']));
    }

    public function testTeachersPageIsForbiddenForTeacherRole(): void
    {
        $client = static::createClient();
        $this->reset();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::ENSEIGNANT);
        self::assertNotNull($user);
        $client->loginUser($user);

        $client->request('GET', '/symfony/teachers');

        self::assertResponseStatusCodeSame(403);
    }

    public function testTimetableIsReadableByTeacherRole(): void
    {
        $client = static::createClient();
        $this->reset();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::ENSEIGNANT);
        self::assertNotNull($user);
        $client->loginUser($user);

        $client->request('GET', '/symfony/timetable');

        self::assertResponseIsSuccessful();
    }

    /**
     * @param array<string, string> $payload
     */
    private function post(KernelBrowser $client, string $action, array $payload): void
    {
        $crawler = $client->request('GET', '/symfony/teachers');
        $token = (string) $crawler
            ->filter('input[value="' . $action . '"] ~ input[name="_token"]')
            ->first()
            ->attr('value');

        $client->request('POST', '/symfony/teachers', $payload + ['action' => $action, '_token' => $token]);
        $client->followRedirect();
    }

    private function assign(KernelBrowser $client, int $teacherId, int $classId, int $subjectId, string $hours): void
    {
        $this->post($client, 'assign', [
            'teacher_id' => (string) $teacherId,
            'class_id' => (string) $classId,
            'subject_id' => (string) $subjectId,
            'weekly_hours' => $hours,
        ]);
    }

    private function schedule(
        KernelBrowser $client,
        int $teacherId,
        int $classId,
        int $subjectId,
        int $weekday,
        string $start,
        string $end
    ): void {
        $crawler = $client->request('GET', '/symfony/timetable?class_id=' . $classId);
        $token = (string) $crawler
            ->filter('input[value="schedule"] ~ input[name="_token"]')
            ->first()
            ->attr('value');

        $client->request('POST', '/symfony/timetable', [
            'action' => 'schedule',
            '_token' => $token,
            'teacher_id' => (string) $teacherId,
            'class_id' => (string) $classId,
            'subject_id' => (string) $subjectId,
            'weekday' => (string) $weekday,
            'start_time' => $start,
            'end_time' => $end,
            'room_id' => '0',
        ]);
        $client->followRedirect();
    }

    private function teacher(KernelBrowser $client, string $lastName = 'Enseignant'): int
    {
        $this->post($client, 'create', [
            'first_name' => 'Test',
            'last_name' => $lastName,
            'specialty' => 'Polyvalent',
        ]);

        return (int) $this->connection()->fetchOne(
            'SELECT t.id FROM teachers t INNER JOIN staff s ON s.id = t.staff_id WHERE s.last_name = ?',
            [$lastName]
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function classAndSubject(): array
    {
        $subjectId = (int) $this->connection()->fetchOne('SELECT id FROM subjects WHERE active = 1 ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $subjectId);

        return [$this->classId(), $subjectId];
    }

    private function classId(): int
    {
        $classId = (int) $this->connection()->fetchOne('SELECT id FROM classes ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $classId);

        return $classId;
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function reset(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DELETE FROM courses');
        $connection->executeStatement('DELETE FROM teacher_assignments');
        $connection->executeStatement('DELETE FROM teachers');
        $connection->executeStatement("DELETE FROM staff WHERE staff_type = 'Enseignant'");
    }

    private function login(): KernelBrowser
    {
        $client = static::createClient();
        $this->reset();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::SCOLARITE);
        self::assertNotNull($user);
        $client->loginUser($user);

        return $client;
    }
}
