<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ReportCardManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportCardController extends AbstractController
{
    public function __construct(
        private readonly ReportCardManager $reportCards
    ) {
    }

    #[Route('/symfony/report-cards', name: 'app_report_cards', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $classId = (int) $request->request->get('class_id', $request->query->get('class_id', 0));
        $periodId = (int) $request->request->get('period_id', $request->query->get('period_id', 0));

        if ($request->isMethod('POST')) {
            $publish = $request->request->get('action') === 'publish';
            $token = $publish ? 'report_cards_publish' : 'report_cards_generate';

            if (!$this->isCsrfTokenValid($token, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expiree, merci de recommencer.');
            } else {
                try {
                    if ($publish) {
                        $count = $this->reportCards->publishForClass($classId, $periodId);
                        $this->addFlash('success', $count . ' bulletin(s) publie(s).');
                    } else {
                        $count = $this->reportCards->generateForClass($classId, $periodId);
                        $this->addFlash('success', $count . ' bulletin(s) calcule(s).');
                    }
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                } catch (\Throwable) {
                    $this->addFlash('error', 'Impossible de traiter les bulletins.');
                }
            }

            return $this->redirectToRoute('app_report_cards', ['class_id' => $classId, 'period_id' => $periodId]);
        }

        $cards = $this->reportCards->search($classId, $periodId);

        return $this->render('report_cards/index.html.twig', [
            'cards' => $cards,
            'classes' => $this->reportCards->classes(),
            'periods' => $this->reportCards->periods(),
            'classId' => $classId,
            'periodId' => $periodId,
            'total' => count($cards),
        ]);
    }

    #[Route('/symfony/report-cards/{id}', name: 'app_report_card_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): Response
    {
        try {
            $card = $this->reportCards->find($id);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        }

        $studentId = (int) $card['student_id'];
        $classId = $this->reportCards->classIdOf($studentId);

        return $this->render('report_cards/show.html.twig', [
            'card' => $card,
            'subjects' => $classId > 0
                ? $this->reportCards->subjectAverages($studentId, $classId, (int) $card['period_id'])
                : [],
            'absences' => $this->reportCards->absencesBetween(
                $studentId,
                (string) $card['start_date'],
                (string) $card['end_date']
            ),
        ]);
    }
}
