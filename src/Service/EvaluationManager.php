<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Creation des evaluations et saisie des notes.
 */
final class EvaluationManager
{
    public const SCHOOL_YEAR_ID = 1;

    /**
     * @var list<string>
     */
    public const TYPES = ['Interrogation', 'Devoir', 'Composition', 'Examen blanc', 'Projet', 'Oral', 'Pratique', 'Examen officiel'];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException si les donnees saisies sont invalides
     */
    public function create(array $input): int
    {
        $title = trim((string) ($input['title'] ?? ''));
        $classId = (int) ($input['class_id'] ?? 0);
        $subjectId = (int) ($input['subject_id'] ?? 0);
        $periodId = (int) ($input['period_id'] ?? 0);
        $type = (string) ($input['evaluation_type'] ?? 'Devoir');
        $date = (string) ($input['evaluation_date'] ?? date('Y-m-d'));
        $scale = (float) ($input['scale'] ?? 20);
        $coefficient = (float) ($input['coefficient'] ?? 1);

        if ($title === '' || $classId < 1 || $subjectId < 1 || $periodId < 1) {
            throw new \InvalidArgumentException('Le titre, la classe, la matiere et la periode sont obligatoires.');
        }

        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Type d\'evaluation invalide.');
        }

        if ($scale <= 0 || $coefficient <= 0) {
            throw new \InvalidArgumentException('Le bareme et le coefficient doivent etre superieurs a zero.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException('La date de l\'evaluation est invalide.');
        }

        $this->requireReference('classes', $classId, 'La classe selectionnee n\'existe pas.');
        $this->requireReference('subjects', $subjectId, 'La matiere selectionnee n\'existe pas.');
        $this->requireReference('periods', $periodId, 'La periode selectionnee n\'existe pas.');

        $this->connection->insert('evaluations', [
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'period_id' => $periodId,
            'title' => $title,
            'evaluation_type' => $type,
            'evaluation_date' => $date,
            'scale' => $scale,
            'coefficient' => $coefficient,
            'status' => 'Brouillon',
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Enregistre les notes d'une evaluation et retourne le nombre de notes saisies.
     *
     * @param array<int|string, mixed> $values
     * @param array<int|string, mixed> $appreciations
     *
     * @throws \InvalidArgumentException si une note est hors bareme
     */
    public function saveGrades(int $evaluationId, array $values, array $appreciations, ?User $author): int
    {
        $evaluation = $this->find($evaluationId);
        $scale = (float) $evaluation['scale'];
        $enrolled = $this->enrolledStudentIds((int) $evaluation['class_id']);
        $saved = 0;

        $this->connection->beginTransaction();

        try {
            foreach ($values as $key => $rawValue) {
                $studentId = (int) $key;

                if (!in_array($studentId, $enrolled, true)) {
                    continue;
                }

                $rawValue = trim((string) $rawValue);

                if ($rawValue === '') {
                    $this->connection->executeStatement(
                        'DELETE FROM grades WHERE evaluation_id = ? AND student_id = ?',
                        [$evaluationId, $studentId]
                    );

                    continue;
                }

                if (!is_numeric($rawValue)) {
                    throw new \InvalidArgumentException('Note invalide.');
                }

                $value = (float) $rawValue;

                if ($value < 0 || $value > $scale) {
                    throw new \InvalidArgumentException('Chaque note doit etre comprise entre 0 et ' . $scale . '.');
                }

                $payload = [
                    'value' => $value,
                    'appreciation' => trim((string) ($appreciations[$studentId] ?? '')),
                    'status' => 'Saisie',
                    'validated_by' => $author?->getId(),
                ];

                $existing = $this->connection->fetchOne(
                    'SELECT id FROM grades WHERE evaluation_id = ? AND student_id = ?',
                    [$evaluationId, $studentId]
                );

                if ($existing !== false) {
                    $this->connection->update('grades', $payload, ['id' => $existing]);
                } else {
                    $this->connection->insert('grades', $payload + [
                        'evaluation_id' => $evaluationId,
                        'student_id' => $studentId,
                    ]);
                }

                ++$saved;
            }

            $this->connection->update(
                'evaluations',
                ['status' => $saved === count($enrolled) && $saved > 0 ? 'Complete' : 'Saisie'],
                ['id' => $evaluationId]
            );
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return $saved;
    }

    /**
     * @throws \InvalidArgumentException si l'evaluation est introuvable
     */
    public function delete(int $evaluationId): void
    {
        $this->find($evaluationId);

        $this->connection->beginTransaction();

        try {
            $this->connection->delete('grades', ['evaluation_id' => $evaluationId]);
            $this->connection->delete('evaluations', ['id' => $evaluationId]);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException si l'evaluation est introuvable
     */
    public function find(int $evaluationId): array
    {
        $evaluation = $this->connection->fetchAssociative(
            <<<SQL
            SELECT e.*, c.name AS class_name, s.name AS subject_name, p.name AS period_name
            FROM evaluations e
            INNER JOIN classes c ON c.id = e.class_id
            INNER JOIN subjects s ON s.id = e.subject_id
            LEFT JOIN periods p ON p.id = e.period_id
            WHERE e.id = ?
            SQL,
            [$evaluationId]
        );

        if ($evaluation === false) {
            throw new \InvalidArgumentException('Evaluation introuvable.');
        }

        return $evaluation;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForClass(int $classId): array
    {
        $parameters = [];
        $where = '';

        if ($classId > 0) {
            $where = 'WHERE e.class_id = :class_id';
            $parameters['class_id'] = $classId;
        }

        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT e.id, e.title, e.evaluation_type, e.evaluation_date, e.scale, e.coefficient, e.status,
                   c.name AS class_name, s.name AS subject_name, p.name AS period_name,
                   COUNT(g.id) AS grade_count
            FROM evaluations e
            INNER JOIN classes c ON c.id = e.class_id
            INNER JOIN subjects s ON s.id = e.subject_id
            LEFT JOIN periods p ON p.id = e.period_id
            LEFT JOIN grades g ON g.evaluation_id = e.id AND g.value IS NOT NULL
            $where
            GROUP BY e.id, e.title, e.evaluation_type, e.evaluation_date, e.scale, e.coefficient, e.status,
                     c.name, s.name, p.name
            ORDER BY e.evaluation_date DESC, e.id DESC
            SQL,
            $parameters
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function gradeSheet(int $evaluationId, int $classId): array
    {
        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT s.id, s.first_name, s.last_name, g.value, g.appreciation
            FROM students s
            INNER JOIN registrations r
                ON r.student_id = s.id
               AND r.school_year_id = :year
               AND r.status = 'Validee'
               AND r.class_id = :class_id
            LEFT JOIN grades g ON g.student_id = s.id AND g.evaluation_id = :evaluation_id
            ORDER BY s.last_name, s.first_name
            SQL,
            ['class_id' => $classId, 'evaluation_id' => $evaluationId, 'year' => self::SCHOOL_YEAR_ID]
        );
    }

    /**
     * @param list<array<string, mixed>> $sheet
     *
     * @return array{entered: int, missing: int, average: float|null}
     */
    public function statistics(array $sheet, float $scale): array
    {
        $entered = 0;
        $sumNormalized = 0.0;

        foreach ($sheet as $line) {
            if ($line['value'] === null || $line['value'] === '') {
                continue;
            }

            ++$entered;
            $sumNormalized += ((float) $line['value'] / $scale) * 20;
        }

        return [
            'entered' => $entered,
            'missing' => count($sheet) - $entered,
            'average' => $entered > 0 ? round($sumNormalized / $entered, 2) : null,
        ];
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
        return $this->connection->fetchAllAssociative('SELECT id, name FROM subjects WHERE active = 1 ORDER BY name');
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

    private function requireReference(string $table, int $id, string $message): void
    {
        if ($this->connection->fetchOne(sprintf('SELECT id FROM %s WHERE id = ?', $table), [$id]) === false) {
            throw new \InvalidArgumentException($message);
        }
    }
}
