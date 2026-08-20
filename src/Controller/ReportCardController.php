<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportCardController extends AbstractController
{
    #[Route('/symfony/report-cards', name: 'app_report_cards', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection): Response
    {
        $classId = (int) $request->request->get('class_id', $request->query->get('class_id', 0));
        $periodId = (int) $request->request->get('period_id', $request->query->get('period_id', 0));
        $message = null;
        $error = null;

        if ($request->isMethod('POST')) {
            if ($classId < 1 || $periodId < 1) {
                $error = 'Selectionnez une classe et une periode.';
            } else {
                try {
                    $count = $this->generateForClass($connection, $classId, $periodId);
                    $message = $count . ' bulletin(s) calcule(s).';
                } catch (\Throwable) {
                    $error = 'Impossible de calculer les bulletins.';
                }
            }
        }

        $parameters = [];
        $where = [];
        if ($classId > 0) {
            $where[] = 'r.class_id = :class_id';
            $parameters['class_id'] = $classId;
        }
        if ($periodId > 0) {
            $where[] = 'rc.period_id = :period_id';
            $parameters['period_id'] = $periodId;
        }
        $sqlWhere = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

        $cards = $connection->fetchAllAssociative(<<<SQL
            SELECT rc.id, rc.average, rc.rank, rc.appreciation, rc.status, rc.published_at,
                   s.first_name, s.last_name, c.name AS class_name, p.name AS period_name
            FROM report_cards rc
            INNER JOIN students s ON s.id = rc.student_id
            INNER JOIN periods p ON p.id = rc.period_id
            LEFT JOIN registrations r ON r.student_id = s.id AND r.school_year_id = 1 AND r.status = 'Validee'
            LEFT JOIN classes c ON c.id = r.class_id
            $sqlWhere
            ORDER BY p.start_date, c.name, rc.rank, s.last_name
        SQL, $parameters);

        return $this->render('report_cards/index.html.twig', [
            'cards' => $cards,
            'classes' => $connection->fetchAllAssociative('SELECT c.id, c.name, cy.name AS cycle FROM classes c JOIN levels l ON l.id = c.level_id JOIN cycles cy ON cy.id = l.cycle_id ORDER BY l.sort_order, c.name'),
            'periods' => $connection->fetchAllAssociative('SELECT id, name FROM periods WHERE school_year_id = 1 ORDER BY start_date'),
            'classId' => $classId,
            'periodId' => $periodId,
            'message' => $message,
            'error' => $error,
            'total' => count($cards),
        ]);
    }

    #[Route('/symfony/report-cards/{id}', name: 'app_report_card_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, Connection $connection): Response
    {
        $card = $connection->fetchAssociative(<<<SQL
            SELECT rc.*, s.first_name, s.last_name, s.class_name, p.name AS period_name, p.start_date, p.end_date,
                   c.name AS registered_class
            FROM report_cards rc
            INNER JOIN students s ON s.id = rc.student_id
            INNER JOIN periods p ON p.id = rc.period_id
            LEFT JOIN registrations r ON r.student_id = s.id AND r.school_year_id = 1 AND r.status = 'Validee'
            LEFT JOIN classes c ON c.id = r.class_id
            WHERE rc.id = ?
        SQL, [$id]);
        if ($card === false) {
            throw $this->createNotFoundException('Bulletin introuvable.');
        }

        $classId = (int) $connection->fetchOne(
            "SELECT class_id FROM registrations WHERE student_id = ? AND school_year_id = 1 AND status = 'Validee'",
            [$card['student_id']]
        );
        $subjects = $classId > 0
            ? $this->subjectAverages($connection, (int) $card['student_id'], $classId, (int) $card['period_id'])
            : [];
        $absences = (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM attendance WHERE student_id = ? AND status = 'Absent' AND attendance_date BETWEEN ? AND ?",
            [$card['student_id'], $card['start_date'], $card['end_date']]
        );

        return $this->render('report_cards/show.html.twig', [
            'card' => $card,
            'subjects' => $subjects,
            'absences' => $absences,
        ]);
    }

    private function generateForClass(Connection $connection, int $classId, int $periodId): int
    {
        $students = $connection->fetchAllAssociative(
            "SELECT s.id FROM students s INNER JOIN registrations r ON r.student_id = s.id AND r.school_year_id = 1 AND r.status = 'Validee' AND r.class_id = ?",
            [$classId]
        );
        $rows = [];
        foreach ($students as $student) {
            $studentId = (int) $student['id'];
            $subjects = $this->subjectAverages($connection, $studentId, $classId, $periodId);
            $weightSum = 0.0;
            $weighted = 0.0;
            foreach ($subjects as $subject) {
                $coef = (float) $subject['coefficient'];
                $weightSum += $coef;
                $weighted += $subject['average'] * $coef;
            }
            $average = $weightSum > 0 ? round($weighted / $weightSum, 2) : null;
            $rows[] = ['student_id' => $studentId, 'average' => $average];
        }

        usort($rows, static function (array $left, array $right): int {
            if ($left['average'] === null && $right['average'] === null) {
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

        $rank = 0;
        $previous = null;
        $index = 0;
        $connection->beginTransaction();
        try {
            foreach ($rows as $row) {
                ++$index;
                if ($row['average'] !== $previous) {
                    $rank = $index;
                    $previous = $row['average'];
                }
                $payload = [
                    'average' => $row['average'],
                    'rank' => $row['average'] === null ? null : $rank,
                    'appreciation' => $this->appreciation($row['average']),
                    'status' => 'Calcule',
                    'published_at' => date('Y-m-d H:i:s'),
                ];
                $existing = $connection->fetchOne('SELECT id FROM report_cards WHERE student_id = ? AND period_id = ?', [$row['student_id'], $periodId]);
                if ($existing !== false) {
                    $connection->update('report_cards', $payload, ['id' => $existing]);
                } else {
                    $connection->insert('report_cards', $payload + [
                        'student_id' => $row['student_id'],
                        'period_id' => $periodId,
                    ]);
                }
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return count($rows);
    }

    /**
     * @return list<array{name: string, coefficient: float, average: float, evaluations: int}>
     */
    private function subjectAverages(Connection $connection, int $studentId, int $classId, int $periodId): array
    {
        $grades = $connection->fetchAllAssociative(<<<SQL
            SELECT sub.id AS subject_id, sub.name, sub.default_coefficient, e.coefficient, e.scale, g.value
            FROM grades g
            INNER JOIN evaluations e ON e.id = g.evaluation_id
            INNER JOIN subjects sub ON sub.id = e.subject_id
            WHERE g.student_id = :student_id AND e.class_id = :class_id AND e.period_id = :period_id AND g.value IS NOT NULL
        SQL, [
            'student_id' => $studentId,
            'class_id' => $classId,
            'period_id' => $periodId,
        ]);

        $grouped = [];
        foreach ($grades as $grade) {
            $subjectId = (int) $grade['subject_id'];
            if (!isset($grouped[$subjectId])) {
                $grouped[$subjectId] = [
                    'name' => $grade['name'],
                    'coefficient' => (float) $grade['default_coefficient'],
                    'weighted' => 0.0,
                    'weights' => 0.0,
                    'evaluations' => 0,
                ];
            }
            $evalCoef = (float) $grade['coefficient'];
            $normalized = ((float) $grade['value'] / (float) $grade['scale']) * 20;
            $grouped[$subjectId]['weighted'] += $normalized * $evalCoef;
            $grouped[$subjectId]['weights'] += $evalCoef;
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

    private function appreciation(?float $average): string
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
}
