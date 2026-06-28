<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Baris invoice hutang yang dilunasi oleh sebuah BG/Cek keluar (saat diserahkan).
 */
class BsOutgoingChequeLine extends Model
{
    use HasTenant;

    protected $table = 'bs_outgoing_cheque_lines';
    protected $fillable = ['tenant_id', 'outgoing_cheque_id', 'ap_invoice_id', 'amount', 'payment_id'];
    protected $casts = ['amount' => 'decimal:2'];

    public function cheque(): BelongsTo { return $this->belongsTo(BsOutgoingCheque::class, 'outgoing_cheque_id'); }

    public function invoice(): BelongsTo { return $this->belongsTo(BsApInvoice::class, 'ap_invoice_id'); }

    public function payment(): BelongsTo { return $this->belongsTo(BsApPayment::class, 'payment_id'); }
}
