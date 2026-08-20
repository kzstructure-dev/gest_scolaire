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
    #[Route('/symfony/classes', name: 'app_classes', methods: ['GET'])]
    public function index(Request $request, Connection $connection): Response
    {
        $cycle = trim((string) $request->query->get('cycle', ''));
        $parameters = [];
        $where = '';
        if (in_array($cycle, ['Primaire', 'Secondaire'], true)) {
            $where = 'WHERE cy.name = :cycle';
            $parameters['cycle'] = $cycle;
        }

        $classes = $connection->fetchAllAssociative(<<<SQL
            SELECT c.id, c.name, c.capacity, l.name AS level_name, cy.name AS cycle,
                   COUNT(r.id) AS student_count
            FROM classes c
            INNER JOIN levels l ON l.id = c.level_id
            INNER JOIN cycles cy ON cy.id = l.cycle_id
            LEFT JOIN registrations r ON r.class_id = c.id AND r.status = 'Validee'
            $where
            GROUP BY c.id, c.name, c.capacity, l.name, cy.name
            ORDER BY l.sort_order, c.name
        SQL, $parameters);

        return $this->render('classes/index.html.twig', [
            'classes' => $classes,
            'cycle' => $cycle,
            'total' => count($classes),
            'primaryCount' => count(array_filter($classes, static fn (array $class): bool => $class['cycle'] === 'Primaire')),
            'secondaryCount' => count(array_filter($classes, static fn (array $class): bool => $class['cycle'] === 'Secondaire')),
        ]);
    }
}
