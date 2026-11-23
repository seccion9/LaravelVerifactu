<?php

declare(strict_types=1);

namespace Squareetlabs\VeriFactu\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Squareetlabs\VeriFactu\Enums\InvoiceType;
use Squareetlabs\VeriFactu\Contracts\VeriFactuInvoice;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class Invoice extends Model implements VeriFactuInvoice
{
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory()
    {
        return \Database\Factories\Squareetlabs\VeriFactu\Models\InvoiceFactory::new();
    }

    protected static function booted()
    {
        static::saving(function (Invoice $invoice) {
            $criticalFields = [
                'issuer_tax_id',
                'number',
                'date',
                'type',
                'tax',
                'total',
                'previous_invoice_hash',
                'is_first_invoice',
                'issued_at',
            ];

            // 1. Inmutabilidad fiscal (RD 1007/2023):
            // Si la factura ya tiene un hash persistido:
            // - Si no cambian campos fiscales, conservar el hash intacto.
            // - Si cambian campos fiscales y ya está encadenada o aceptada por AEAT (CSV), bloquear la mutación.
            $originalHash = $invoice->getOriginal('hash');
            if ($invoice->exists && !empty($originalHash)) {
                if (!$invoice->isDirty($criticalFields)) {
                    return;
                }

                $hasChainedSuccessor = static::withTrashed()
                    ->where('previous_invoice_hash', $originalHash)
                    ->exists();

                if ($hasChainedSuccessor || !empty($invoice->getOriginal('csv'))) {
                    throw new \DomainException(
                        'No se pueden modificar los campos fiscales de una factura ya encadenada o registrada en AEAT (RD 1007/2023). Emita una factura rectificativa o subsanación.'
                    );
                }
            }

            // 2. Resolución de la huella anterior (Encadenamiento determinista con bloqueo pesimista)
            // Incluye soft-deleted (withTrashed) porque una factura emitida jamás desaparece de la cadena fiscal.
            if (empty($invoice->previous_invoice_hash) && !$invoice->is_first_invoice) {
                $query = static::withTrashed()
                    ->where('issuer_tax_id', $invoice->issuer_tax_id)
                    ->whereNotNull('hash');

                if ($invoice->exists && $invoice->getKey() !== null) {
                    $query->whereKeyNot($invoice->getKey());
                }

                $previousInvoice = $query->lockForUpdate()->latest('id')->first();

                if ($previousInvoice) {
                    $invoice->previous_invoice_number = $previousInvoice->number;
                    $invoice->previous_invoice_date = $previousInvoice->date;
                    $invoice->previous_invoice_hash = $previousInvoice->hash;
                    $invoice->is_first_invoice = false;
                } else {
                    $invoice->is_first_invoice = true;
                    $invoice->previous_invoice_hash = null;
                    $invoice->previous_invoice_number = null;
                    $invoice->previous_invoice_date = null;
                }
            } elseif ($invoice->is_first_invoice) {
                $invoice->previous_invoice_hash = null;
                $invoice->previous_invoice_number = null;
                $invoice->previous_invoice_date = null;
            }

            // 3. Marca temporal de generación: preservar issued_at si existe, o fijarlo ahora
            if (empty($invoice->issued_at)) {
                $invoice->issued_at = now();
            }

            // 4. Calcular huella SHA-256 conforme a especificación AEAT (Fecha d-m-Y, importes %.2f)
            $hashResult = \Squareetlabs\VeriFactu\Helpers\HashHelper::generateInvoiceHash($invoice->buildHashData());
            $invoice->hash = $hashResult['hash'];
        });
    }

    protected $table = 'invoices';

    protected $fillable = [
        'uuid',
        'number',
        'date',
        'customer_name',
        'customer_tax_id',
        'customer_country',
        'issuer_name',
        'issuer_tax_id',
        'issuer_country',
        'amount',
        'tax',
        'total',
        'type',
        'external_reference',
        'description',
        'status',
        'issued_at',
        'cancelled_at',
        'hash',
        'operation_date',
        'tax_period',
        'correction_type',
        // New fields from PR #8
        'csv',
        'previous_invoice_number',
        'previous_invoice_date',
        'previous_invoice_hash',
        'is_first_invoice',
        'rectificative_type',
        'rectified_invoices',
        'rectification_amount',
        'is_subsanacion',
        'rejected_invoice_number',
        'rejection_date',
        'numero_instalacion',
        'aeat_estado_registro',
        'aeat_codigo_error',
        'aeat_descripcion_error',
        'has_aeat_warnings',
    ];

    protected $casts = [
        'date' => 'date',
        'operation_date' => 'date',
        'issued_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'type' => InvoiceType::class,
        'amount' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
        // New casts from PR #8
        'previous_invoice_date' => 'date',
        'rejection_date' => 'date',
        'is_first_invoice' => 'boolean',
        'is_subsanacion' => 'boolean',
        'has_aeat_warnings' => 'boolean',
        'rectified_invoices' => 'array',
        'rectification_amount' => 'array',
    ];

    public function breakdowns()
    {
        return $this->hasMany(Breakdown::class);
    }

    public function recipients()
    {
        return $this->hasMany(Recipient::class);
    }

    // VeriFactuInvoice Contract Implementation

    public function getInvoiceNumber(): string
    {
        return $this->number;
    }

    public function getIssueDate(): Carbon
    {
        return $this->date;
    }

    public function getInvoiceType(): string
    {
        return $this->type->value ?? (string) $this->type;
    }

    public function getTotalAmount(): float
    {
        return (float) $this->total;
    }

    public function getTaxAmount(): float
    {
        return (float) $this->tax;
    }

    public function getCustomerName(): string
    {
        return $this->customer_name;
    }

    public function getCustomerTaxId(): ?string
    {
        return $this->customer_tax_id;
    }

    public function getBreakdowns(): Collection
    {
        return $this->breakdowns;
    }

    public function getRecipients(): Collection
    {
        return $this->recipients;
    }

    public function previousInvoice()
    {
        return $this->belongsTo(self::class, 'previous_invoice_hash', 'hash')
            ->withTrashed();
    }

    /**
     * Build canonical AEAT hash input fields for this invoice.
     *
     * @return array<string, string>
     */
    public function buildHashData(): array
    {
        if ($this->date instanceof \DateTimeInterface) {
            $issueDate = $this->date->format('d-m-Y');
        } elseif (!empty($this->date)) {
            $issueDate = Carbon::parse((string) $this->date)->format('d-m-Y');
        } else {
            $issueDate = '';
        }

        if ($this->issued_at instanceof \DateTimeInterface) {
            $generatedAt = $this->issued_at->format('c');
        } elseif (is_string($this->issued_at) && !empty($this->issued_at)) {
            $generatedAt = \Squareetlabs\VeriFactu\Helpers\DateTimeHelper::formatIso8601($this->issued_at);
        } else {
            $generatedAt = now()->format('c');
        }

        $prevHash = $this->previous_invoice_hash ?? $this->previous_hash ?? '';

        return [
            'issuer_tax_id' => (string) $this->issuer_tax_id,
            'invoice_number' => (string) $this->number,
            'issue_date' => $issueDate,
            'invoice_type' => $this->type instanceof \BackedEnum ? (string) $this->type->value : (string) $this->type,
            'total_tax' => sprintf('%.2f', (float) $this->tax),
            'total_amount' => sprintf('%.2f', (float) $this->total),
            'previous_hash' => (string) $prevHash,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * Verify whether both the invoice's own SHA-256 hash and its chain link with the previous invoice are valid.
     */
    public function isChainValid(?self $previousInvoice = null): bool
    {
        if (empty($this->hash)) {
            return false;
        }

        // 1. Verificar integridad criptográfica de la propia factura
        if (!\Squareetlabs\VeriFactu\Helpers\HashHelper::verifyInvoiceHash($this->hash, $this->buildHashData())) {
            return false;
        }

        // 2. Verificar eslabón con el registro anterior
        if ($this->is_first_invoice) {
            return empty($this->previous_invoice_hash);
        }

        if (empty($this->previous_invoice_hash)) {
            return false;
        }

        $prev = $previousInvoice ?? $this->previousInvoice()->first();
        if ($prev) {
            return \Squareetlabs\VeriFactu\Helpers\HashHelper::verifyChain($prev->hash, $this->previous_invoice_hash);
        }

        return (bool) preg_match('/^[A-F0-9]{64}$/i', $this->previous_invoice_hash);
    }

    public function getPreviousHash(): ?string
    {
        return $this->previous_invoice_hash ?? $this->previous_hash ?? null;
    }

    public function getOperationDescription(): string
    {
        return $this->description ?? 'Invoice issued';
    }

    public function getOperationDate(): ?Carbon
    {
        return $this->operation_date;
    }

    public function getTaxPeriod(): ?string
    {
        return $this->tax_period;
    }

    public function getCorrectionType(): ?string
    {
        if (!empty($this->correction_type)) {
            return $this->correction_type;
        }

        return $this->rectificative_type ?: null;
    }

    public function getExternalReference(): ?string
    {
        return $this->external_reference;
    }
}
