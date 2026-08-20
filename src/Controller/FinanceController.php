<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FinanceController extends AbstractController
{
    private const METHODS = ['Especes', 'Cheque', 'Virement', 'Mobile money'];

    #[Route('/symfony/finance', name: 'app_finance', methods: ['GET', 'POST'])]
    public function index(Request $request, Connection $connection): Response
    {
        $this->ensureDefaultTariffs($connection);
        $message = null;
        $error = null;

        if ($request->isMethod('POST')) {
            $action = (string) $request->request->get('action');
            try {
                $message = match ($action) {
                    'tariff' => $this->createTariff($request, $connection),
                    'invoice' => $this->createInvoice($request, $connection),
                    'payment' => $this->createPayment($request, $connection),
                    default => throw new \InvalidArgumentException('Action inconnue.'),
                };
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            } catch (\Throwable) {
                $error = 'Impossible d\'enregistrer cette operation.';
            }
        }

        $invoices = $connection->fetchAllAssociative(<<<SQL
            SELECT i.id, i.invoice_number, i.amount, i.due_date, i.status,
                   s.first_name, s.last_name, s.class_name, t.label AS tariff_label,
                   COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.invoice_id = i.id AND p.status = 'Valide'), 0) AS paid
            FROM invoices i
            INNER JOIN students s ON s.id = i.student_id
            INNER JOIN tariffs t ON t.id = i.tariff_id
            ORDER BY i.id DESC
        SQL);
        foreach ($invoices as &$invoice) {
            $invoice['remaining'] = round((float) $invoice['amount'] - (float) $invoice['paid'], 2);
        }
        unset($invoice);

        $payments = $connection->fetchAllAssociative(<<<SQL
            SELECT p.id, p.amount, p.payment_date, p.method, p.status, i.invoice_number,
                   s.first_name, s.last_name, r.receipt_number
            FROM payments p
            INNER JOIN invoices i ON i.id = p.invoice_id
            INNER JOIN students s ON s.id = i.student_id
            LEFT JOIN receipts r ON r.payment_id = p.id
            ORDER BY p.id DESC
            LIMIT 30
        SQL);

        $collected = (float) $connection->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'Valide'");
        $invoiced = (float) $connection->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM invoices');

        return $this->render('finance/index.html.twig', [
            'tariffs' => $connection->fetchAllAssociative('SELECT t.id, t.label, t.amount, t.due_date, l.name AS level_name FROM tariffs t LEFT JOIN levels l ON l.id = t.level_id WHERE t.active = 1 ORDER BY t.label'),
            'students' => $connection->fetchAllAssociative("SELECT s.id, s.first_name, s.last_name, s.class_name FROM students s INNER JOIN registrations r ON r.student_id = s.id AND r.school_year_id = 1 AND r.status = 'Validee' ORDER BY s.last_name, s.first_name"),
            'levels' => $connection->fetchAllAssociative('SELECT id, name FROM levels ORDER BY sort_order'),
            'openInvoices' => array_values(array_filter($invoices, static fn (array $invoice): bool => $invoice['remaining'] > 0)),
            'invoices' => $invoices,
            'payments' => $payments,
            'methods' => self::METHODS,
            'message' => $message,
            'error' => $error,
            'collected' => $collected,
            'outstanding' => max(0, $invoiced - $collected),
            'invoiceCount' => count($invoices),
        ]);
    }

    #[Route('/symfony/finance/receipts/{id}', name: 'app_finance_receipt', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function receipt(int $id, Connection $connection): Response
    {
        $receipt = $connection->fetchAssociative(<<<SQL
            SELECT r.receipt_number, r.issued_at, p.amount, p.payment_date, p.method,
                   i.invoice_number, i.amount AS invoice_amount, t.label AS tariff_label,
                   s.first_name, s.last_name, s.class_name
            FROM receipts r
            INNER JOIN payments p ON p.id = r.payment_id
            INNER JOIN invoices i ON i.id = p.invoice_id
            INNER JOIN tariffs t ON t.id = i.tariff_id
            INNER JOIN students s ON s.id = i.student_id
            WHERE p.id = ?
        SQL, [$id]);

        if ($receipt === false) {
            throw $this->createNotFoundException('Recu introuvable.');
        }

        return $this->render('finance/receipt.html.twig', ['receipt' => $receipt]);
    }

    private function createTariff(Request $request, Connection $connection): string
    {
        $label = trim((string) $request->request->get('label'));
        $amount = (float) $request->request->get('amount');
        $dueDate = trim((string) $request->request->get('due_date'));
        $levelId = (int) $request->request->get('level_id');
        if ($label === '' || $amount <= 0) {
            throw new \InvalidArgumentException('Le libelle et un montant superieur a zero sont obligatoires.');
        }
        $connection->insert('tariffs', [
            'school_year_id' => 1,
            'level_id' => $levelId > 0 ? $levelId : null,
            'label' => $label,
            'amount' => $amount,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'active' => 1,
        ]);

        return 'Tarif enregistre.';
    }

    private function createInvoice(Request $request, Connection $connection): string
    {
        $studentId = (int) $request->request->get('student_id');
        $tariffId = (int) $request->request->get('tariff_id');
        $tariff = $connection->fetchAssociative('SELECT id, amount, due_date FROM tariffs WHERE id = ? AND active = 1', [$tariffId]);
        if ($studentId < 1 || $tariff === false) {
            throw new \InvalidArgumentException('Selectionnez un eleve et un tarif valides.');
        }
        $nextId = (int) $connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices');
        $connection->insert('invoices', [
            'student_id' => $studentId,
            'tariff_id' => $tariffId,
            'invoice_number' => 'FAC-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT),
            'amount' => $tariff['amount'],
            'due_date' => $tariff['due_date'],
            'status' => 'A payer',
        ]);

        return 'Facture generee.';
    }

    private function createPayment(Request $request, Connection $connection): string
    {
        $invoiceId = (int) $request->request->get('invoice_id');
        $amount = (float) $request->request->get('amount');
        $method = (string) $request->request->get('method', 'Especes');
        $date = (string) $request->request->get('payment_date', date('Y-m-d'));
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Mode de paiement invalide.');
        }
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Le montant paye doit etre superieur a zero.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('La date de paiement est invalide.');
        }

        $invoice = $connection->fetchAssociative('SELECT id, amount, status FROM invoices WHERE id = ?', [$invoiceId]);
        if ($invoice === false) {
            throw new \InvalidArgumentException('Facture introuvable.');
        }
        $paid = (float) $connection->fetchOne("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = 'Valide'", [$invoiceId]);
        $remaining = round((float) $invoice['amount'] - $paid, 2);
        if ($amount - $remaining > 0.009) {
            throw new \InvalidArgumentException('Le montant depasse le reste a payer (' . number_format($remaining, 0, ',', ' ') . ' F CFA).');
        }

        $connection->beginTransaction();
        try {
            $connection->insert('payments', [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'payment_date' => $date,
                'method' => $method,
                'status' => 'Valide',
            ]);
            $paymentId = (int) $connection->lastInsertId();
            $receiptNumber = 'REC-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
            $connection->insert('receipts', [
                'payment_id' => $paymentId,
                'receipt_number' => $receiptNumber,
                'issued_at' => date('Y-m-d H:i:s'),
            ]);
            $newPaid = $paid + $amount;
            $status = $newPaid + 0.009 >= (float) $invoice['amount'] ? 'Payee' : 'Partiel';
            $connection->update('invoices', ['status' => $status], ['id' => $invoiceId]);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();
            throw $exception;
        }

        return 'Paiement enregistre. Recu ' . $receiptNumber . '.';
    }

    private function ensureDefaultTariffs(Connection $connection): void
    {
        if ((int) $connection->fetchOne('SELECT COUNT(*) FROM tariffs') > 0) {
            return;
        }
        foreach ([
            ['Droits d\'inscription', 25000, '2025-09-30'],
            ['Scolarite trimestre 1', 75000, '2025-10-15'],
            ['Cantine trimestre 1', 15000, '2025-10-15'],
        ] as [$label, $amount, $dueDate]) {
            $connection->insert('tariffs', [
                'school_year_id' => 1,
                'label' => $label,
                'amount' => $amount,
                'due_date' => $dueDate,
                'active' => 1,
            ]);
        }
    }
}
