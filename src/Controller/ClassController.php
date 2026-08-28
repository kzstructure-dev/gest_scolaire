<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ClassController extends AbstractController
{
    private const SCHOOL_YEAR_ID = 1;
    private const OCCUPANCY = ['libre' => 'Places disponibles', 'complete' => 'Classes completes', 'surcharge' => 'Classes en surcharge'];

    #[Route('/symfony/classes', name: 'app_classes', methods: ['GET'])]
    public function index(Request $request, Connection $connection): Response
    {
        $cycles = $connection->fetchAllAssociative('SELECT name FROM cycles ORDER BY id');
        $filters = [
            'search' => trim((string) $request->query->get('search', '')),
            'cycle' => $this->allowed($request->query->get('cycle'), array_column($cycles, 'name')),
            'occupancy' => $this->allowed($request->query->get('occupancy'), array_keys(self::OCCUPANCY)),
        ];

        $classes = $this->classes($connection, $filters);

        return $this->render('classes/index.html.twig', [
            'groups' => $this->groupByCycle($classes),
            'cycles' => $cycles,
            'occupancies' => self::OCCUPANCY,
            'filters' => $filters,
            'stats' => $this->stats($classes),
        ]);
    }

    /**
     * @param list<string> $allowed
     */
    private function allowed(mixed $value, array $allowed): string
    {
        $value = trim((string) $value);

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * @param array{search: string, cycle: string, occupancy: string} $filters
     *
     * @return list<array<string, mixed>>
     */
    private function classes(Connection $connection, array $filters): array
    {
        $where = ['c.school_year_id = :year', 'c.active = 1'];
        $parameters = ['year' => self::SCHOOL_YEAR_ID];
        if ($filters['search'] !== '') {
            $where[] = '(c.name LIKE :search OR l.name LIKE :search OR r.name LIKE :search)';
            $parameters['search'] = '%' . $filters['search'] . '%';
        }
        if ($filters['cycle'] !== '') {
            $where[] = 'cy.name = :cycle';
            $parameters['cycle'] = $filters['cycle'];
        }
        $having = match ($filters['occupancy']) {
            'libre' => ' HAVING student_count < c.capacity',
            'complete' => ' HAVING student_count = c.capacity',
            'surcharge' => ' HAVING student_count > c.capacity',
            default => '',
        };
        $whereSql = implode(' AND ', $where);

        return $connection->fetchAllAssociative(<<<SQL
            SELECT c.id, c.name, c.capacity, l.name AS level_name, l.sort_order, cy.id AS cycle_id, cy.name AS cycle,
                   r.name AS room_name,
                   (SELECT COUNT(*) FROM registrations reg WHERE reg.class_id = c.id AND reg.status = 'Validee') AS student_count
            FROM classes c
            INNER JOIN levels l ON l.id = c.level_id
            INNER JOIN cycles cy ON cy.id = l.cycle_id
            LEFT JOIN rooms r ON r.id = c.room_id
            WHERE $whereSql
            GROUP BY c.id$having
            ORDER BY cy.id, l.sort_order, c.name
        SQL, $parameters);
    }

    /**
     * @param list<array<string, mixed>> $classes
     *
     * @return list<array{cycle: string, classes: list<array<string, mixed>>, students: int, capacity: int}>
     */
    private function groupByCycle(array $classes): array
    {
        $groups = [];
        foreach ($classes as $class) {
            $cycle = (string) $class['cycle'];
            $groups[$cycle]['cycle'] = $cycle;
            $groups[$cycle]['classes'][] = $class;
            $groups[$cycle]['students'] = ($groups[$cycle]['students'] ?? 0) + (int) $class['student_count'];
            $groups[$cycle]['capacity'] = ($groups[$cycle]['capacity'] ?? 0) + (int) $class['capacity'];
        }

        return array_values($groups);
    }

    /**
     * @param list<array<string, mixed>> $classes
     *
     * @return array{classes: int, students: int, capacity: int, seats: int, full: int}
     */
    private function stats(array $classes): array
    {
        $students = array_sum(array_map(static fn (array $class): int => (int) $class['student_count'], $classes));
        $capacity = array_sum(array_map(static fn (array $class): int => (int) $class['capacity'], $classes));

        return [
            'classes' => count($classes),
            'students' => $students,
            'capacity' => $capacity,
            'seats' => max(0, $capacity - $students),
            'full' => count(array_filter($classes, static fn (array $class): bool => (int) $class['student_count'] >= (int) $class['capacity'])),
        ];
    }
}
