<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Calcul, consultation et publication des bulletins.
 */
final class ReportCardManager
{
    public const SCHOOL_YEAR_ID = 1;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * Calcule les bulletins d'une classe pour une periode et retourne le nombre d'eleves traites.
     *
     * @throws \InvalidArgumentException si la classe ou la periode est absente
     */
    public function generateForClass(int $classId, int $periodId): int
    {
        if ($classId < 1 || $periodId < 1) {
            throw new \InvalidArgumentException('Selectionnez une classe et une periode.');
        }

        $students = $this->connection->fetchFirstColumn(
            "SELECT s.id FROM students s
             INNER JOIN registrations r
                ON r.student_id = s.id AND r.school_year_id = ? AND r.status = 'Validee' AND r.class_id = ?",
            [self::SCHOOL_YEAR_ID, $classId]
        );

        if ($students === []) {
            throw new \InvalidArgumentException('Aucun eleve inscrit dans cette classe.');
        }

        $rows = [];

        foreach ($students as $student) {
            $studentId = (int) $student;
            $weighted = 0.0;
            $weights = 0.0;

            foreach ($this->subjectAverages($studentId, $classId, $periodId) as $subject) {
                $weighted += $subject['average'] * $subject['coefficient'];
                $weights += $subject['coefficient'];
            }

            $rows[] = [
                'student_id' => $studentId,
                'average' => $weights > 0 ? round($weighted / $weights, 2) : null,
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            if ($left['average'] === $right['average']) {
                return 0;
            }

            if ($left['average'] === null) {
                return 1;
            }

            if ($right['average'] === null) {
                return -1;
            }

            return $right['average'] <=> $left['average'];
        });

        $this->connection->beginTransaction();

        try {
            $rank = 0;
            $previous = null;
            $index = 0;

            foreach ($rows as $row) {
                ++$index;

                if ($row['average'] !== $previous) {
                    $rank = $index;
                    $previous = $row['average'];
                }

                $payload = [
                    'average' => $row['average'],
                    'rank' => $row['average'] === null ? null : $rank,
                    'appreciation' => self::appreciation($row['average']),
                    'decision' => self::decision($row['average']),
                    'status' => 'Calcule',
                ];

                $existing = $this->connection->fetchOne(
                    'SELECT id FROM report_cards WHERE student_id = ? AND period_id = ?',
                    [$row['student_id'], $periodId]
                );

                if ($existing !== false) {
                    $this->connection->update('report_cards', $payload, ['id' => $existing]);
                } else {
                    $this->connection->insert('report_cards', $payload + [
                        'student_id' => $row['student_id'],
                        'period_id' => $periodId,
                    ]);
                }
            }

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return count($rows);
    }

    /**
     * Publie les bulletins calcules d'une classe et retourne le nombre publie.
     *
     * @throws \InvalidArgumentException si aucun bulletin calcule n'est disponible
     */
    public function publishForClass(int $classId, int $periodId): int
    {
        if ($classId < 1 || $periodId < 1) {
            throw new \InvalidArgumentException('Selectionnez une classe et une periode.');
        }

        $published = (int) $this->connection->executeStatement(
            "UPDATE report_cards
             SET status = 'Publie', published_at = ?
             WHERE period_id = ?
               AND status = 'Calcule'
               AND student_id IN (
                   SELECT student_id FROM registrations
                   WHERE class_id = ? AND school_year_id = ? AND status = 'Validee'
               )",
            [date('Y-m-d H:i:s'), $periodId, $classId, self::SCHOOL_YEAR_ID]
        );

        if ($published === 0) {
            throw new \InvalidArgumentException('Aucun bulletin calcule a publier pour cette classe et cette periode.');
        }

        return $published;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException si le bulletin est introuvable
     */
    public function find(int $cardId): array
    {
        $card = $this->connection->fetchAssociative(
            <<<SQL
            SELECT rc.*, s.first_name, s.last_name, s.class_name,
                   p.name AS period_name, p.start_date, p.end_date, c.name AS registered_class
            FROM report_cards rc
            INNER JOIN students s ON s.id = rc.student_id
            INNER JOIN periods p ON p.id = rc.period_id
            LEFT JOIN registrations r
                ON r.student_id = s.id AND r.school_year_id = :year AND r.status = 'Validee'
            LEFT JOIN classes c ON c.id = r.class_id
            WHERE rc.id = :id
            SQL,
            ['id' => $cardId, 'year' => self::SCHOOL_YEAR_ID]
        );

        if ($card === false) {
            throw new \InvalidArgumentException('Bulletin introuvable.');
        }

        return $card;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(int $classId, int $periodId): array
    {
        $where = [];
        $parameters = ['year' => self::SCHOOL_YEAR_ID];

        if ($classId > 0) {
            $where[] = 'r.class_id = :class_id';
            $parameters['class_id'] = $classId;
        }

        if ($periodId > 0) {
            $where[] = 'rc.period_id = :period_id';
            $parameters['period_id'] = $periodId;
        }

        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT rc.id, rc.average, rc.rank, rc.appreciation, rc.decision, rc.status, rc.published_at,
                   s.first_name, s.last_name, c.name AS class_name, p.name AS period_name
            FROM report_cards rc
            INNER JOIN students s ON s.id = rc.student_id
            INNER JOIN periods p ON p.id = rc.period_id
            LEFT JOIN registrations r
                ON r.student_id = s.id AND r.school_year_id = :year AND r.status = 'Validee'
            LEFT JOIN classes c ON c.id = r.class_id
            $sqlWhere
            ORDER BY p.start_date, c.name, rc.rank, s.last_name
            SQL,
            $parameters
        );
    }

    /**
     * @return list<array{name: string, coefficient: float, average: float, evaluations: int}>
     */
    public function subjectAverages(int $studentId, int $classId, int $periodId): array
    {
        $grades = $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT sub.id AS subject_id, sub.name, sub.default_coefficient, e.coefficient, e.scale, g.value
            FROM grades g
            INNER JOIN evaluations e ON e.id = g.evaluation_id
            INNER JOIN subjects sub ON sub.id = e.subject_id
            WHERE g.student_id = :student_id
              AND e.class_id = :class_id
              AND e.period_id = :period_id
              AND g.value IS NOT NULL
            SQL,
            ['student_id' => $studentId, 'class_id' => $classId, 'period_id' => $periodId]
        );

        $grouped = [];

        foreach ($grades as $grade) {
            $subjectId = (int) $grade['subject_id'];
            $grouped[$subjectId] ??= [
                'name' => (string) $grade['name'],
                'coefficient' => (float) $grade['default_coefficient'],
                'weighted' => 0.0,
                'weights' => 0.0,
                'evaluations' => 0,
            ];

            $evaluationCoefficient = (float) $grade['coefficient'];
            $grouped[$subjectId]['weighted'] += ((float) $grade['value'] / (float) $grade['scale']) * 20 * $evaluationCoefficient;
            $grouped[$subjectId]['weights'] += $evaluationCoefficient;
            ++$grouped[$subjectId]['evaluations'];
        }

        $subjects = [];

        foreach ($grouped as $subject) {
            if ($subject['weights'] <= 0) {
                continue;
            }

            $subjects[] = [
                'name' => $subject['name'],
                'coefficient' => $subject['coefficient'],
                'average' => round($subject['weighted'] / $subject['weights'], 2),
                'evaluations' => $subject['evaluations'],
            ];
        }

        usort($subjects, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return $subjects;
    }

    public function classIdOf(int $studentId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT class_id FROM registrations
             WHERE student_id = ? AND school_year_id = ? AND status = 'Validee'",
            [$studentId, self::SCHOOL_YEAR_ID]
        );
    }

    public function absencesBetween(int $studentId, string $start, string $end): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM attendance
             WHERE student_id = ? AND status = 'Absent' AND attendance_date BETWEEN ? AND ?",
            [$studentId, $start, $end]
        );
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
    public function periods(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, name FROM periods WHERE school_year_id = ? ORDER BY start_date',
            [self::SCHOOL_YEAR_ID]
        );
    }

    public static function appreciation(?float $average): string
    {
        if ($average === null) {
            return 'Notes insuffisantes';
        }

        return match (true) {
            $average >= 16 => 'Excellent',
            $average >= 14 => 'Tres bien',
            $average >= 12 => 'Bien',
            $average >= 10 => 'Passable',
            default => 'Insuffisant',
        };
    }

    public static function decision(?float $average): ?string
    {
        if ($average === null) {
            return null;
        }

        return match (true) {
            $average >= 10 => 'Admis',
            $average >= 8.5 => 'Admis sous conditions',
            default => 'Redouble',
        };
    }
}
