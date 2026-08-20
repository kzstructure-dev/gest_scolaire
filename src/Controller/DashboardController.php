<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Student;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard', methods: ['GET'])]
    public function index(EntityManagerInterface $entityManager, Connection $connection): Response
    {
        $students = $entityManager->getRepository(Student::class)->findBy([], ['id' => 'DESC']);
        $primaryCount = count(array_filter($students, static fn (Student $student): bool => $student->getCycle() === 'Primaire'));
        $moduleGroups = [
            'Etablissement' => [
                ['label' => 'Sites', 'table' => 'sites'],
                ['label' => 'Annees scolaires', 'table' => 'school_years'],
                ['label' => 'Periodes', 'table' => 'periods'],
            ],
            'Scolarite' => [
                ['label' => 'Candidats', 'table' => 'candidates'],
                ['label' => 'Inscriptions', 'table' => 'registrations', 'route' => 'app_students'],
                ['label' => 'Transferts', 'table' => 'transfers'],
                ['label' => 'Documents eleves', 'table' => 'student_documents'],
            ],
            'Pedagogie' => [
                ['label' => 'Classes', 'table' => 'classes', 'route' => 'app_classes'],
                ['label' => 'Matieres', 'table' => 'subjects'],
                ['label' => 'Cours', 'table' => 'courses'],
                ['label' => 'Evaluations', 'table' => 'evaluations', 'route' => 'app_evaluations'],
                ['label' => 'Notes', 'table' => 'grades', 'route' => 'app_evaluations'],
                ['label' => 'Bulletins', 'table' => 'report_cards', 'route' => 'app_report_cards'],
            ],
            'Finance' => [
                ['label' => 'Tarifs', 'table' => 'tariffs', 'route' => 'app_finance'],
                ['label' => 'Factures', 'table' => 'invoices', 'route' => 'app_finance'],
                ['label' => 'Paiements', 'table' => 'payments', 'route' => 'app_finance'],
                ['label' => 'Recus', 'table' => 'receipts', 'route' => 'app_finance'],
            ],
            'Vie scolaire' => [
                ['label' => 'Presences', 'table' => 'attendance', 'route' => 'app_attendance'],
                ['label' => 'Incidents', 'table' => 'incidents'],
                ['label' => 'Notifications', 'table' => 'notifications'],
                ['label' => 'Livres', 'table' => 'library_books'],
                ['label' => 'Prets', 'table' => 'loans'],
                ['label' => 'Inventaire', 'table' => 'inventory_items'],
            ],
        ];

        foreach ($moduleGroups as $group => $modules) {
            foreach ($modules as $index => $module) {
                $moduleGroups[$group][$index]['count'] = (int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $module['table']);
            }
        }

        return $this->render('dashboard/index.html.twig', [
            'students' => $students,
            'primaryCount' => $primaryCount,
            'secondaryCount' => count($students) - $primaryCount,
            'moduleGroups' => $moduleGroups,
        ]);
    }
}
