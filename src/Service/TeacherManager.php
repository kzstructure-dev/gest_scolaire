<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Enseignants, affectations aux classes et emploi du temps hebdomadaire.
 */
final class TeacherManager
{
    public const SCHOOL_YEAR_ID = 1;

    /**
     * Heures hebdomadaires maximales par enseignant.
     */
    public const MAX_WEEKLY_HOURS = 30.0;

    /**
     * @var array<int, string>
     */
    public const WEEKDAYS = [
        1 => 'Lundi',
        2 => 'Mardi',
        3 => 'Mercredi',
        4 => 'Jeudi',
        5 => 'Vendredi',
        6 => 'Samedi',
    ];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Cree la fiche personnel et la fiche enseignant associee.
     *
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException si la fiche est invalide
     */
    public function createTeacher(array $input): int
    {
        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        $specialty = trim((string) ($input['specialty'] ?? ''));
        $phone = trim((string) ($input['phone'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $number = trim((string) ($input['registration_number'] ?? ''));

        if ($firstName === '' || $lastName === '') {
            throw new \InvalidArgumentException('Les prenoms et le nom sont obligatoires.');
        }

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('L\'adresse email est invalide.');
        }

        if ($number !== '' && $this->connection->fetchOne(
            'SELECT id FROM teachers WHERE registration_number = ?',
            [$number]
        ) !== false) {
            throw new \InvalidArgumentException('Ce matricule enseignant existe deja.');
        }

        $establishmentId = (int) $this->connection->fetchOne('SELECT id FROM establishments ORDER BY id LIMIT 1');

        $this->connection->beginTransaction();

        try {
            $this->connection->insert('staff', [
                'establishment_id' => $establishmentId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'staff_type' => 'Enseignant',
                'phone' => $phone,
                'email' => $email,
                'active' => 1,
            ]);

            $staffId = (int) $this->connection->lastInsertId();

            $this->connection->insert('teachers', [
                'staff_id' => $staffId,
                'registration_number' => $number !== '' ? $number : 'ENS-' . str_pad((string) $staffId, 4, '0', STR_PAD_LEFT),
                'specialty' => $specialty,
            ]);

            $teacherId = (int) $this->connection->lastInsertId();
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return $teacherId;
    }

    /**
     * Affecte un enseignant a une matiere dans une classe.
     *
     * @throws \InvalidArgumentException si l'affectation est invalide
     */
    public function assign(int $teacherId, int $classId, int $subjectId, float $weeklyHours): void
    {
        if ($weeklyHours <= 0) {
            throw new \InvalidArgumentException('Le volume horaire doit etre superieur a zero.');
        }

        $this->assertTeacher($teacherId);

        if ($this->connection->fetchOne('SELECT id FROM classes WHERE id = ?', [$classId]) === false) {
            throw new \InvalidArgumentException('Cette classe n\'existe pas.');
        }

        if ($this->connection->fetchOne('SELECT id FROM subjects WHERE id = ? AND active = 1', [$subjectId]) === false) {
            throw new \InvalidArgumentException('Cette matiere n\'existe pas.');
        }

        $existing = $this->connection->fetchOne(
            'SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
            [$teacherId, $classId, $subjectId]
        );

        $otherTeacher = $this->connection->fetchOne(
            'SELECT t.id FROM teacher_assignments a
             INNER JOIN teachers t ON t.id = a.teacher_id
             WHERE a.class_id = ? AND a.subject_id = ? AND a.teacher_id <> ?',
            [$classId, $subjectId, $teacherId]
        );

        if ($otherTeacher !== false) {
            throw new \InvalidArgumentException('Cette matiere est deja confiee a un autre enseignant dans cette classe.');
        }

        $current = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(weekly_hours), 0) FROM teacher_assignments WHERE teacher_id = ? AND id <> ?',
            [$teacherId, $existing === false ? 0 : $existing]
        );

        if ($current + $weeklyHours > self::MAX_WEEKLY_HOURS) {
            throw new \InvalidArgumentException(sprintf(
                'Charge hebdomadaire depassee : %s h deja affectees pour un maximum de %s h.',
                rtrim(rtrim(number_format($current, 1, ',', ' '), '0'), ','),
                (int) self::MAX_WEEKLY_HOURS
            ));
        }

        if ($existing !== false) {
            $this->connection->update('teacher_assignments', ['weekly_hours' => $weeklyHours], ['id' => $existing]);

            return;
        }

        $this->connection->insert('teacher_assignments', [
            'teacher_id' => $teacherId,
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'weekly_hours' => $weeklyHours,
        ]);
    }

    /**
     * @throws \InvalidArgumentException si l'affectation est introuvable
     */
    public function removeAssignment(int $assignmentId): void
    {
        $assignment = $this->connection->fetchAssociative(
            'SELECT teacher_id, class_id, subject_id FROM teacher_assignments WHERE id = ?',
            [$assignmentId]
        );

        if ($assignment === false) {
            throw new \InvalidArgumentException('Cette affectation n\'existe pas.');
        }

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                'DELETE FROM courses WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
                [$assignment['teacher_id'], $assignment['class_id'], $assignment['subject_id']]
            );
            $this->connection->delete('teacher_assignments', ['id' => $assignmentId]);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * Place un cours dans l'emploi du temps en refusant les conflits.
     *
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException si le creneau est invalide ou en conflit
     */
    public function scheduleCourse(array $input): int
    {
        $classId = (int) ($input['class_id'] ?? 0);
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $teacherId = (int) ($input['teacher_id'] ?? 0);
        $roomId = (int) ($input['room_id'] ?? 0);
        $weekday = (int) ($input['weekday'] ?? 0);
        $start = trim((string) ($input['start_time'] ?? ''));
        $end = trim((string) ($input['end_time'] ?? ''));

        if (!array_key_exists($weekday, self::WEEKDAYS)) {
            throw new \InvalidArgumentException('Jour de la semaine invalide.');
        }

        if (!$this->isTime($start) || !$this->isTime($end)) {
            throw new \InvalidArgumentException('Les heures doivent etre au format HH:MM.');
        }

        if ($start >= $end) {
            throw new \InvalidArgumentException('L\'heure de fin doit suivre l\'heure de debut.');
        }

        $this->assertTeacher($teacherId);

        $assigned = $this->connection->fetchOne(
            'SELECT id FROM teacher_assignments WHERE teacher_id = ? AND class_id = ? AND subject_id = ?',
            [$teacherId, $classId, $subjectId]
        );

        if ($assigned === false) {
            throw new \InvalidArgumentException('Affectez d\'abord cet enseignant a cette matiere dans cette classe.');
        }

        if ($roomId > 0 && $this->connection->fetchOne('SELECT id FROM rooms WHERE id = ?', [$roomId]) === false) {
            throw new \InvalidArgumentException('Cette salle n\'existe pas.');
        }

        $this->assertNoConflict('teacher_id', $teacherId, $weekday, $start, $end, 'Cet enseignant a deja cours sur ce creneau.');
        $this->assertNoConflict('class_id', $classId, $weekday, $start, $end, 'Cette classe a deja cours sur ce creneau.');

        if ($roomId > 0) {
            $this->assertNoConflict('room_id', $roomId, $weekday, $start, $end, 'Cette salle est deja occupee sur ce creneau.');
        }

        $title = (string) $this->connection->fetchOne('SELECT name FROM subjects WHERE id = ?', [$subjectId]);

        $this->connection->insert('courses', [
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
            'title' => $title,
            'room_id' => $roomId > 0 ? $roomId : null,
            'start_time' => $start,
            'end_time' => $end,
            'weekday' => $weekday,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * @throws \InvalidArgumentException si le cours est introuvable
     */
    public function removeCourse(int $courseId): void
    {
        if ($this->connection->fetchOne('SELECT id FROM courses WHERE id = ?', [$courseId]) === false) {
            throw new \InvalidArgumentException('Ce cours n\'existe pas.');
        }

        $this->connection->delete('courses', ['id' => $courseId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function teachers(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT t.id, t.registration_number, t.specialty,
                    s.first_name, s.last_name, s.phone, s.email,
                    COALESCE((SELECT SUM(a.weekly_hours) FROM teacher_assignments a WHERE a.teacher_id = t.id), 0) AS weekly_hours,
                    (SELECT COUNT(*) FROM teacher_assignments a WHERE a.teacher_id = t.id) AS assignments
             FROM teachers t
             INNER JOIN staff s ON s.id = t.staff_id
             WHERE s.active = 1
             ORDER BY s.last_name, s.first_name'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function assignments(int $teacherId = 0, int $classId = 0): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id, a.weekly_hours, c.name AS class_name, sub.name AS subject_name,
                    s.first_name, s.last_name, a.teacher_id, a.class_id, a.subject_id
             FROM teacher_assignments a
             INNER JOIN teachers t ON t.id = a.teacher_id
             INNER JOIN staff s ON s.id = t.staff_id
             INNER JOIN classes c ON c.id = a.class_id
             INNER JOIN subjects sub ON sub.id = a.subject_id
             WHERE (:teacher_id = 0 OR a.teacher_id = :teacher_id)
               AND (:class_id = 0 OR a.class_id = :class_id)
             ORDER BY c.name, sub.name',
            ['teacher_id' => $teacherId, 'class_id' => $classId],
            ['teacher_id' => ParameterType::INTEGER, 'class_id' => ParameterType::INTEGER]
        );
    }

    /**
     * Emploi du temps d'une classe, regroupe par jour.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function weeklySchedule(int $classId): array
    {
        $schedule = array_fill_keys(array_keys(self::WEEKDAYS), []);

        if ($classId < 1) {
            return $schedule;
        }

        $courses = $this->connection->fetchAllAssociative(
            'SELECT co.id, co.weekday, co.start_time, co.end_time, co.title,
                    sub.name AS subject_name, r.name AS room_name,
                    s.first_name, s.last_name
             FROM courses co
             INNER JOIN subjects sub ON sub.id = co.subject_id
             LEFT JOIN teachers t ON t.id = co.teacher_id
             LEFT JOIN staff s ON s.id = t.staff_id
             LEFT JOIN rooms r ON r.id = co.room_id
             WHERE co.class_id = ?
             ORDER BY co.weekday, co.start_time',
            [$classId]
        );

        foreach ($courses as $course) {
            $weekday = (int) $course['weekday'];

            if (isset($schedule[$weekday])) {
                $schedule[$weekday][] = $course;
            }
        }

        return $schedule;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function classes(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT c.id, c.name, cy.name AS cycle
             FROM classes c
             JOIN levels l ON l.id = c.level_id
             JOIN cycles cy ON cy.id = l.cycle_id
             ORDER BY l.sort_order, c.name'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function subjects(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, name, default_coefficient FROM subjects WHERE active = 1 ORDER BY name'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rooms(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, name, room_type, capacity FROM rooms ORDER BY name'
        );
    }

    private function assertTeacher(int $teacherId): void
    {
        if ($this->connection->fetchOne('SELECT id FROM teachers WHERE id = ?', [$teacherId]) === false) {
            throw new \InvalidArgumentException('Cet enseignant n\'existe pas.');
        }
    }

    private function assertNoConflict(
        string $column,
        int $value,
        int $weekday,
        string $start,
        string $end,
        string $message
    ): void {
        $conflict = $this->connection->fetchOne(
            sprintf(
                'SELECT id FROM courses
                 WHERE %s = ? AND weekday = ? AND start_time < ? AND end_time > ?',
                $column
            ),
            [$value, $weekday, $end, $start]
        );

        if ($conflict !== false) {
            throw new \InvalidArgumentException($message);
        }
    }

    private function isTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
