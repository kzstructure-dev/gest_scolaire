<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\FinanceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FinanceController extends AbstractController
{
    public function __construct(
        private readonly FinanceManager $finance
    ) {
    }

    #[Route('/symfony/finance', name: 'app_finance', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $this->finance->ensureDefaultTariffs();

        if ($request->isMethod('POST')) {
            return $this->handle($request);
        }

        $studentId = (int) $request->query->get('student_id', 0);
        $status = (string) $request->query->get('status', '');
        $invoices = $this->finance->invoices($studentId, $status);

        return $this->render('finance/index.html.twig', array_merge($this->finance->statistics(), [
            'tariffs' => $this->finance->tariffs(),
            'students' => $this->finance->students(),
            'levels' => $this->finance->levels(),
            'invoices' => $invoices,
            'openInvoices' => array_values(array_filter(
                $this->finance->invoices(),
                static fn (array $invoice): bool => $invoice['remaining'] > 0
            )),
            'payments' => $this->finance->payments(),
            'outstandingStudents' => $this->finance->outstandingByStudent(),
            'methods' => FinanceManager::METHODS,
            'studentId' => $studentId,
            'status' => $status,
        ]));
    }

    #[Route('/symfony/finance/receipts/{id}', name: 'app_finance_receipt', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function receipt(int $id): Response
    {
        try {
            $receipt = $this->finance->receipt($id);
        } catch (\InvalidArgumentException $exception) {
            throw $this->createNotFoundException($exception->getMessage());
        }

        return $this->render('finance/receipt.html.twig', ['receipt' => $receipt]);
    }

    private function handle(Request $request): Response
    {
        $action = (string) $request->request->get('action');

        if (!$this->isCsrfTokenValid('finance_' . $action, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Session expiree, merci de recommencer.');

            return $this->redirectToRoute('app_finance');
        }

        try {
            $this->addFlash('success', match ($action) {
                'tariff' => $this->createTariff($request),
                'invoice' => $this->createInvoice($request),
                'payment' => $this->registerPayment($request),
                'cancel' => $this->cancelPayment($request),
                default => throw new \InvalidArgumentException('Action inconnue.'),
            });
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible d\'enregistrer cette operation.');
        }

        return $this->redirectToRoute('app_finance');
    }

    private function createTariff(Request $request): string
    {
        $this->finance->createTariff($request->request->all());

        return 'Tarif enregistre.';
    }

    private function createInvoice(Request $request): string
    {
        $number = $this->finance->createInvoice(
            (int) $request->request->get('student_id'),
            (int) $request->request->get('tariff_id')
        );

        return 'Facture ' . $number . ' generee.';
    }

    private function registerPayment(Request $request): string
    {
        $receipt = $this->finance->registerPayment($request->request->all(), $this->currentUser());

        return 'Paiement enregistre. Recu ' . $receipt . '.';
    }

    private function cancelPayment(Request $request): string
    {
        $this->finance->cancelPayment(
            (int) $request->request->get('payment_id'),
            (string) $request->request->get('reason', '')
        );

        return 'Paiement annule.';
    }

    private function currentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
