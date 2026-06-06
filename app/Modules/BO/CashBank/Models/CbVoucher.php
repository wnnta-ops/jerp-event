<?php

namespace App\Modules\BO\CashBank\Models;

use App\Models\Bank;
use App\Models\Company;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantAwareModel;
use App\Models\CostCenter;
use App\Modules\BO\GeneralLedger\Contracts\PostsToGeneralLedger;
use App\Modules\BO\GeneralLedger\DataTransferObjects\JurnalLineDTO;
use App\Modules\BO\GeneralLedger\Models\JurnalHeader;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CbVoucher extends TenantAwareModel implements PostsToGeneralLedger
{
    use Auditable;

    protected $table = 'bo_cb_vouchers';

    /** Cash/bank movement direction per voucher type. */
    public const MASUK = ['BKM', 'BGM', 'BS', 'ANO'];

    public const KELUAR = ['BKK', 'BGK', 'BNG', 'ADM', 'PBS'];

    protected $fillable = [
        'tenant_id', 'jenis', 'no_voucher', 'tgl', 'company_id', 'bank_id',
        'akun_kas', 'akun_lawan', 'cost_center_id', 'nominal', 'pihak',
        'no_referensi', 'status', 'jurnal_id', 'keterangan',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected $casts = [
        'tgl' => 'date',
        'nominal' => 'decimal:2',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }

    public function jurnal(): BelongsTo
    {
        return $this->belongsTo(JurnalHeader::class, 'jurnal_id');
    }

    public function isMasuk(): bool
    {
        return in_array($this->jenis, self::MASUK, true);
    }

    // --- PostsToGeneralLedger ---

    public function glLines(): array
    {
        $amount = (float) $this->nominal;
        $cc = $this->cost_center_id;

        if ($this->isMasuk()) {
            // Cash in: debit cash/bank, credit counter account.
            return [
                JurnalLineDTO::debet($this->akun_kas, $amount, $this->keterangan, $cc),
                JurnalLineDTO::kredit($this->akun_lawan, $amount, $this->keterangan, $cc),
            ];
        }

        // Cash out: debit counter account, credit cash/bank.
        return [
            JurnalLineDTO::debet($this->akun_lawan, $amount, $this->keterangan, $cc),
            JurnalLineDTO::kredit($this->akun_kas, $amount, $this->keterangan, $cc),
        ];
    }

    public function glDocumentType(): string
    {
        return $this->jenis;
    }

    public function glDocumentNo(): string
    {
        return $this->no_voucher;
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
        return $this->keterangan ?? ($this->jenis.' '.$this->no_voucher);
    }
}
