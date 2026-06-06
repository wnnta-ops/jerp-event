<?php

namespace App\Modules\BO\AccountsReceivable\Models;

use App\Models\Company;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\BO\GeneralLedger\Contracts\PostsToGeneralLedger;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArInvoice extends TenantAwareModel implements PostsToGeneralLedger
{
    use Auditable;

    protected $table = 'bo_ar_invoices';

    protected $fillable = [
        'tenant_id', 'no_invoice', 'jenis', 'tgl', 'jatuh_tempo', 'company_id',
        'customer_id', 'customer_nama', 'kontrak_ref', 'akun_piutang', 'akun_pendapatan',
        'nominal', 'denda', 'nominal_bayar', 'sisa', 'status', 'jurnal_id', 'keterangan',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'tgl' => 'date',
        'jatuh_tempo' => 'date',
        'nominal' => 'decimal:2',
        'denda' => 'decimal:2',
        'nominal_bayar' => 'decimal:2',
        'sisa' => 'decimal:2',
    ];

    /** In-memory defaults so postings work before the row is reloaded. */
    protected $attributes = [
        'akun_piutang' => '1.1.02.001',
        'akun_pendapatan' => '4.1.01.001',
        'denda' => 0,
        'nominal_bayar' => 0,
        'sisa' => 0,
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(JurnalHeader::class, 'jurnal_id');
    }

    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['LUNAS', 'BATAL'], true)
            && $this->jatuh_tempo->isPast();
    }

    public function glLines(): array
    {
        $total = (float) $this->nominal;

        return [
            JurnalLineDTO::debet($this->akun_piutang, $total, 'Piutang '.$this->no_invoice),
            JurnalLineDTO::kredit($this->akun_pendapatan, $total, 'Pendapatan '.$this->no_invoice),
        ];
    }

    public function glDocumentType(): string
    {
        return 'INV-AR';
    }

    public function glDocumentNo(): string
    {
        return $this->no_invoice;
    }

    public function glCompanyId(): int
    {
        return (int) $this->company_id;
    }

    public function glDate(): DateTimeInterface
    {
        return $this->tgl;
    }

    public function glDescription(): ?string
    {
        return 'Invoice piutang '.$this->no_invoice.' — '.($this->customer_nama ?? '');
    }
}
