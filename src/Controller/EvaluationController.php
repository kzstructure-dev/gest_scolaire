<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EvaluationController extends AbstractController
{
    private const TYPES = ['Interrogation', 'Devoir', 'Composition', 'Examen blanc', 'Projet', 'Oral', 'Pratique', 'Examen officiel'];

    #[Route('/symfony/evaluations', name: 'app_evaluations', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection): Response
    {
        $message = null;
        $error = null;
        $classId = (int) $request->query->get('class_id', 0);

        if ($request->isMethod('POST')) {
            $title = trim((string) $request->request->get('title'));
            $classId = (int) $request->request->get('class_id');
            $subjectId = (int) $request->request->get('subject_id');
            $periodId = (int) $request->request->get('period_id');
            $type = (string) $request->request->get('evaluation_type', 'Devoir');
            $date = (string) $request->request->get('evaluation_date', date('Y-m-d'));
            $scale = (float) $request->request->get('scale', 20);
            $coefficient = (float) $request->request->get('coefficient', 1);

            if ($title === '' || $classId < 1 || $subjectId < 1 || $periodId < 1) {
                $error = 'Le titre, la classe, la matiere et la periode sont obligatoires.';
            } elseif (!in_array($type, self::TYPES, true)) {
                $error = 'Type d\'evaluation invalide.';
            } elseif ($scale <= 0 || $coefficient <= 0) {
                $error = 'Le bareme et le coefficient doivent etre superieurs a zero.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $error = 'La date de l\'evaluation est invalide.';
            } else {
                $connection->insert('evaluations', [
                    'class_id' => $classId,
                    'subject_id' => $subjectId,
                    'period_id' => $periodId,
                    'title' => $title,
                    'evaluation_type' => $type,
                    'evaluation_date' => $date,
                    'scale' => $scale,
                    'coefficient' => $coefficient,
                    'status' => 'Saisie',
                ]);
                $evaluationId = (int) $connection->lastInsertId();

                return $this->redirectToRoute('app_evaluation_grades', ['id' => $evaluationId]);
            }
        }

        $parameters = [];
        $where = '';
        if ($classId > 0) {
            $where = 'WHERE e.class_id = :class_id';
            $parameters['class_id'] = $classId;
        }

        $evaluations = $connection->fetchAllAssociative(<<<SQL
            SELECT e.id, e.title, e.evaluation_type, e.evaluation_date, e.scale, e.coefficient, e.status,
                   c.name AS class_name, s.name AS subject_name, p.name AS period_name,
                   COUNT(g.id) AS grade_count
            FROM evaluations e
            INNER JOIN classes c ON c.id = e.class_id
            INNER JOIN subjects s ON s.id = e.subject_id
            LEFT JOIN periods p ON p.id = e.period_id
            LEFT JOIN grades g ON g.evaluation_id = e.id AND g.value IS NOT NULL
            $where
            GROUP BY e.id, e.title, e.evaluation_type, e.evaluation_date, e.scale, e.coefficient, e.status, c.name, s.name, p.name
            ORDER BY e.evaluation_date DESC, e.id DESC
        SQL, $parameters);

        return $this->render('evaluations/index.html.twig', [
            'evaluations' => $evaluations,
            'classes' => $connection->fetchAllAssociative('SELECT c.id, c.name, cy.name AS cycle FROM classes c JOIN levels l ON l.id = c.level_id JOIN cycles cy ON cy.id = l.cycle_id ORDER BY l.sort_order, c.name'),
            'subjects' => $connection->fetchAllAssociative('SELECT id, name FROM subjects WHERE active = 1 ORDER BY name'),
            'periods' => $connection->fetchAllAssociative('SELECT id, name FROM periods WHERE school_year_id = 1 ORDER BY start_date'),
            'types' => self::TYPES,
            'classId' => $classId,
            'message' => $message,
            'error' => $error,
            'total' => count($evaluations),
        ]);
    }

    #[Route('/symfony/evaluations/{id}', name: 'app_evaluation_grades', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function grades(int $id, Request $request, Connection $connection): Response
    {
        $evaluation = $connection->fetchAssociative(<<<SQL
            SELECT e.*, c.name AS class_name, s.name AS subject_name, p.name AS period_name
            FROM evaluations e
            INNER JOIN classes c ON c.id = e.class_id
            INNER JOIN subjects s ON s.id = e.subject_id
            LEFT JOIN periods p ON p.id = e.period_id
            WHERE e.id = ?
        SQL, [$id]);

        if ($evaluation === false) {
            throw $this->createNotFoundException('Evaluation introuvable.');
        }

        $message = null;
        $error = null;
        $scale = (float) $evaluation['scale'];

        if ($request->isMethod('POST')) {
            $values = $request->request->all('value');
            $appreciations = $request->request->all('appreciation');
            $connection->beginTransaction();
            try {
                foreach ($values as $studentId => $rawValue) {
                    $studentId = (int) $studentId;
                    $rawValue = trim((string) $rawValue);
                    $appreciation = trim((string) ($appreciations[$studentId] ?? ''));
                    if ($studentId < 1) {
                        continue;
                    }
                    if ($rawValue === '') {
                        $connection->executeStatement('DELETE FROM grades WHERE evaluation_id = ? AND student_id = ?', [$id, $studentId]);
                        continue;
                    }
                    if (!is_numeric($rawValue)) {
                        throw new \InvalidArgumentException('Note invalide.');
                    }
                    $value = (float) $rawValue;
                    if ($value < 0 || $value > $scale) {
                        throw new \InvalidArgumentException('Chaque note doit etre comprise entre 0 et ' . $scale . '.');
                    }
                    $existing = $connection->fetchOne('SELECT id FROM grades WHERE evaluation_id = ? AND student_id = ?', [$id, $studentId]);
                    $payload = ['value' => $value, 'appreciation' => $appreciation, 'status' => 'Saisie'];
                    if ($existing !== false) {
                        $connection->update('grades', $payload, ['id' => $existing]);
                    } else {
                        $connection->insert('grades', $payload + ['evaluation_id' => $id, 'student_id' => $studentId]);
                    }
                }
                $connection->commit();
                $message = 'Notes enregistrees.';
            } catch (\InvalidArgumentException $exception) {
                $connection->rollBack();
                $error = $exception->getMessage();
            } catch (\Throwable) {
                $connection->rollBack();
                $error = 'Impossible d\'enregistrer les notes.';
            }
        }

        $students = $connection->fetchAllAssociative(<<<SQL
            SELECT s.id, s.first_name, s.last_name, g.value, g.appreciation
            FROM students s
            INNER JOIN registrations r ON r.student_id = s.id AND r.school_year_id = 1 AND r.status = 'Validee' AND r.class_id = :class_id
            LEFT JOIN grades g ON g.student_id = s.id AND g.evaluation_id = :evaluation_id
            ORDER BY s.last_name, s.first_name
        SQL, ['class_id' => $evaluation['class_id'], 'evaluation_id' => $id]);

        $entered = 0;
        $sumNormalized = 0.0;
        foreach ($students as $student) {
            if ($student['value'] === null || $student['value'] === '') {
                continue;
            }
            ++$entered;
            $sumNormalized += ((float) $student['value'] / $scale) * 20;
        }

        return $this->render('evaluations/grades.html.twig', [
            'evaluation' => $evaluation,
            'students' => $students,
            'message' => $message,
            'error' => $error,
            'entered' => $entered,
            'missing' => count($students) - $entered,
            'average' => $entered > 0 ? round($sumNormalized / $entered, 2) : null,
        ]);
    }
}
