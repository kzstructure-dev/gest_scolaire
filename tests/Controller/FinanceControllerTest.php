<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use App\Service\FinanceManager;
use App\Tests\TestUsers;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class FinanceControllerTest extends WebTestCase
{
    public function testTariffIsCreatedWithLabelAmountAndDueDate(): void
    {
        $client = $this->login();

        $this->post($client, 'tariff', [
            'label' => 'Sortie pedagogique',
            'amount' => '12000',
            'due_date' => '2026-02-10',
            'level_id' => '0',
        ]);

        self::assertSelectorTextContains('.notice.success', 'Tarif enregistre.');
        $tariff = $this->connection()->fetchAssociative('SELECT * FROM tariffs WHERE label = ?', ['Sortie pedagogique']);
        self::assertIsArray($tariff);
        self::assertSame(12000.0, (float) $tariff['amount']);
        self::assertSame('2026-02-10', $tariff['due_date']);
    }

    public function testTariffWithoutPositiveAmountIsRejected(): void
    {
        $client = $this->login();

        $this->post($client, 'tariff', ['label' => 'Tarif nul', 'amount' => '0']);

        self::assertSelectorTextContains('.notice.error', 'Le montant doit etre superieur a zero.');
        self::assertFalse($this->connection()->fetchOne('SELECT id FROM tariffs WHERE label = ?', ['Tarif nul']));
    }

    public function testDuplicateActiveTariffLabelIsRejected(): void
    {
        $client = $this->login();
        $this->post($client, 'tariff', ['label' => 'Cantine mars', 'amount' => '9000']);

        $this->post($client, 'tariff', ['label' => 'Cantine mars', 'amount' => '9000']);

        self::assertSelectorTextContains('.notice.error', 'Un tarif actif porte deja ce libelle.');
        self::assertSame(
            1,
            (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM tariffs WHERE label = ?', ['Cantine mars'])
        );
    }

    public function testInvoiceIsGeneratedForRegisteredStudent(): void
    {
        $client = $this->login();
        [$studentId, $tariffId] = $this->studentAndTariff();

        $this->post($client, 'invoice', ['student_id' => (string) $studentId, 'tariff_id' => (string) $tariffId]);

        self::assertSelectorTextContains('.notice.success', 'Facture FAC-');
        $invoice = $this->invoiceOf($studentId, $tariffId);
        self::assertSame('A payer', $invoice['status']);
        self::assertStringStartsWith('FAC-', (string) $invoice['invoice_number']);
    }

    public function testSameTariffCannotBeInvoicedTwiceToTheSameStudent(): void
    {
        $client = $this->login();
        [$studentId, $tariffId] = $this->studentAndTariff();
        $this->post($client, 'invoice', ['student_id' => (string) $studentId, 'tariff_id' => (string) $tariffId]);

        $this->post($client, 'invoice', ['student_id' => (string) $studentId, 'tariff_id' => (string) $tariffId]);

        self::assertSelectorTextContains('.notice.error', 'deja facture a cet eleve');
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM invoices WHERE student_id = ? AND tariff_id = ?',
            [$studentId, $tariffId]
        ));
    }

    public function testInvoiceIsRefusedForStudentWithoutValidRegistration(): void
    {
        $client = $this->login();
        [, $tariffId] = $this->studentAndTariff();

        $this->post($client, 'invoice', ['student_id' => '999999', 'tariff_id' => (string) $tariffId]);

        self::assertSelectorTextContains('.notice.error', 'pas d\'inscription validee');
    }

    public function testPartialPaymentMarksInvoicePartialAndIssuesReceipt(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);

        $this->post($client, 'payment', [
            'invoice_id' => (string) $invoiceId,
            'amount' => '10000',
            'method' => 'Especes',
            'payment_date' => '2026-01-20',
        ]);

        self::assertSelectorTextContains('.notice.success', 'Recu REC-');
        self::assertSame('Partiel', $this->connection()->fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
        self::assertSame(1, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM receipts r INNER JOIN payments p ON p.id = r.payment_id WHERE p.invoice_id = ?',
            [$invoiceId]
        ));
    }

    public function testFullPaymentMarksInvoicePaid(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);
        $amount = (float) $this->connection()->fetchOne('SELECT amount FROM invoices WHERE id = ?', [$invoiceId]);

        $this->post($client, 'payment', ['invoice_id' => (string) $invoiceId, 'amount' => (string) $amount]);

        self::assertSame('Payee', $this->connection()->fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    }

    public function testPaymentAboveRemainingAmountIsRejected(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);
        $amount = (float) $this->connection()->fetchOne('SELECT amount FROM invoices WHERE id = ?', [$invoiceId]);

        $this->post($client, 'payment', ['invoice_id' => (string) $invoiceId, 'amount' => (string) ($amount + 1000)]);

        self::assertSelectorTextContains('.notice.error', 'depasse le reste a payer');
        self::assertSame(0, (int) $this->connection()->fetchOne(
            'SELECT COUNT(*) FROM payments WHERE invoice_id = ?',
            [$invoiceId]
        ));
    }

    public function testPaymentWithUnknownMethodIsRejected(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);

        $this->post($client, 'payment', [
            'invoice_id' => (string) $invoiceId,
            'amount' => '5000',
            'method' => 'Bitcoin',
        ]);

        self::assertSelectorTextContains('.notice.error', 'Mode de paiement invalide.');
    }

    public function testReceiptKeepsTheIssuingUser(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);

        $this->post($client, 'payment', ['invoice_id' => (string) $invoiceId, 'amount' => '5000']);

        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::COMPTABLE);
        self::assertNotNull($user);
        self::assertSame($user->getId(), (int) $this->connection()->fetchOne(
            'SELECT r.issued_by FROM receipts r INNER JOIN payments p ON p.id = r.payment_id WHERE p.invoice_id = ?',
            [$invoiceId]
        ));
    }

    public function testCancellingPaymentRestoresRemainingAmountAndKeepsReason(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);
        $amount = (float) $this->connection()->fetchOne('SELECT amount FROM invoices WHERE id = ?', [$invoiceId]);
        $this->post($client, 'payment', ['invoice_id' => (string) $invoiceId, 'amount' => (string) $amount]);
        $paymentId = (int) $this->connection()->fetchOne('SELECT id FROM payments WHERE invoice_id = ?', [$invoiceId]);

        $this->post($client, 'cancel', ['payment_id' => (string) $paymentId, 'reason' => 'Cheque sans provision']);

        self::assertSelectorTextContains('.notice.success', 'Paiement annule.');
        $payment = $this->connection()->fetchAssociative('SELECT * FROM payments WHERE id = ?', [$paymentId]);
        self::assertIsArray($payment);
        self::assertSame('Annule', $payment['status']);
        self::assertSame('Cheque sans provision', $payment['cancelled_reason']);
        self::assertSame('A payer', $this->connection()->fetchOne('SELECT status FROM invoices WHERE id = ?', [$invoiceId]));
    }

    public function testCancellingPaymentWithoutReasonIsRejected(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);
        $this->post($client, 'payment', ['invoice_id' => (string) $invoiceId, 'amount' => '5000']);
        $paymentId = (int) $this->connection()->fetchOne('SELECT id FROM payments WHERE invoice_id = ?', [$invoiceId]);

        $this->post($client, 'cancel', ['payment_id' => (string) $paymentId, 'reason' => '  ']);

        self::assertSelectorTextContains('.notice.error', 'Le motif d\'annulation est obligatoire.');
        self::assertSame('Valide', $this->connection()->fetchOne('SELECT status FROM payments WHERE id = ?', [$paymentId]));
    }

    public function testPaymentIsRefusedWithoutCsrfToken(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);

        $client->request('POST', '/symfony/finance', [
            'action' => 'payment',
            'invoice_id' => (string) $invoiceId,
            'amount' => '5000',
        ]);
        $client->followRedirect();

        self::assertSelectorTextContains('.notice.error', 'Session expiree');
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM payments'));
    }

    public function testReceiptPageShowsTheStudentAndTheAmount(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);
        $this->post($client, 'payment', ['invoice_id' => (string) $invoiceId, 'amount' => '5000']);
        $paymentId = (int) $this->connection()->fetchOne('SELECT id FROM payments WHERE invoice_id = ?', [$invoiceId]);

        $client->request('GET', '/symfony/finance/receipts/'.$paymentId);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'REC-');
    }

    public function testUnknownReceiptReturnsNotFound(): void
    {
        $client = $this->login();

        $client->request('GET', '/symfony/finance/receipts/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testInvoicesCanBeFilteredByStatus(): void
    {
        $client = $this->login();
        $invoiceId = $this->invoice($client);
        $amount = (float) $this->connection()->fetchOne('SELECT amount FROM invoices WHERE id = ?', [$invoiceId]);
        $this->post($client, 'payment', ['invoice_id' => (string) $invoiceId, 'amount' => (string) $amount]);

        $client->request('GET', '/symfony/finance?status=A+payer');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Aucune facture.');
    }

    public function testTeacherCannotReachFinance(): void
    {
        $client = static::createClient();
        $this->reset();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::ENSEIGNANT);
        self::assertNotNull($user);
        $client->loginUser($user);

        $client->request('GET', '/symfony/finance');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, string> $payload
     */
    private function post(KernelBrowser $client, string $action, array $payload): void
    {
        $crawler = $client->request('GET', '/symfony/finance');
        $token = (string) $crawler
            ->filter('input[value="'.$action.'"] ~ input[name="_token"]')
            ->first()
            ->attr('value');

        $client->request('POST', '/symfony/finance', $payload + ['action' => $action, '_token' => $token]);
        $client->followRedirect();
    }

    private function invoice(KernelBrowser $client): int
    {
        [$studentId, $tariffId] = $this->studentAndTariff();
        $this->post($client, 'invoice', ['student_id' => (string) $studentId, 'tariff_id' => (string) $tariffId]);

        return (int) $this->invoiceOf($studentId, $tariffId)['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceOf(int $studentId, int $tariffId): array
    {
        $invoice = $this->connection()->fetchAssociative(
            'SELECT * FROM invoices WHERE student_id = ? AND tariff_id = ?',
            [$studentId, $tariffId]
        );
        self::assertIsArray($invoice);

        return $invoice;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function studentAndTariff(): array
    {
        $connection = $this->connection();
        $studentId = (int) $connection->fetchOne(
            "SELECT student_id FROM registrations
             WHERE school_year_id = ? AND status = 'Validee' ORDER BY student_id LIMIT 1",
            [FinanceManager::SCHOOL_YEAR_ID]
        );
        $tariffId = (int) $connection->fetchOne('SELECT id FROM tariffs WHERE active = 1 ORDER BY id LIMIT 1');
        self::assertGreaterThan(0, $studentId);
        self::assertGreaterThan(0, $tariffId);

        return [$studentId, $tariffId];
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function reset(): void
    {
        $connection = $this->connection();
        $connection->executeStatement('DELETE FROM receipts');
        $connection->executeStatement('DELETE FROM payments');
        $connection->executeStatement('DELETE FROM invoices');
    }

    private function login(): KernelBrowser
    {
        $client = static::createClient();
        $this->reset();
        $user = static::getContainer()->get(UserRepository::class)->findOneByEmail(TestUsers::COMPTABLE);
        self::assertNotNull($user);
        $client->loginUser($user);

        return $client;
    }
}
