<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class StudentController extends AbstractController
{
    private const SCHOOL_YEAR_ID = 1;
    private const SITE_ID = 1;
    private const PER_PAGE = 20;
    private const STATUSES = ['Inscrit', 'A verifier', 'Retard paiement', 'Suspendu', 'Transfere', 'Sorti'];
    private const SORTS = [
        'recent' => 's.id DESC',
        'name' => 's.last_name COLLATE NOCASE, s.first_name COLLATE NOCASE',
        'class' => 's.class_name COLLATE NOCASE, s.last_name COLLATE NOCASE',
        'status' => 's.status COLLATE NOCASE, s.last_name COLLATE NOCASE',
    ];

    #[Route('/symfony/students', name: 'app_students', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection): Response
    {
        if ($request->isMethod('POST')) {
            try {
                $this->addFlash('success', $this->createStudent($request, $connection));
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable) {
                $this->addFlash('error', 'Impossible d\'enregistrer cette inscription.');
            }

            return $this->redirectToRoute('app_students', $request->query->all());
        }

        $filters = $this->filters($request, $connection);
        [$where, $parameters] = $this->buildCriteria($filters);
        $total = (int) $connection->fetchOne('SELECT COUNT(*) FROM students s' . $where, $parameters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, (int) $request->query->get('page', 1)), $pages);

        $students = $connection->fetchAllAssociative(
            $this->listQuery($where) . ' ORDER BY ' . self::SORTS[$filters['sort']] . ' LIMIT ' . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $parameters
        );

        return $this->render('students/index.html.twig', [
            'students' => $students,
            'classes' => $this->classes($connection),
            'cycles' => $this->cycles($connection),
            'statuses' => self::STATUSES,
            'filters' => $filters,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'stats' => $this->stats($connection),
        ]);
    }

    #[Route('/symfony/students/export', name: 'app_students_export', methods: ['GET'])]
    public function export(Request $request, Connection $connection): Response
    {
        $filters = $this->filters($request, $connection);
        [$where, $parameters] = $this->buildCriteria($filters);
        $students = $connection->fetchAllAssociative(
            $this->listQuery($where) . ' ORDER BY ' . self::SORTS[$filters['sort']],
            $parameters
        );

        $response = new StreamedResponse(static function () use ($students): void {
            $handle = fopen('php://output', 'wb');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Matricule', 'Nom', 'Prenoms', 'Cycle', 'Classe', 'Telephone', 'Date inscription', 'Statut'], ';', '"', '\\');
            foreach ($students as $student) {
                fputcsv($handle, [
                    $student['registration_number'] ?? '',
                    $student['last_name'],
                    $student['first_name'],
                    $student['cycle'],
                    $student['registered_class'] ?: $student['class_name'],
                    $student['phone'],
                    $student['registration_date'] ?? '',
                    $student['status'],
                ], ';', '"', '\\');
            }
            fclose($handle);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="eleves-' . date('Y-m-d') . '.csv"');

        return $response;
    }

    #[Route('/symfony/students/{id}', name: 'app_student_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(int $id, Connection $connection): Response
    {
        $student = $connection->fetchAssociative($this->listQuery(' WHERE s.id = :id'), ['id' => $id]);
        if ($student === false) {
            throw $this->createNotFoundException('Eleve introuvable.');
        }

        return $this->render('students/show.html.twig', [
            'student' => $student,
            'classes' => $this->classes($connection),
            'statuses' => array_values(array_unique([...self::STATUSES, (string) $student['status']])),
            'guardians' => $connection->fetchAllAssociative(<<<SQL
                SELECT g.first_name, g.last_name, g.relationship, g.phone, g.email, sg.is_legal, sg.can_pick_up
                FROM student_guardians sg
                INNER JOIN guardians g ON g.id = sg.guardian_id
                WHERE sg.student_id = ?
                ORDER BY sg.is_legal DESC, g.last_name
            SQL, [$id]),
            'grades' => $connection->fetchAllAssociative(<<<SQL
                SELECT sub.name AS subject, e.title, e.evaluation_type, e.evaluation_date, e.scale, e.coefficient, g.value
                FROM grades g
                INNER JOIN evaluations e ON e.id = g.evaluation_id
                INNER JOIN subjects sub ON sub.id = e.subject_id
                WHERE g.student_id = ? AND g.value IS NOT NULL
                ORDER BY e.evaluation_date DESC, sub.name
                LIMIT 15
            SQL, [$id]),
            'average' => $this->average($connection, $id),
            'attendance' => $this->attendanceSummary($connection, $id),
            'finance' => $this->financeSummary($connection, $id),
            'transfers' => $connection->fetchAllAssociative(<<<SQL
                SELECT t.transfer_date, t.reason, t.status, cf.name AS from_class, ct.name AS to_class
                FROM transfers t
                LEFT JOIN classes cf ON cf.id = t.from_class_id
                LEFT JOIN classes ct ON ct.id = t.to_class_id
                WHERE t.student_id = ?
                ORDER BY t.id DESC
            SQL, [$id]),
            'documents' => $connection->fetchAllAssociative(
                'SELECT document_type, status, submitted_at FROM student_documents WHERE student_id = ? ORDER BY document_type',
                [$id]
            ),
        ]);
    }

    #[Route('/symfony/students/{id}', name: 'app_student_update', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function update(int $id, Request $request, Connection $connection): Response
    {
        try {
            $this->addFlash('success', $this->updateStudent($id, $request, $connection));
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible de mettre a jour cette fiche.');
        }

        return $this->redirectToRoute('app_student_show', ['id' => $id]);
    }

    /**
     * @return array{search: string, cycle: string, class_name: string, status: string, sort: string}
     */
    private function filters(Request $request, Connection $connection): array
    {
        $cycle = trim((string) $request->query->get('cycle', ''));
        $className = trim((string) $request->query->get('class_name', ''));
        $status = trim((string) $request->query->get('status', ''));
        $sort = (string) $request->query->get('sort', 'recent');

        return [
            'search' => trim((string) $request->query->get('search', '')),
            'cycle' => in_array($cycle, array_column($this->cycles($connection), 'name'), true) ? $cycle : '',
            'class_name' => in_array($className, array_column($this->classes($connection), 'name'), true) ? $className : '',
            'status' => in_array($status, self::STATUSES, true) ? $status : '',
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'recent',
        ];
    }

    /**
     * @param array{search: string, cycle: string, class_name: string, status: string, sort: string} $filters
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildCriteria(array $filters): array
    {
        $where = [];
        $parameters = [];
        if ($filters['search'] !== '') {
            $where[] = "(s.first_name || ' ' || s.last_name LIKE :search OR s.last_name || ' ' || s.first_name LIKE :search OR s.class_name LIKE :search OR s.phone LIKE :search)";
            $parameters['search'] = '%' . $filters['search'] . '%';
        }
        foreach (['cycle' => 's.cycle', 'class_name' => 's.class_name', 'status' => 's.status'] as $key => $column) {
            if ($filters[$key] !== '') {
                $where[] = $column . ' = :' . $key;
                $parameters[$key] = $filters[$key];
            }
        }

        return [$where === [] ? '' : ' WHERE ' . implode(' AND ', $where), $parameters];
    }

    private function listQuery(string $where): string
    {
        $schoolYearId = self::SCHOOL_YEAR_ID;

        return <<<SQL
            SELECT s.*, r.registration_number, r.registration_date, r.status AS registration_status,
                   c.id AS class_id, c.name AS registered_class, c.capacity,
                   (SELECT COUNT(*) FROM registrations rc WHERE rc.class_id = c.id AND rc.status = 'Validee') AS class_size
            FROM students s
            LEFT JOIN registrations r ON r.student_id = s.id AND r.school_year_id = $schoolYearId
            LEFT JOIN classes c ON c.id = r.class_id
            $where
        SQL;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function classes(Connection $connection): array
    {
        return $connection->fetchAllAssociative(<<<SQL
            SELECT c.id, c.name, c.capacity, cy.name AS cycle, l.name AS level_name,
                   (SELECT COUNT(*) FROM registrations r WHERE r.class_id = c.id AND r.status = 'Validee') AS student_count
            FROM classes c
            INNER JOIN levels l ON l.id = c.level_id
            INNER JOIN cycles cy ON cy.id = l.cycle_id
            WHERE c.school_year_id = ? AND c.active = 1
            ORDER BY l.sort_order, c.name
        SQL, [self::SCHOOL_YEAR_ID]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cycles(Connection $connection): array
    {
        return $connection->fetchAllAssociative('SELECT name FROM cycles ORDER BY id');
    }

    /**
     * @return array{total: int, byCycle: list<array<string, mixed>>, unregistered: int, toCheck: int}
     */
    private function stats(Connection $connection): array
    {
        return [
            'total' => (int) $connection->fetchOne('SELECT COUNT(*) FROM students'),
            'byCycle' => $connection->fetchAllAssociative('SELECT cycle, COUNT(*) AS total FROM students GROUP BY cycle ORDER BY cycle'),
            'unregistered' => (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM students s WHERE NOT EXISTS (SELECT 1 FROM registrations r WHERE r.student_id = s.id AND r.school_year_id = ?)',
                [self::SCHOOL_YEAR_ID]
            ),
            'toCheck' => (int) $connection->fetchOne("SELECT COUNT(*) FROM students WHERE status = 'A verifier'"),
        ];
    }

    private function createStudent(Request $request, Connection $connection): string
    {
        $firstName = $this->name($request->request->get('first_name'), 'Le prenom');
        $lastName = $this->name($request->request->get('last_name'), 'Le nom');
        $phone = $this->phone($request->request->get('phone'));
        $class = $this->availableClass($connection, trim((string) $request->request->get('class_name')));

        $duplicate = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM students WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?)',
            [$firstName, $lastName]
        );
        if ($duplicate > 0) {
            throw new \InvalidArgumentException('Un eleve portant ce nom est deja enregistre.');
        }

        $connection->beginTransaction();
        try {
            $connection->insert('students', [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'class_name' => $class['name'],
                'cycle' => $class['cycle'],
                'phone' => $phone,
                'status' => 'Inscrit',
            ]);
            $studentId = (int) $connection->lastInsertId();
            $connection->insert('registrations', [
                'student_id' => $studentId,
                'site_id' => self::SITE_ID,
                'school_year_id' => self::SCHOOL_YEAR_ID,
                'class_id' => (int) $class['id'],
                'registration_number' => $this->registrationNumber($studentId),
                'registration_date' => date('Y-m-d'),
                'status' => 'Validee',
            ]);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        return sprintf('%s %s inscrit(e) en %s.', $firstName, $lastName, $class['name']);
    }

    private function updateStudent(int $id, Request $request, Connection $connection): string
    {
        $student = $connection->fetchAssociative('SELECT * FROM students WHERE id = ?', [$id]);
        if ($student === false) {
            throw $this->createNotFoundException('Eleve introuvable.');
        }

        $firstName = $this->name($request->request->get('first_name'), 'Le prenom');
        $lastName = $this->name($request->request->get('last_name'), 'Le nom');
        $phone = $this->phone($request->request->get('phone'));
        $status = (string) $request->request->get('status', $student['status']);
        if ($status !== (string) $student['status'] && !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Statut invalide.');
        }
        $duplicate = (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM students WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) AND id <> ?',
            [$firstName, $lastName, $id]
        );
        if ($duplicate > 0) {
            throw new \InvalidArgumentException('Un autre eleve porte deja ce nom.');
        }

        $className = trim((string) $request->request->get('class_name'));
        $classChanged = $className !== (string) $student['class_name'];
        $class = $classChanged
            ? $this->availableClass($connection, $className)
            : $this->classByName($connection, $className);

        $connection->beginTransaction();
        try {
            $connection->update('students', [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $phone,
                'status' => $status,
                'class_name' => $class['name'],
                'cycle' => $class['cycle'],
            ], ['id' => $id]);

            if ($classChanged) {
                $registration = $connection->fetchAssociative(
                    'SELECT id, class_id FROM registrations WHERE student_id = ? AND school_year_id = ?',
                    [$id, self::SCHOOL_YEAR_ID]
                );
                if ($registration === false) {
                    $connection->insert('registrations', [
                        'student_id' => $id,
                        'site_id' => self::SITE_ID,
                        'school_year_id' => self::SCHOOL_YEAR_ID,
                        'class_id' => (int) $class['id'],
                        'registration_number' => $this->registrationNumber($id),
                        'registration_date' => date('Y-m-d'),
                        'status' => 'Validee',
                    ]);
                } else {
                    $connection->update('registrations', ['class_id' => (int) $class['id']], ['id' => (int) $registration['id']]);
                }
                $connection->insert('transfers', [
                    'student_id' => $id,
                    'from_class_id' => $registration === false ? null : $registration['class_id'],
                    'to_class_id' => (int) $class['id'],
                    'transfer_date' => date('Y-m-d'),
                    'reason' => trim((string) $request->request->get('reason')),
                    'status' => 'Valide',
                ]);
            }
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        return $classChanged ? 'Fiche mise a jour, transfert vers ' . $class['name'] . ' enregistre.' : 'Fiche mise a jour.';
    }

    /**
     * @return array<string, mixed>
     */
    private function classByName(Connection $connection, string $className): array
    {
        foreach ($this->classes($connection) as $class) {
            if ($class['name'] === $className) {
                return $class;
            }
        }

        throw new \InvalidArgumentException('La classe selectionnee n\'existe pas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function availableClass(Connection $connection, string $className): array
    {
        $class = $this->classByName($connection, $className);
        if ((int) $class['student_count'] >= (int) $class['capacity']) {
            throw new \InvalidArgumentException(sprintf('La classe %s est complete (%d places).', $class['name'], (int) $class['capacity']));
        }

        return $class;
    }

    private function name(mixed $value, string $label): string
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
        if ($name === '') {
            throw new \InvalidArgumentException($label . ' est obligatoire.');
        }
        if (mb_strlen($name) > 120) {
            throw new \InvalidArgumentException($label . ' ne peut pas depasser 120 caracteres.');
        }
        if (preg_match('/^[\p{L}\' -]+$/u', $name) !== 1) {
            throw new \InvalidArgumentException($label . ' ne peut contenir que des lettres, espaces, apostrophes et tirets.');
        }

        return $name;
    }

    private function phone(mixed $value): string
    {
        $phone = trim((string) $value);
        if ($phone === '') {
            return '';
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) !== 10) {
            throw new \InvalidArgumentException('Le telephone doit contenir 10 chiffres (ex. 07 00 00 00 00).');
        }

        return trim(chunk_split($digits, 2, ' '));
    }

    private function registrationNumber(int $studentId): string
    {
        return 'INS-' . date('Y') . '-' . str_pad((string) $studentId, 5, '0', STR_PAD_LEFT);
    }

    private function average(Connection $connection, int $studentId): ?float
    {
        $row = $connection->fetchAssociative(<<<SQL
            SELECT SUM(g.value / e.scale * 20 * e.coefficient) AS weighted, SUM(e.coefficient) AS coefficients
            FROM grades g
            INNER JOIN evaluations e ON e.id = g.evaluation_id
            WHERE g.student_id = ? AND g.value IS NOT NULL AND e.scale > 0
        SQL, [$studentId]);

        if ($row === false || $row['coefficients'] === null || (float) $row['coefficients'] <= 0.0) {
            return null;
        }

        return round((float) $row['weighted'] / (float) $row['coefficients'], 2);
    }

    /**
     * @return array{total: int, present: int, absent: int, late: int, rate: float|null}
     */
    private function attendanceSummary(Connection $connection, int $studentId): array
    {
        $row = $connection->fetchAssociative(<<<SQL
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present,
                   SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absent,
                   SUM(CASE WHEN status = 'Retard' THEN 1 ELSE 0 END) AS late
            FROM attendance WHERE student_id = ?
        SQL, [$studentId]);
        $total = $row === false ? 0 : (int) $row['total'];

        return [
            'total' => $total,
            'present' => $row === false ? 0 : (int) $row['present'],
            'absent' => $row === false ? 0 : (int) $row['absent'],
            'late' => $row === false ? 0 : (int) $row['late'],
            'rate' => $total > 0 ? round((int) $row['present'] / $total * 100, 1) : null,
        ];
    }

    /**
     * @return array{invoiced: float, paid: float, remaining: float, invoices: list<array<string, mixed>>}
     */
    private function financeSummary(Connection $connection, int $studentId): array
    {
        $invoices = $connection->fetchAllAssociative(<<<SQL
            SELECT i.invoice_number, i.amount, i.due_date, i.status, t.label AS tariff_label,
                   COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.status = 'Valide'), 0) AS paid
            FROM invoices i
            INNER JOIN tariffs t ON t.id = i.tariff_id
            WHERE i.student_id = ?
            ORDER BY i.id DESC
        SQL, [$studentId]);

        $invoiced = 0.0;
        $paid = 0.0;
        foreach ($invoices as &$invoice) {
            $invoice['remaining'] = round((float) $invoice['amount'] - (float) $invoice['paid'], 2);
            $invoiced += (float) $invoice['amount'];
            $paid += (float) $invoice['paid'];
        }
        unset($invoice);

        return [
            'invoiced' => $invoiced,
            'paid' => $paid,
            'remaining' => round($invoiced - $paid, 2),
            'invoices' => $invoices,
        ];
    }
}
