<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Saisie et suivi des presences quotidiennes par classe.
 */
final class AttendanceManager
{
    public const SCHOOL_YEAR_ID = 1;

    /**
     * @var list<string>
     */
    public const STATUSES = ['Present', 'Absent', 'Retard', 'Dispense', 'Sortie autorisee'];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Enregistre la feuille du jour et retourne le nombre de lignes traitees.
     *
     * @param array<int|string, mixed> $statuses
     * @param array<int|string, mixed> $minutesLate
     * @param array<int|string, mixed> $reasons
     * @param array<int|string, mixed> $justified
     *
     * @throws \InvalidArgumentException si la classe ou la saisie est invalide
     */
    public function saveSheet(
        int $classId,
        string $date,
        array $statuses,
        array $minutesLate,
        array $reasons,
        array $justified,
        ?User $author
    ): int {
        if ($classId < 1 || $statuses === []) {
            throw new \InvalidArgumentException('Selectionnez une classe et au moins un eleve.');
        }

        $enrolled = $this->enrolledStudentIds($classId);
        $saved = 0;

        $this->connection->beginTransaction();

        try {
            foreach ($statuses as $key => $status) {
                $studentId = (int) $key;

                if (!in_array($studentId, $enrolled, true) || !in_array($status, self::STATUSES, true)) {
                    continue;
                }

                $payload = [
                    'status' => $status,
                    'minutes_late' => $status === 'Retard' ? max(0, (int) ($minutesLate[$studentId] ?? 0)) : 0,
                    'reason' => trim((string) ($reasons[$studentId] ?? '')),
                    'justified' => isset($justified[$studentId]) ? 1 : 0,
                    'validated_by' => $author?->getId(),
                ];

                $existing = $this->connection->fetchOne(
                    'SELECT id FROM attendance WHERE student_id = ? AND class_id = ? AND attendance_date = ?',
                    [$studentId, $classId, $date]
                );

                if ($existing !== false) {
                    $this->connection->update('attendance', $payload, ['id' => $existing]);
                } else {
                    $this->connection->insert('attendance', $payload + [
                        'student_id' => $studentId,
                        'class_id' => $classId,
                        'attendance_date' => $date,
                    ]);
                }

                ++$saved;
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return $saved;
    }

    /**
     * @throws \InvalidArgumentException si la ligne de presence est introuvable
     */
    public function justify(int $attendanceId, string $reason, ?User $author): void
    {
        $line = $this->connection->fetchAssociative('SELECT id FROM attendance WHERE id = ?', [$attendanceId]);

        if ($line === false) {
            throw new \InvalidArgumentException('Cette absence n\'existe pas.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('Le motif de justification est obligatoire.');
        }

        $this->connection->update(
            'attendance',
            ['justified' => 1, 'reason' => $reason, 'validated_by' => $author?->getId()],
            ['id' => $attendanceId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sheet(int $classId, string $date): array
    {
        if ($classId < 1) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT s.id, s.first_name, s.last_name, s.class_name, s.cycle,
                   a.id AS attendance_id, a.status, a.minutes_late, a.reason, a.justified
            FROM students s
            INNER JOIN registrations r
                ON r.student_id = s.id
               AND r.school_year_id = :year
               AND r.status = 'Validee'
               AND r.class_id = :class_id
            LEFT JOIN attendance a
                ON a.student_id = s.id AND a.class_id = :class_id AND a.attendance_date = :date
            ORDER BY s.last_name, s.first_name
            SQL,
            ['class_id' => $classId, 'date' => $date, 'year' => self::SCHOOL_YEAR_ID]
        );
    }

    /**
     * Cumul du mois de la date fournie, par eleve de la classe.
     *
     * @return list<array<string, mixed>>
     */
    public function monthlyRecap(int $classId, string $date): array
    {
        if ($classId < 1) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT s.id, s.first_name, s.last_name,
                   SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absences,
                   SUM(CASE WHEN a.status = 'Absent' AND a.justified = 1 THEN 1 ELSE 0 END) AS justifiees,
                   SUM(CASE WHEN a.status = 'Retard' THEN 1 ELSE 0 END) AS retards,
                   SUM(CASE WHEN a.status = 'Retard' THEN a.minutes_late ELSE 0 END) AS minutes_retard
            FROM attendance a
            INNER JOIN students s ON s.id = a.student_id
            WHERE a.class_id = :class_id
              AND substr(a.attendance_date, 1, 7) = :month
              AND a.status IN ('Absent', 'Retard')
            GROUP BY s.id, s.first_name, s.last_name
            ORDER BY absences DESC, retards DESC, s.last_name
            SQL,
            ['class_id' => $classId, 'month' => substr($date, 0, 7)]
        );
    }

    /**
     * @param list<array<string, mixed>> $sheet
     *
     * @return array{total: int, present: int, absent: int, late: int, unjustified: int}
     */
    public function counts(array $sheet): array
    {
        $counts = ['total' => count($sheet), 'present' => 0, 'absent' => 0, 'late' => 0, 'unjustified' => 0];

        foreach ($sheet as $line) {
            if ($line['status'] === 'Present') {
                ++$counts['present'];
            } elseif ($line['status'] === 'Absent') {
                ++$counts['absent'];

                if ((int) $line['justified'] === 0) {
                    ++$counts['unjustified'];
                }
            } elseif ($line['status'] === 'Retard') {
                ++$counts['late'];
            }
        }

        return $counts;
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

    public static function normalizeDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : date('Y-m-d');
    }

    /**
     * @return list<int>
     */
    private function enrolledStudentIds(int $classId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT student_id FROM registrations
             WHERE class_id = ? AND school_year_id = ? AND status = \'Validee\'',
            [$classId, self::SCHOOL_YEAR_ID]
        ));
    }
}
