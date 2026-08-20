<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AttendanceController extends AbstractController
{
    private const STATUSES = ['Present', 'Absent', 'Retard', 'Dispense', 'Sortie autorisee'];

    #[Route('/symfony/attendance', name: 'app_attendance', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection): Response
    {
        $date = $this->normalizeDate((string) $request->request->get('date', $request->query->get('date', date('Y-m-d'))));
        $classId = (int) $request->request->get('class_id', $request->query->get('class_id', 0));
        $message = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $statuses = $request->request->all('status');
            $minutesLate = $request->request->all('minutes_late');
            $reasons = $request->request->all('reason');

            if ($classId < 1 || $statuses === []) {
                $error = 'Selectionnez une classe et au moins un eleve.';
            } else {
                $connection->beginTransaction();
                try {
                    foreach ($statuses as $studentId => $status) {
                        $studentId = (int) $studentId;
                        if ($studentId < 1 || !in_array($status, self::STATUSES, true)) {
                            continue;
                        }

                        $payload = [
                            'status' => $status,
                            'minutes_late' => max(0, (int) ($minutesLate[$studentId] ?? 0)),
                            'reason' => trim((string) ($reasons[$studentId] ?? '')),
                        ];
                        $existing = $connection->fetchOne(
                            'SELECT id FROM attendance WHERE student_id = ? AND class_id = ? AND attendance_date = ?',
                            [$studentId, $classId, $date]
                        );
                        if ($existing !== false) {
                            $connection->update('attendance', $payload, ['id' => $existing]);
                        } else {
                            $connection->insert('attendance', $payload + [
                                'student_id' => $studentId,
                                'class_id' => $classId,
                                'attendance_date' => $date,
                            ]);
                        }
                    }
                    $connection->commit();
                    $message = 'Feuille de presence enregistree pour le ' . $date . '.';
                } catch (\Throwable) {
                    $connection->rollBack();
                    $error = 'Impossible d\'enregistrer les presences.';
                }
            }
        }

        $classes = $connection->fetchAllAssociative('SELECT c.id, c.name, cy.name AS cycle FROM classes c JOIN levels l ON l.id = c.level_id JOIN cycles cy ON cy.id = l.cycle_id ORDER BY l.sort_order, c.name');
        $students = [];
        if ($classId > 0) {
            $students = $connection->fetchAllAssociative(
                <<<SQL
                SELECT s.id, s.first_name, s.last_name, s.class_name, s.cycle, a.status, a.minutes_late, a.reason
                FROM students s
                INNER JOIN registrations r ON r.student_id = s.id AND r.school_year_id = 1 AND r.status = 'Validee' AND r.class_id = :class_id
                LEFT JOIN attendance a ON a.student_id = s.id AND a.class_id = :class_id AND a.attendance_date = :date
                ORDER BY s.last_name, s.first_name
                SQL,
                ['class_id' => $classId, 'date' => $date]
            );
        }

        $counts = [
            'total' => count($students),
            'present' => 0,
            'absent' => 0,
            'late' => 0,
        ];
        foreach ($students as $student) {
            if ($student['status'] === 'Present') {
                ++$counts['present'];
            } elseif ($student['status'] === 'Absent') {
                ++$counts['absent'];
            } elseif ($student['status'] === 'Retard') {
                ++$counts['late'];
            }
        }

        return $this->render('attendance/index.html.twig', [
            'classes' => $classes,
            'students' => $students,
            'date' => $date,
            'classId' => $classId,
            'message' => $message,
            'error' => $error,
            'counts' => $counts,
            'statuses' => self::STATUSES,
        ]);
    }

    private function normalizeDate(string $date): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : date('Y-m-d');
    }
}
