<?php

namespace App\Modules\BO\AccountsPayable\Models;

use App\Models\Company;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use App\Modules\BO\GeneralLedger\Contracts\PostsToGeneralLedger;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApInvoice extends TenantAwareModel implements PostsToGeneralLedger
{
    use Auditable;

    protected $table = 'bo_ap_invoices';

    /** COA for input VAT (PPN Masukan). */
    public const AKUN_PPN_MASUKAN = '1.1.02.004';

    /** COA for PPh payable withheld from the supplier. */
    public const AKUN_PPH = '2.1.01.003';

    protected $fillable = [
        'tenant_id', 'no_invoice', 'jenis', 'tgl', 'jatuh_tempo', 'company_id',
        'supplier_id', 'po_id', 'akun_beban', 'akun_hutang', 'nominal', 'ppn', 'pph',
        'total', 'dibayar', 'sisa', 'status', 'jurnal_id', 'keterangan',
        'approved_by', 'approved_at', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'tgl' => 'date',
        'jatuh_tempo' => 'date',
        'nominal' => 'decimal:2',
        'ppn' => 'decimal:2',
        'pph' => 'decimal:2',
        'total' => 'decimal:2',
        'dibayar' => 'decimal:2',
        'sisa' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'akun_beban' => '5.9.01.001',
        'akun_hutang' => '2.1.01.001',
        'ppn' => 0,
        'pph' => 0,
        'dibayar' => 0,
        'sisa' => 0,
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(JurnalHeader::class, 'jurnal_id');
    }

    public function computeTotal(): float
    {
        return round((float) $this->nominal + (float) $this->ppn - (float) $this->pph, 2);
    }

    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['LUNAS', 'BATAL'], true)
            && $this->jatuh_tempo->isPast();
    }

    public function glLines(): array
    {
        $lines = [
            JurnalLineDTO::debet($this->akun_beban, (float) $this->nominal, 'Beban '.$this->no_invoice),
        ];

        if ((float) $this->ppn > 0) {
            $lines[] = JurnalLineDTO::debet(self::AKUN_PPN_MASUKAN, (float) $this->ppn, 'PPN Masukan '.$this->no_invoice);
        }
        if ((float) $this->pph > 0) {
            $lines[] = JurnalLineDTO::kredit(self::AKUN_PPH, (float) $this->pph, 'PPh dipotong '.$this->no_invoice);
        }

        $lines[] = JurnalLineDTO::kredit($this->akun_hutang, $this->computeTotal(), 'Hutang '.$this->no_invoice);

        return $lines;
    }

    public function glDocumentType(): string
    {
        return 'INV-AP';
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
        return 'Invoice hutang '.$this->no_invoice;
    }
}
