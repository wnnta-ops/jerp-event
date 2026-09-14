<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;

/**
 * Saldo & masa berlaku kartu debit/VA — SELALU lewat permintaan yang disetujui.
 *
 * Tidak ada jalan menyunting saldo secara langsung, dan itu disengaja: angka saldo menentukan
 * sebuah kartu boleh dipakai membayar atau tidak, sehingga setiap perubahannya harus punya
 * jejak siapa meminta, siapa menyetujui, kapan, dan atas dasar dokumen apa.
 */
class CreditCardService
{
    /**
     * Ajukan permintaan pada sebuah kartu.
     *
     * @param  array{type:string, amount?:float|int|string|null, expired_date?:string|null, source_type?:string|null, source_id?:int|null, reference?:string|null, note?:string|null}  $data
     */
    public function request(BsCreditCard $card, array $data, ?int $userId = null): BsCreditCardRequest
    {
        $type = (string) ($data['type'] ?? '');
        if (! array_key_exists($type, BsCreditCardRequest::TYPES)) {
            throw new BusinessRuleException('Jenis permintaan tidak dikenal.', errorCode: 'CC_BAD_TYPE');
        }

        $amount = $type === 'expiry' ? null : round((float) ($data['amount'] ?? 0), 2);
        $expired = $type === 'expiry' ? ($data['expired_date'] ?? null) : null;

        // Permintaan saldo tanpa nilai, atau permintaan masa berlaku tanpa tanggal, tidak bisa
        // disetujui menjadi apa pun — ditolak di sini supaya tidak menumpuk sebagai baris
        // "pending" yang selamanya tidak berarti.
        if ($type !== 'expiry' && (! $amount || $amount <= 0)) {
            throw new BusinessRuleException('Nilai permintaan saldo harus lebih dari 0.', errorCode: 'CC_ZERO');
        }
        if ($type === 'expiry' && ! $expired) {
            throw new BusinessRuleException('Permintaan masa berlaku wajib menyertakan tanggalnya.', errorCode: 'CC_NO_DATE');
        }

        return BsCreditCardRequest::create([
            'credit_card_id' => $card->id,
            'type'           => $type,
            'amount'         => $amount,
            'expired_date'   => $expired,
            'status'         => 'pending',
            'source_type'    => $data['source_type'] ?? null,
            'source_id'      => $data['source_id'] ?? null,
            'reference'      => $data['reference'] ?? null,
            'note'           => $data['note'] ?? null,
            'requested_by'   => $userId,
        ]);
    }

