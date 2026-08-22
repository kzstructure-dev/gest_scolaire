<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Frais scolaires: tarifs, factures, encaissements et recus.
 */
final class FinanceManager
{
    public const SCHOOL_YEAR_ID = 1;

    /**
     * @var list<string>
     */
    public const METHODS = ['Especes', 'Cheque', 'Virement', 'Mobile money'];

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException si le tarif est invalide
     */
    public function createTariff(array $input): int
    {
        $label = trim((string) ($input['label'] ?? ''));
        $amount = (float) ($input['amount'] ?? 0);
        $dueDate = trim((string) ($input['due_date'] ?? ''));
        $levelId = (int) ($input['level_id'] ?? 0);

        if ($label === '') {
            throw new \InvalidArgumentException('Le libelle du tarif est obligatoire.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Le montant doit etre superieur a zero.');
        }

        if ($dueDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate) !== 1) {
            throw new \InvalidArgumentException('La date d\'echeance est invalide.');
        }

        if ($levelId > 0 && $this->connection->fetchOne('SELECT id FROM levels WHERE id = ?', [$levelId]) === false) {
            throw new \InvalidArgumentException('Le niveau selectionne n\'existe pas.');
        }

        $duplicate = $this->connection->fetchOne(
            'SELECT id FROM tariffs WHERE school_year_id = ? AND label = ? AND active = 1',
            [self::SCHOOL_YEAR_ID, $label]
        );

        if ($duplicate !== false) {
            throw new \InvalidArgumentException('Un tarif actif porte deja ce libelle.');
        }

        $this->connection->insert('tariffs', [
            'school_year_id' => self::SCHOOL_YEAR_ID,
            'level_id' => $levelId > 0 ? $levelId : null,
            'label' => $label,
            'amount' => $amount,
            'due_date' => $dueDate !== '' ? $dueDate : null,
            'active' => 1,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Facture un tarif a un eleve inscrit, sans doublon.
     *
     * @throws \InvalidArgumentException si l'eleve ou le tarif est invalide
     */
    public function createInvoice(int $studentId, int $tariffId): string
    {
        $tariff = $this->connection->fetchAssociative(
            'SELECT id, amount, due_date FROM tariffs WHERE id = ? AND active = 1',
            [$tariffId]
        );

        if ($tariff === false) {
            throw new \InvalidArgumentException('Selectionnez un tarif actif.');
        }

        $registered = $this->connection->fetchOne(
            'SELECT id FROM registrations
             WHERE student_id = ? AND school_year_id = ? AND status = \'Validee\'',
            [$studentId, self::SCHOOL_YEAR_ID]
        );

        if ($registered === false) {
            throw new \InvalidArgumentException('Cet eleve n\'a pas d\'inscription validee cette annee.');
        }

        $existing = $this->connection->fetchOne(
            'SELECT invoice_number FROM invoices WHERE student_id = ? AND tariff_id = ?',
            [$studentId, $tariffId]
        );

        if ($existing !== false) {
            throw new \InvalidArgumentException('Ce tarif est deja facture a cet eleve (' . $existing . ').');
        }

        $nextId = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) + 1 FROM invoices');
        $number = 'FAC-' . str_pad((string) $nextId, 5, '0', STR_PAD_LEFT);

        $this->connection->insert('invoices', [
            'student_id' => $studentId,
            'tariff_id' => $tariffId,
            'invoice_number' => $number,
            'amount' => $tariff['amount'],
            'due_date' => $tariff['due_date'],
            'status' => 'A payer',
        ]);

        return $number;
    }

    /**
     * Encaisse un paiement partiel ou total et emet le recu.
     *
     * @param array<string, mixed> $input
     *
     * @throws \InvalidArgumentException si le paiement est invalide
     */
    public function registerPayment(array $input, ?User $author): string
    {
        $invoiceId = (int) ($input['invoice_id'] ?? 0);
        $amount = round((float) ($input['amount'] ?? 0), 2);
        $method = (string) ($input['method'] ?? 'Especes');
        $date = (string) ($input['payment_date'] ?? date('Y-m-d'));

        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException('Mode de paiement invalide.');
        }

        if ($amount <= 0) {
            throw new \InvalidArgumentException('Le montant paye doit etre superieur a zero.');
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new \InvalidArgumentException('La date de paiement est invalide.');
        }

        $invoice = $this->connection->fetchAssociative('SELECT id, amount FROM invoices WHERE id = ?', [$invoiceId]);

        if ($invoice === false) {
            throw new \InvalidArgumentException('Facture introuvable.');
        }

        $remaining = $this->remainingFor($invoiceId, (float) $invoice['amount']);

        if ($remaining <= 0) {
            throw new \InvalidArgumentException('Cette facture est deja soldee.');
        }

        if ($amount - $remaining > 0.009) {
            throw new \InvalidArgumentException(
                'Le montant depasse le reste a payer (' . number_format($remaining, 0, ',', ' ') . ' F CFA).'
            );
        }

        $this->connection->beginTransaction();

        try {
            $this->connection->insert('payments', [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'payment_date' => $date,
                'method' => $method,
                'status' => 'Valide',
            ]);

            $paymentId = (int) $this->connection->lastInsertId();
            $receiptNumber = 'REC-' . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);

            $this->connection->insert('receipts', [
                'payment_id' => $paymentId,
                'receipt_number' => $receiptNumber,
                'issued_at' => date('Y-m-d H:i:s'),
                'issued_by' => $author?->getId(),
            ]);

            $this->refreshInvoiceStatus($invoiceId, (float) $invoice['amount']);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }

