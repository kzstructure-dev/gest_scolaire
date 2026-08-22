<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;

/**
 * Gestion des eleves et de leur inscription dans l'annee scolaire courante.
 */
final class StudentManager
{
    public const SCHOOL_YEAR_ID = 1;
    public const SITE_ID = 1;

    /**
     * @var list<string>
     */
    public const STATUSES = ['Inscrit', 'A verifier', 'Transfere', 'Radie'];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException si les donnees saisies sont invalides
     */
    public function create(array $input, ?User $author): int
    {
        $data = $this->validate($input);
        $class = $this->requireClass($data['class_name']);

        $this->connection->beginTransaction();

        try {
            $this->connection->insert('students', [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'class_name' => $data['class_name'],
                'cycle' => self::cycleOf($data['class_name']),
                'phone' => $data['phone'],
                'status' => $data['status'],
            ]);

            $studentId = (int) $this->connection->lastInsertId();

            $this->connection->insert('registrations', [
                'student_id' => $studentId,
                'site_id' => self::SITE_ID,
                'school_year_id' => self::SCHOOL_YEAR_ID,
                'class_id' => (int) $class['id'],
                'registration_number' => 'INS-' . str_pad((string) $studentId, 5, '0', STR_PAD_LEFT),
                'status' => 'Validee',
            ]);

            $this->audit('creation', $studentId, null, $data, $author);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return $studentId;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException si les donnees saisies sont invalides ou l'eleve introuvable
     */
    public function update(int $studentId, array $input, ?User $author): void
    {
        $student = $this->find($studentId);
        $data = $this->validate($input);
        $class = $this->requireClass($data['class_name']);

        $this->connection->beginTransaction();

        try {
            $this->connection->update('students', [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'class_name' => $data['class_name'],
                'cycle' => self::cycleOf($data['class_name']),
                'phone' => $data['phone'],
                'status' => $data['status'],
            ], ['id' => $studentId]);

            $registration = $this->connection->fetchAssociative(
                'SELECT id, class_id FROM registrations WHERE student_id = ? AND school_year_id = ?',
                [$studentId, self::SCHOOL_YEAR_ID]
            );

            if ($registration === false) {
                $this->connection->insert('registrations', [
                    'student_id' => $studentId,
                    'site_id' => self::SITE_ID,
                    'school_year_id' => self::SCHOOL_YEAR_ID,
                    'class_id' => (int) $class['id'],
                    'registration_number' => 'INS-' . str_pad((string) $studentId, 5, '0', STR_PAD_LEFT),
                    'status' => 'Validee',
                ]);
            } elseif ((int) $registration['class_id'] !== (int) $class['id']) {
                $this->connection->update(
                    'registrations',
                    ['class_id' => (int) $class['id']],
                    ['id' => (int) $registration['id']]
                );
                $this->connection->insert('transfers', [
                    'student_id' => $studentId,
                    'from_class_id' => (int) $registration['class_id'],
                    'to_class_id' => (int) $class['id'],
                    'reason' => 'Changement de classe',
                ]);
            }

            $this->audit('modification', $studentId, $student, $data, $author);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @throws \InvalidArgumentException si l'eleve est introuvable
     */
    public function delete(int $studentId, ?User $author): void
    {
        $student = $this->find($studentId);

        $this->connection->beginTransaction();

        try {
            $this->connection->delete('transfers', ['student_id' => $studentId]);
            $this->connection->delete('registrations', ['student_id' => $studentId]);
            $this->connection->delete('students', ['id' => $studentId]);
            $this->audit('suppression', $studentId, $student, null, $author);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException si l'eleve est introuvable
     */
    public function find(int $studentId): array
    {
        $student = $this->connection->fetchAssociative('SELECT * FROM students WHERE id = ?', [$studentId]);

        if ($student === false) {
            throw new \InvalidArgumentException('Cet eleve n\'existe pas.');
        }

        return $student;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $search, string $cycle): array
    {
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

        $query = 'SELECT s.*, r.registration_number, r.registration_date, c.name AS registered_class'
            . ' FROM students s'
            . ' LEFT JOIN registrations r ON r.student_id = s.id AND r.school_year_id = ' . self::SCHOOL_YEAR_ID
            . ' LEFT JOIN classes c ON c.id = r.class_id';

        if ($where !== []) {
            $query .= ' WHERE ' . implode(' AND ', $where);
        }

        return $this->connection->fetchAllAssociative($query . ' ORDER BY s.id DESC', $parameters);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function classes(): array
    {
        return $this->connection->fetchAllAssociative('SELECT name, capacity FROM classes ORDER BY name');
    }

    public static function cycleOf(string $className): string
    {
        return preg_match('/^(CP|CE|CM)/i', $className) === 1 ? 'Primaire' : 'Secondaire';
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{first_name: string, last_name: string, class_name: string, phone: string, status: string}
     */
    private function validate(array $input): array
    {
        $data = [
            'first_name' => trim((string) ($input['first_name'] ?? '')),
            'last_name' => trim((string) ($input['last_name'] ?? '')),
            'class_name' => trim((string) ($input['class_name'] ?? '')),
            'phone' => trim((string) ($input['phone'] ?? '')),
            'status' => trim((string) ($input['status'] ?? 'Inscrit')),
        ];

        if ($data['first_name'] === '' || $data['last_name'] === '' || $data['class_name'] === '') {
            throw new \InvalidArgumentException('Les prenoms, le nom et la classe sont obligatoires.');
        }

        if ($data['phone'] !== '' && preg_match('/^[0-9 +().-]{8,20}$/', $data['phone']) !== 1) {
            throw new \InvalidArgumentException('Le numero de telephone est invalide.');
        }

        if (!in_array($data['status'], self::STATUSES, true)) {
            throw new \InvalidArgumentException('Le statut selectionne est inconnu.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException si la classe n'existe pas
     */
    private function requireClass(string $className): array
    {
        $class = $this->connection->fetchAssociative('SELECT id FROM classes WHERE name = ?', [$className]);

        if ($class === false) {
            throw new \InvalidArgumentException('La classe selectionnee n\'existe pas.');
        }

        return $class;
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function audit(string $action, int $studentId, ?array $before, ?array $after, ?User $author): void
    {
        $this->connection->insert('audit_logs', [
            'user_id' => $author?->getId(),
            'action' => $action,
            'entity_type' => 'students',
            'entity_id' => $studentId,
            'before_json' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after_json' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR),
        ]);
    }
}
