<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Squareetlabs\VeriFactu\Helpers\HashHelper;
use Squareetlabs\VeriFactu\Models\Invoice;
use Squareetlabs\VeriFactu\Services\AeatClient;
use Tests\TestCase;

class InvoiceChainingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test que la primera factura de una serie se registra sin huella previa
     * y genera un hash SHA-256 válido de 64 caracteres en mayúsculas.
     */
    public function test_first_invoice_in_chain_has_empty_previous_hash_and_valid_sha256(): void
    {
        $invoice = Invoice::factory()->create([
            'issuer_tax_id' => 'B12345678',
            'number' => 'FAC-2025-001',
            'date' => '2025-01-15',
            'type' => InvoiceType::STANDARD,
            'tax' => 21.00,
            'total' => 121.00,
            'is_first_invoice' => true,
        ]);

        $this->assertTrue($invoice->is_first_invoice);
        $this->assertNull($invoice->previous_invoice_hash);
        $this->assertNull($invoice->previous_invoice_number);
        $this->assertNotNull($invoice->hash);
        $this->assertMatchesRegularExpression('/^[A-F0-9]{64}$/', $invoice->hash);
        $this->assertTrue($invoice->isChainValid());
    }

    /**
     * Test que una segunda factura encadena automáticamente con la primera
     * detectando la huella previa y persistiendo number, date y hash del registro anterior.
     */
    public function test_second_invoice_automatically_chains_to_previous_invoice(): void
    {
        $firstInvoice = Invoice::factory()->create([
            'issuer_tax_id' => 'B12345678',
            'number' => 'FAC-2025-001',
            'date' => '2025-01-15',
            'type' => InvoiceType::STANDARD,
            'tax' => 21.00,
            'total' => 121.00,
            'is_first_invoice' => true,
        ]);

        $secondInvoice = Invoice::factory()->create([
            'issuer_tax_id' => 'B12345678',
            'number' => 'FAC-2025-002',
            'date' => '2025-01-16',
            'type' => InvoiceType::STANDARD,
            'tax' => 42.00,
            'total' => 242.00,
            'is_first_invoice' => false,
        ]);

        $this->assertFalse($secondInvoice->is_first_invoice);
        $this->assertEquals($firstInvoice->number, $secondInvoice->previous_invoice_number);
        $this->assertEquals($firstInvoice->hash, $secondInvoice->previous_invoice_hash);
        $this->assertEquals($firstInvoice->hash, $secondInvoice->getPreviousHash());
        $this->assertTrue($secondInvoice->isChainValid($firstInvoice));
        $this->assertTrue(HashHelper::verifyChain($firstInvoice->hash, $secondInvoice->previous_invoice_hash));
    }

    /**
     * Test de encadenamiento secuencial ininterrumpido en una cadena de 3 facturas (A -> B -> C).
     */
    public function test_chain_of_three_consecutive_invoices_is_unbroken(): void
    {
        $inv1 = Invoice::factory()->create([
            'issuer_tax_id' => 'B99887766',
            'number' => 'SEC-001',
            'is_first_invoice' => true,
        ]);

        $inv2 = Invoice::factory()->create([
            'issuer_tax_id' => 'B99887766',
            'number' => 'SEC-002',
            'is_first_invoice' => false,
        ]);

        $inv3 = Invoice::factory()->create([
            'issuer_tax_id' => 'B99887766',
            'number' => 'SEC-003',
            'is_first_invoice' => false,
        ]);

        // Verificaciones de la cadena
        $this->assertTrue($inv1->is_first_invoice);
        $this->assertNull($inv1->previous_invoice_hash);

        $this->assertEquals($inv1->hash, $inv2->previous_invoice_hash);
        $this->assertEquals($inv2->hash, $inv3->previous_invoice_hash);

        $this->assertTrue(HashHelper::verifyChain($inv1->hash, $inv2->previous_invoice_hash));
        $this->assertTrue(HashHelper::verifyChain($inv2->hash, $inv3->previous_invoice_hash));

        $this->assertTrue($inv1->isChainValid());
        $this->assertTrue($inv2->isChainValid($inv1));
        $this->assertTrue($inv3->isChainValid($inv2));
    }

    /**
     * Test de conformidad matemática estricta con los ejemplos oficiales de la AEAT
     * Especificación técnica v0.1.2 (Casos 1 y 2, págs. 10-11).
     */
    public function test_official_aeat_two_invoice_chaining_compliance(): void
    {
        // Caso 1: Primera factura
        $case1Data = [
            'issuer_tax_id' => '89890001K',
            'invoice_number' => '12345678/G33',
            'issue_date' => '01-01-2024',
            'invoice_type' => 'F1',
            'total_tax' => '12.35',
            'total_amount' => '123.45',
            'previous_hash' => '',
            'generated_at' => '2024-01-01T19:20:30+01:00',
        ];

        $hash1 = HashHelper::generateInvoiceHash($case1Data);
        $expectedHash1 = '3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60';

        $this->assertEquals($expectedHash1, $hash1['hash']);
        $this->assertTrue(HashHelper::verifyInvoiceHash($expectedHash1, $case1Data));

        // Caso 2: Segunda factura encadenada a la primera
        $case2Data = [
            'issuer_tax_id' => '89890001K',
            'invoice_number' => '12345679/G34',
            'issue_date' => '01-01-2024',
            'invoice_type' => 'F1',
            'total_tax' => '12.35',
            'total_amount' => '123.45',
            'previous_hash' => $hash1['hash'], // Encadenamiento con la huella de la primera
            'generated_at' => '2024-01-01T19:20:35+01:00',
        ];

        $hash2 = HashHelper::generateInvoiceHash($case2Data);
        $expectedHash2 = 'F7B94CFD8924EDFF273501B01EE5153E4CE8F259766F88CF6ACB8935802A2B97';

        $this->assertEquals($expectedHash2, $hash2['hash']);
        $this->assertTrue(HashHelper::verifyInvoiceHash($expectedHash2, $case2Data));

        // Verificación de la integridad del eslabón
        $this->assertTrue(HashHelper::verifyChain($hash1['hash'], $case2Data['previous_hash']));
    }

    /**
     * Test de inmutabilidad: Actualizar campos no fiscales de una factura ya emitida
     * no debe alterar su hash ni romper la cadena existente.
     */
    public function test_invoice_hash_remains_immutable_on_non_critical_updates(): void
    {
        $invoice = Invoice::factory()->create([
            'issuer_tax_id' => 'B55443322',
            'number' => 'IMM-001',
            'customer_name' => 'Original Customer SL',
            'status' => 'draft',
            'is_first_invoice' => true,
        ]);

        $originalHash = $invoice->hash;
        $this->assertNotNull($originalHash);

        // Modificar campos secundarios
        $invoice->customer_name = 'Updated Customer Name';
        $invoice->status = 'sent';
        $invoice->description = 'Updated description after review';
        $invoice->save();

        $invoice->refresh();
        $this->assertEquals($originalHash, $invoice->hash, 'El hash de una factura no debe mutar en actualizaciones secundarias.');
    }

    /**
     * Test que diferentes emisores (multi-tenant) mantienen cadenas de facturación independientes.
     */
    public function test_different_issuers_maintain_independent_chains(): void
    {
        // Factura del Emisor A
        $invoiceIssuerA = Invoice::factory()->create([
            'issuer_tax_id' => 'B11111111',
            'number' => 'FAC-001',
            'is_first_invoice' => true,
        ]);

        // Primera Factura del Emisor B (no debe encadenarse con el Emisor A)
        $invoiceIssuerB = Invoice::factory()->create([
            'issuer_tax_id' => 'B22222222',
            'number' => 'FAC-001',
            'is_first_invoice' => false,
        ]);

        // Al no existir facturas previas de B, debe quedar como primera factura de B
        $this->assertTrue($invoiceIssuerB->is_first_invoice);
        $this->assertNull($invoiceIssuerB->previous_invoice_hash);
        $this->assertNotEquals($invoiceIssuerA->hash, $invoiceIssuerB->previous_invoice_hash);
    }

    /**
     * Test que detecta una cadena rota o alterada fraudulentamente.
     */
    public function test_tampered_chain_is_detected(): void
    {
        $invoice1 = Invoice::factory()->create([
            'issuer_tax_id' => 'B12345678',
            'number' => 'TAMP-001',
            'is_first_invoice' => true,
        ]);

        $invoice2 = Invoice::factory()->create([
            'issuer_tax_id' => 'B12345678',
            'number' => 'TAMP-002',
            'is_first_invoice' => false,
        ]);

        // Simular alteración externa o fraude en la huella previa
        $invoice2->previous_invoice_hash = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

        $this->assertFalse($invoice2->isChainValid($invoice1));
        $this->assertFalse(HashHelper::verifyChain($invoice1->hash, $invoice2->previous_invoice_hash));
    }

    /**
     * Test de consistencia bit a bit entre la huella generada por el modelo Invoice
     * y la huella calculada por AeatClient::buildFingerprint() con formato oficial AEAT (d-m-Y, %.2f, issued_at).
     */
    public function test_model_hash_matches_aeat_client_fingerprint_bit_for_bit(): void
    {
        $invoice = Invoice::factory()->create([
            'issuer_tax_id' => 'B12345678',
            'number' => 'AEAT-2026-001',
            'date' => '2026-03-09',
            'type' => InvoiceType::STANDARD,
            'tax' => 21.0,
            'total' => 121.0,
            'is_first_invoice' => true,
        ]);

        $client = new AeatClient(
            certPath: 'dummy.pem',
            certPassword: null,
            production: false
        );

        $reflection = new \ReflectionMethod(AeatClient::class, 'buildFingerprint');
        $reflection->setAccessible(true);

        $aeatHash = $reflection->invoke(
            $client,
            $invoice->issuer_tax_id,
            $invoice->getInvoiceNumber(),
            $invoice->getIssueDate()->format('d-m-Y'),
            $invoice->getInvoiceType(),
            sprintf('%.2f', $invoice->getTaxAmount()),
            sprintf('%.2f', $invoice->getTotalAmount()),
            $invoice->issued_at->format('c'),
            $invoice->getPreviousHash() ?? ''
        );

        $this->assertSame($aeatHash, $invoice->hash);
    }

    /**
     * Test que un soft-delete sobre una factura emitida NO rompe el encadenamiento de la siguiente factura.
     */
    public function test_soft_deleted_invoice_is_preserved_in_chain(): void
    {
        $inv1 = Invoice::factory()->create([
            'issuer_tax_id' => 'B77777777',
            'number' => 'DEL-001',
            'is_first_invoice' => true,
        ]);

        // Borrado lógico de la primera factura
        $inv1->delete();
        $this->assertSoftDeleted('invoices', ['id' => $inv1->id]);

        // La segunda factura debe seguir encadenando contra la huella de inv1
        $inv2 = Invoice::factory()->create([
            'issuer_tax_id' => 'B77777777',
            'number' => 'DEL-002',
            'is_first_invoice' => false,
        ]);

        $this->assertFalse($inv2->is_first_invoice);
        $this->assertSame($inv1->hash, $inv2->previous_invoice_hash);
        $this->assertTrue($inv2->isChainValid());
    }

    /**
     * Test que detecta manipulación directa de importes fiscales en BD aunque las columnas hash no se toquen.
     */
    public function test_tampering_with_fiscal_amount_in_database_invalidates_chain(): void
    {
        $invoice = Invoice::factory()->create([
            'issuer_tax_id' => 'B88888888',
            'number' => 'FRAUD-001',
            'tax' => 21.00,
            'total' => 121.00,
            'is_first_invoice' => true,
        ]);

        $this->assertTrue($invoice->isChainValid());

        // Alterar el importe directamente en base de datos saltándose Eloquent
        DB::table('invoices')->where('id', $invoice->id)->update(['total' => 9999.99]);

        $invoice->refresh();
        $this->assertFalse($invoice->isChainValid());
    }

    /**
     * Test de inmutabilidad RD 1007/2023: intentar modificar campos fiscales de una factura ya encadenada lanza DomainException.
     */
    public function test_modifying_fiscal_fields_of_already_chained_invoice_throws_exception(): void
    {
        $inv1 = Invoice::factory()->create([
            'issuer_tax_id' => 'B99999999',
            'number' => 'LOCK-001',
            'tax' => 21.00,
            'total' => 121.00,
            'is_first_invoice' => true,
        ]);

        Invoice::factory()->create([
            'issuer_tax_id' => 'B99999999',
            'number' => 'LOCK-002',
            'is_first_invoice' => false,
        ]);

        $this->expectException(\DomainException::class);

        $inv1->total = 500.00;
        $inv1->save();
    }
}
