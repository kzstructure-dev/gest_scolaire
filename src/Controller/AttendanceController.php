<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\AttendanceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AttendanceController extends AbstractController
{
    public function __construct(
        private readonly AttendanceManager $attendance
    ) {
    }

    #[Route('/symfony/attendance', name: 'app_attendance', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $date = AttendanceManager::normalizeDate((string) $request->request->get('date', $request->query->get('date', date('Y-m-d'))));
        $classId = (int) $request->request->get('class_id', $request->query->get('class_id', 0));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('attendance_sheet', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expiree, merci de recommencer.');
            } else {
                try {
                    $saved = $this->attendance->saveSheet(
                        $classId,
                        $date,
                        $request->request->all('status'),
                        $request->request->all('minutes_late'),
                        $request->request->all('reason'),
                        $request->request->all('justified'),
                        $this->currentUser()
                    );
                    $this->addFlash('success', sprintf('Feuille du %s enregistree pour %d eleve(s).', $date, $saved));
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                } catch (\Throwable) {
                    $this->addFlash('error', 'Impossible d\'enregistrer les presences.');
                }
            }

            return $this->redirectToRoute('app_attendance', ['date' => $date, 'class_id' => $classId]);
        }

        $sheet = $this->attendance->sheet($classId, $date);

        return $this->render('attendance/index.html.twig', [
            'classes' => $this->attendance->classes(),
            'students' => $sheet,
            'recap' => $this->attendance->monthlyRecap($classId, $date),
            'date' => $date,
            'classId' => $classId,
            'counts' => $this->attendance->counts($sheet),
            'statuses' => AttendanceManager::STATUSES,
        ]);
    }

    #[Route('/symfony/attendance/{id}/justify', name: 'app_attendance_justify', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function justify(int $id, Request $request): Response
    {
        $date = AttendanceManager::normalizeDate((string) $request->request->get('date', date('Y-m-d')));
        $classId = (int) $request->request->get('class_id', 0);

        if (!$this->isCsrfTokenValid('attendance_justify_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expiree, merci de recommencer.');
        } else {
            try {
                $this->attendance->justify($id, (string) $request->request->get('reason', ''), $this->currentUser());
                $this->addFlash('success', 'Absence justifiee.');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable) {
                $this->addFlash('error', 'Impossible de justifier cette absence.');
            }
        }

        return $this->redirectToRoute('app_attendance', ['date' => $date, 'class_id' => $classId]);
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
