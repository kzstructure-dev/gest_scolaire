<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\TeacherManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class TeacherController extends AbstractController
{
    public function __construct(
        private readonly TeacherManager $teachers
    ) {
    }

    #[Route('/symfony/teachers', name: 'app_teachers', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handle($request);
        }

        $teacherId = (int) $request->query->get('teacher_id', 0);

        return $this->render('teachers/index.html.twig', [
            'teachers' => $this->teachers->teachers(),
            'assignments' => $this->teachers->assignments($teacherId),
            'classes' => $this->teachers->classes(),
            'subjects' => $this->teachers->subjects(),
            'teacherId' => $teacherId,
            'maxWeeklyHours' => TeacherManager::MAX_WEEKLY_HOURS,
        ]);
    }

    #[Route('/symfony/timetable', name: 'app_timetable', methods: ['GET', 'POST'])]
    public function timetable(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handleTimetable($request);
        }

        $classId = (int) $request->query->get('class_id', 0);

        return $this->render('teachers/timetable.html.twig', [
            'schedule' => $this->teachers->weeklySchedule($classId),
            'weekdays' => TeacherManager::WEEKDAYS,
            'classes' => $this->teachers->classes(),
            'subjects' => $this->teachers->subjects(),
            'rooms' => $this->teachers->rooms(),
            'assignments' => $this->teachers->assignments(0, $classId),
            'teachers' => $this->teachers->teachers(),
            'classId' => $classId,
        ]);
    }

    private function handle(Request $request): Response
    {
        $action = (string) $request->request->get('action');

        if (!$this->isCsrfTokenValid('teacher_' . $action, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expiree, merci de recommencer.');

            return $this->redirectToRoute('app_teachers');
        }

        try {
            $this->addFlash('success', match ($action) {
                'create' => $this->createTeacher($request),
                'assign' => $this->assign($request),
                'unassign' => $this->unassign($request),
                default => throw new \InvalidArgumentException('Action inconnue.'),
            });
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible d\'enregistrer cette operation.');
        }

        return $this->redirectToRoute('app_teachers');
    }

    private function handleTimetable(Request $request): Response
    {
        $action = (string) $request->request->get('action');
        $classId = (int) $request->request->get('class_id', 0);

        if (!$this->isCsrfTokenValid('timetable_' . $action, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expiree, merci de recommencer.');

            return $this->redirectToRoute('app_timetable', ['class_id' => $classId]);
        }

        try {
            if ($action === 'schedule') {
                $this->teachers->scheduleCourse($request->request->all());
                $this->addFlash('success', 'Creneau ajoute a l\'emploi du temps.');
            } elseif ($action === 'remove') {
                $this->teachers->removeCourse((int) $request->request->get('course_id'));
                $this->addFlash('success', 'Creneau supprime.');
            } else {
                throw new \InvalidArgumentException('Action inconnue.');
            }
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible d\'enregistrer ce creneau.');
        }

        return $this->redirectToRoute('app_timetable', ['class_id' => $classId]);
    }

    private function createTeacher(Request $request): string
    {
        $this->teachers->createTeacher($request->request->all());

        return 'Enseignant enregistre.';
    }

    private function assign(Request $request): string
    {
        $this->teachers->assign(
            (int) $request->request->get('teacher_id'),
            (int) $request->request->get('class_id'),
            (int) $request->request->get('subject_id'),
            (float) $request->request->get('weekly_hours')
        );

        return 'Affectation enregistree.';
    }

    private function unassign(Request $request): string
    {
        $this->teachers->removeAssignment((int) $request->request->get('assignment_id'));

        return 'Affectation retiree.';
    }
}
