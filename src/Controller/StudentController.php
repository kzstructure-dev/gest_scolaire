<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StudentController extends AbstractController
{
    #[Route('/symfony/students', name: 'app_students', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection): Response
    {
        $message = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $firstName = trim((string) $request->request->get('first_name'));
            $lastName = trim((string) $request->request->get('last_name'));
            $className = trim((string) $request->request->get('class_name'));
            $phone = trim((string) $request->request->get('phone'));

            if ($firstName === '' || $lastName === '' || $className === '') {
                $error = 'Les prenoms, le nom et la classe sont obligatoires.';
            } else {
                $class = $connection->fetchAssociative('SELECT id FROM classes WHERE name = ?', [$className]);
                if ($class === false) {
                    $error = 'La classe selectionnee n\'existe pas.';
                } else {
                    $connection->beginTransaction();
                    try {
                        $connection->insert('students', [
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                            'class_name' => $className,
                            'cycle' => preg_match('/^(CP|CE|CM)/i', $className) === 1 ? 'Primaire' : 'Secondaire',
                            'phone' => $phone,
                            'status' => 'Inscrit',
                        ]);
                        $studentId = (int) $connection->lastInsertId();
                        $connection->insert('registrations', [
                            'student_id' => $studentId,
                            'site_id' => 1,
                            'school_year_id' => 1,
                            'class_id' => (int) $class['id'],
                            'registration_number' => 'INS-' . str_pad((string) $studentId, 5, '0', STR_PAD_LEFT),
                            'status' => 'Validee',
                        ]);
                        $connection->commit();
                        $message = 'Inscription enregistree avec succes.';
                    } catch (\Throwable $exception) {
                        $connection->rollBack();
                        $error = 'Impossible d\'enregistrer cette inscription.';
                    }
                }
            }
        }

        $search = trim((string) $request->query->get('search', ''));
        $cycle = trim((string) $request->query->get('cycle', ''));
        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = '(s.first_name || \' \' || s.last_name LIKE :search OR s.class_name LIKE :search OR s.phone LIKE :search)';
            $parameters['search'] = '%' . $search . '%';
        }
        if (in_array($cycle, ['Primaire', 'Secondaire'], true)) {
            $where[] = 's.cycle = :cycle';
            $parameters['cycle'] = $cycle;
        }
        $query = 'SELECT s.*, r.registration_number, r.registration_date, c.name AS registered_class FROM students s LEFT JOIN registrations r ON r.student_id = s.id AND r.school_year_id = 1 LEFT JOIN classes c ON c.id = r.class_id';
        if ($where !== []) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }
        $query .= ' ORDER BY s.id DESC';
        $students = $connection->fetchAllAssociative($query, $parameters);
        $classes = $connection->fetchAllAssociative('SELECT name, capacity FROM classes ORDER BY name');

        return $this->render('students/index.html.twig', [
            'students' => $students,
            'classes' => $classes,
            'search' => $search,
            'cycle' => $cycle,
            'message' => $message,
            'error' => $error,
        ]);
    }
}
