<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\StudentManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StudentController extends AbstractController
{
    public function __construct(
        private readonly StudentManager $students
    ) {
    }

    #[Route('/symfony/students', name: 'app_students', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('student_create', (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expiree, merci de recommencer.');

                return $this->redirectToRoute('app_students');
            }

            try {
                $this->students->create($request->request->all(), $this->currentUser());
                $this->addFlash('success', 'Inscription enregistree avec succes.');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            } catch (\Throwable) {
                $this->addFlash('error', 'Impossible d\'enregistrer cette inscription.');
            }

            return $this->redirectToRoute('app_students');
        }

        $search = trim((string) $request->query->get('search', ''));
        $cycle = trim((string) $request->query->get('cycle', ''));

        return $this->render('students/index.html.twig', [
            'students' => $this->students->search($search, $cycle),
            'classes' => $this->students->classes(),
            'search' => $search,
            'cycle' => $cycle,
        ]);
    }

    #[Route('/symfony/students/{id}/edit', name: 'app_students_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request): Response
    {
        try {
            $student = $this->students->find($id);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('student_edit_' . $id, (string) $request->request->get('_token'))) {
                $this->addFlash('error', 'Session expiree, merci de recommencer.');

                return $this->redirectToRoute('app_students_edit', ['id' => $id]);
            }

            try {
                $this->students->update($id, $request->request->all(), $this->currentUser());
                $this->addFlash('success', 'Fiche eleve mise a jour.');

                return $this->redirectToRoute('app_students');
            } catch (\InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
                $student = array_merge($student, $request->request->all());
            } catch (\Throwable) {
                $this->addFlash('error', 'Impossible de mettre a jour cette fiche.');
            }
        }

        return $this->render('students/edit.html.twig', [
            'student' => $student,
            'classes' => $this->students->classes(),
            'statuses' => StudentManager::STATUSES,
        ]);
    }

    #[Route('/symfony/students/{id}/delete', name: 'app_students_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('student_delete_' . $id, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expiree, merci de recommencer.');

            return $this->redirectToRoute('app_students');
        }

        try {
            $this->students->delete($id, $this->currentUser());
            $this->addFlash('success', 'Eleve supprime ainsi que son inscription.');
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible de supprimer cet eleve.');
        }

        return $this->redirectToRoute('app_students');
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