    /**
     * Setujui satu permintaan — DI SINILAH saldo/masa berlaku kartunya berubah.
     *
     * Seluruhnya dalam satu transaksi dan kartunya dikunci baris: dua persetujuan yang berjalan
     * bersamaan pada kartu yang sama akan saling menimpa saldonya bila tidak dikunci, dan
     * selisihnya tidak meninggalkan jejak apa pun.
     */
    public function approve(BsCreditCardRequest $req, ?int $userId = null): BsCreditCardRequest
    {
        return DB::transaction(function () use ($req, $userId): BsCreditCardRequest {
            if ($req->status !== 'pending') {
                throw new BusinessRuleException('Permintaan ini sudah diproses.', errorCode: 'CC_NOT_PENDING');
            }

            $card = BsCreditCard::whereKey($req->credit_card_id)->lockForUpdate()->first();
            if (! $card) {
                throw new BusinessRuleException('Kartu tidak ditemukan.', errorCode: 'CC_NO_CARD');
            }

            if ($req->type === 'topup') {
                $card->balance = round((float) $card->balance + (float) $req->amount, 2);
            } elseif ($req->type === 'reduce') {
                $sisa = round((float) $card->balance - (float) $req->amount, 2);
                // Saldo kartu tidak boleh minus: yang tidak ada tidak bisa dikurangi, dan
                // membiarkannya minus membuat kartu itu "boleh dipakai" atas uang yang tak ada.
                if ($sisa < 0) {
                    throw new BusinessRuleException(
                        'Pengurangan melebihi saldo kartu (saldo '.number_format((float) $card->balance, 2, ',', '.').').',
                        errorCode: 'CC_INSUFFICIENT',
                    );
                }
                $card->balance = $sisa;
            } else { // expiry
                $card->expired_date = $req->expired_date;
            }
            $card->save();

            $req->update([
                'status'      => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            return $req->refresh();
        });
    }

    /** Tolak permintaan — kartunya tidak berubah sama sekali. */
    public function reject(BsCreditCardRequest $req, ?int $userId = null, ?string $alasan = null): BsCreditCardRequest
    {
        if ($req->status !== 'pending') {
            throw new BusinessRuleException('Permintaan ini sudah diproses.', errorCode: 'CC_NOT_PENDING');
        }
        $req->update([
            'status'      => 'rejected',
            'approved_by' => $userId,
            'approved_at' => now(),
            'note'        => $alasan ?: $req->note,
        ]);

        return $req->refresh();
    }

    /**
     * "Masuk Saldo" — sisa realisasi bon Debit VA dikembalikan ke saldo kartunya.
     *
     * Diajukan DAN langsung disetujui dalam satu langkah, atas nama orang yang menekannya.
     * Bukan jalan pintas yang melompati persetujuan: keputusan menekan tombol itu SENDIRI yang
     * menjadi persetujuannya, dan barisnya tetap tercatat lengkap di Riwayat Permintaan —
     * siapa, kapan, dari bon mana, berapa. Menaruhnya di antrean "menunggu persetujuan" akan
     * bertentangan dengan yang dijanjikan tombolnya: saldonya bisa dipakai untuk pengajuan
     * berikutnya, sekarang.
     */
    public function masukSaldoDariBon(
        BsCreditCard $card,
        int $advanceId,
        string $advanceNumber,
        float $amount,
        ?int $userId = null,
    ): BsCreditCardRequest {
        $req = $this->request($card, [
            'type'        => 'topup',
            'amount'      => $amount,
            'source_type' => self::SUMBER_MASUK_SALDO,
            'source_id'   => $advanceId,
            'reference'   => $advanceNumber,
            'note'        => 'Masuk Saldo — sisa realisasi bon '.$advanceNumber
                .' dikembalikan ke kartu (tanpa jurnal; uangnya memang masih di akun kartu)',
        ], $userId);

        return $this->approve($req, $userId);
    }

    /** Penanda sumber permintaan yang lahir dari tombol Masuk Saldo — dasar kolom "Sisa Saldo". */
    public const SUMBER_MASUK_SALDO = 'cash_advance_sisa';

    /** Penanda permintaan yang lahir otomatis dari pembayaran Debit VA sebuah bon. */
    public const SUMBER_BON = 'cash_advance';

    /**
     * Permintaan MASA BERLAKU otomatis dari pembayaran bon sementara lewat Debit VA.
     *
     * SALDONYA TIDAK LAGI DIAJUKAN DI SINI. Kolom "Penambahan" pada Set Up Debit Card kini
     * dibaca langsung dari pembayaran Debit VA yang tersimpan (CreditCardClosingService), sebab
     * itulah yang diminta: penambahan berasal dari nominal pembayaran Debit VA. Mengajukan
     * permintaan penambahan otomatis di samping itu akan melahirkan angka KEDUA untuk uang yang
     * sama — menumpuk di antrean persetujuan, dan bila disetujui membuat saldo kartu terhitung
     * dua kali.
     *
     * Masa berlakunya tetap lewat persetujuan: kapan sebuah kartu berhenti bisa didebit adalah
     * keputusan tersendiri, bukan turunan dari pembayaran mana pun.
     */
    public function requestMasaBerlakuDariBon(
        BsCreditCard $card,
        int $advanceId,
        string $advanceNumber,
        string $expiredDate,
        ?int $userId = null,
    ): BsCreditCardRequest {
        return $this->request($card, [
            'type'         => 'expiry',
            'expired_date' => $expiredDate,
            'source_type'  => self::SUMBER_BON,
            'source_id'    => $advanceId,
            'reference'    => $advanceNumber,
            'note'         => 'Masa berlaku dari pembayaran Debit VA bon '.$advanceNumber,
        ], $userId);
    }
}