        return $receiptNumber;
    }

    /**
     * Annule un encaissement en conservant sa trace et son motif.
     *
     * @throws \InvalidArgumentException si le paiement ou le motif est invalide
     */
    public function cancelPayment(int $paymentId, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('Le motif d\'annulation est obligatoire.');
        }

        $payment = $this->connection->fetchAssociative(
            'SELECT id, invoice_id, status FROM payments WHERE id = ?',
            [$paymentId]
        );

        if ($payment === false) {
            throw new \InvalidArgumentException('Paiement introuvable.');
        }

        if ($payment['status'] !== 'Valide') {
            throw new \InvalidArgumentException('Ce paiement est deja annule.');
        }

        $invoiceId = (int) $payment['invoice_id'];
        $invoiceAmount = (float) $this->connection->fetchOne('SELECT amount FROM invoices WHERE id = ?', [$invoiceId]);

        $this->connection->beginTransaction();

        try {
            $this->connection->update(
                'payments',
                ['status' => 'Annule', 'cancelled_reason' => $reason],
                ['id' => $paymentId]
            );
            $this->refreshInvoiceStatus($invoiceId, $invoiceAmount);
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    public function remainingFor(int $invoiceId, float $invoiceAmount): float
    {
        $paid = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = \'Valide\'',
            [$invoiceId]
        );

        return round($invoiceAmount - $paid, 2);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoices(int $studentId = 0, string $status = ''): array
    {
        $sql = <<<SQL
            SELECT i.id, i.invoice_number, i.amount, i.due_date, i.status,
                   s.first_name, s.last_name, s.class_name, t.label AS tariff_label,
                   COALESCE((
                       SELECT SUM(p.amount) FROM payments p
                       WHERE p.invoice_id = i.id AND p.status = 'Valide'
                   ), 0) AS paid
            FROM invoices i
            INNER JOIN students s ON s.id = i.student_id
            INNER JOIN tariffs t ON t.id = i.tariff_id
            WHERE (:student_id = 0 OR i.student_id = :student_id)
              AND (:status = '' OR i.status = :status)
            ORDER BY i.id DESC
        SQL;

        $invoices = $this->connection->fetchAllAssociative($sql, [
            'student_id' => $studentId,
            'status' => $status,
        ]);

        foreach ($invoices as $index => $invoice) {
            $invoices[$index]['remaining'] = round((float) $invoice['amount'] - (float) $invoice['paid'], 2);
        }

        return $invoices;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payments(int $limit = 30): array
    {
        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT p.id, p.amount, p.payment_date, p.method, p.status, p.cancelled_reason,
                   i.invoice_number, s.first_name, s.last_name, r.receipt_number
            FROM payments p
            INNER JOIN invoices i ON i.id = p.invoice_id
            INNER JOIN students s ON s.id = i.student_id
            LEFT JOIN receipts r ON r.payment_id = p.id
            ORDER BY p.id DESC
            LIMIT :limit
            SQL,
            ['limit' => $limit],
            ['limit' => ParameterType::INTEGER]
        );
    }

    /**
     * @return array{receipt_number: string, issued_at: string, ...<string, mixed>}
     *
     * @throws \InvalidArgumentException si le recu n'existe pas
     */
    public function receipt(int $paymentId): array
    {
        $receipt = $this->connection->fetchAssociative(
            <<<SQL
            SELECT r.receipt_number, r.issued_at, p.amount, p.payment_date, p.method, p.status,
                   i.invoice_number, i.amount AS invoice_amount, t.label AS tariff_label,
                   s.first_name, s.last_name, s.class_name
            FROM receipts r
            INNER JOIN payments p ON p.id = r.payment_id
            INNER JOIN invoices i ON i.id = p.invoice_id
            INNER JOIN tariffs t ON t.id = i.tariff_id
            INNER JOIN students s ON s.id = i.student_id
            WHERE p.id = ?
            SQL,
            [$paymentId]
        );

        if ($receipt === false) {
            throw new \InvalidArgumentException('Recu introuvable.');
        }

        return $receipt;
    }

    /**
     * Recapitulatif des impayes par eleve.
     *
     * @return list<array<string, mixed>>
     */
    public function outstandingByStudent(): array
    {
        return $this->connection->fetchAllAssociative(
            <<<SQL
            SELECT s.id, s.first_name, s.last_name, s.class_name,
                   SUM(i.amount) AS invoiced,
                   COALESCE(SUM((
                       SELECT SUM(p.amount) FROM payments p
                       WHERE p.invoice_id = i.id AND p.status = 'Valide'
                   )), 0) AS paid
            FROM invoices i
            INNER JOIN students s ON s.id = i.student_id
            GROUP BY s.id, s.first_name, s.last_name, s.class_name
            HAVING invoiced - paid > 0
            ORDER BY invoiced - paid DESC
            SQL
        );
    }

    /**
     * @return array{collected: float, invoiced: float, outstanding: float, invoiceCount: int}
     */
    public function statistics(): array
    {
        $collected = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = \'Valide\''
        );
        $invoiced = (float) $this->connection->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM invoices');

        return [
            'collected' => $collected,
            'invoiced' => $invoiced,
            'outstanding' => max(0, round($invoiced - $collected, 2)),
            'invoiceCount' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM invoices'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tariffs(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT t.id, t.label, t.amount, t.due_date, l.name AS level_name
             FROM tariffs t
             LEFT JOIN levels l ON l.id = t.level_id
             WHERE t.active = 1
             ORDER BY t.label'
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function students(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT s.id, s.first_name, s.last_name, s.class_name
             FROM students s
             INNER JOIN registrations r
                 ON r.student_id = s.id AND r.school_year_id = ? AND r.status = \'Validee\'
             ORDER BY s.last_name, s.first_name',
            [self::SCHOOL_YEAR_ID]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function levels(): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, name FROM levels ORDER BY sort_order');
    }

    public function ensureDefaultTariffs(): void
    {
        if ((int) $this->connection->fetchOne('SELECT COUNT(*) FROM tariffs') > 0) {
            return;
        }

        foreach ([
            ['Droits d\'inscription', 25000, '2025-09-30'],
            ['Scolarite trimestre 1', 75000, '2025-10-15'],
            ['Cantine trimestre 1', 15000, '2025-10-15'],
        ] as [$label, $amount, $dueDate]) {
            $this->connection->insert('tariffs', [
                'school_year_id' => self::SCHOOL_YEAR_ID,
                'label' => $label,
                'amount' => $amount,
                'due_date' => $dueDate,
                'active' => 1,
            ]);
        }
    }

    private function refreshInvoiceStatus(int $invoiceId, float $invoiceAmount): void
    {
        $paid = (float) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = \'Valide\'',
            [$invoiceId]
        );

        $status = match (true) {
            $paid + 0.009 >= $invoiceAmount => 'Payee',
            $paid > 0 => 'Partiel',
            default => 'A payer',
        };

        $this->connection->update('invoices', ['status' => $status], ['id' => $invoiceId]);
    }
}
