<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\EvaluationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EvaluationController extends AbstractController
{
    public function __construct(
        private readonly EvaluationManager $evaluations
    ) {
    }

    #[Route('/symfony/evaluations', name: 'app_evaluations', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $classId = (int) $request->query->get('class_id', 0);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('evaluation_create', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expiree, merci de recommencer.');

                return $this->redirectToRoute('app_evaluations');
            }

            try {
                $evaluationId = $this->evaluations->create($request->request->all());

                return $this->redirectToRoute('app_evaluation_grades', ['id' => $evaluationId]);
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable) {
                $this->addFlash('error', 'Impossible de creer cette evaluation.');
            }

            return $this->redirectToRoute('app_evaluations', ['class_id' => (int) $request->request->get('class_id', 0)]);
        }

        $evaluations = $this->evaluations->listForClass($classId);

        return $this->render('evaluations/index.html.twig', [
            'evaluations' => $evaluations,
            'classes' => $this->evaluations->classes(),
            'subjects' => $this->evaluations->subjects(),
            'periods' => $this->evaluations->periods(),
            'types' => EvaluationManager::TYPES,
            'classId' => $classId,
            'total' => count($evaluations),
        ]);
    }

    #[Route('/symfony/evaluations/{id}', name: 'app_evaluation_grades', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function grades(int $id, Request $request): Response
    {
        try {
            $evaluation = $this->evaluations->find($id);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('evaluation_grades_' . $id, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expiree, merci de recommencer.');
            } else {
                try {
                    $saved = $this->evaluations->saveGrades(
                        $id,
                        $request->request->all('value'),
                        $request->request->all('appreciation'),
                        $this->currentUser()
                    );
                    $this->addFlash('success', sprintf('%d note(s) enregistree(s).', $saved));
                } catch (\InvalidArgumentException $exception) {
                    $this->addFlash('error', $exception->getMessage());
                } catch (\Throwable) {
                    $this->addFlash('error', 'Impossible d\'enregistrer les notes.');
                }
            }

            return $this->redirectToRoute('app_evaluation_grades', ['id' => $id]);
        }

        $sheet = $this->evaluations->gradeSheet($id, (int) $evaluation['class_id']);

        return $this->render('evaluations/grades.html.twig', array_merge(
            $this->evaluations->statistics($sheet, (float) $evaluation['scale']),
            [
                'evaluation' => $evaluation,
                'students' => $sheet,
            ]
        ));
    }

    #[Route('/symfony/evaluations/{id}/delete', name: 'app_evaluation_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('evaluation_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expiree, merci de recommencer.');
        } else {
            try {
                $this->evaluations->delete($id);
                $this->addFlash('success', 'Evaluation supprimee ainsi que ses notes.');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable) {
                $this->addFlash('error', 'Impossible de supprimer cette evaluation.');
            }
        }

        return $this->redirectToRoute('app_evaluations', ['class_id' => (int) $request->request->get('class_id', 0)]);
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
