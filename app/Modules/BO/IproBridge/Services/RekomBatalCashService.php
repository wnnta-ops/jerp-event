<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rekom Batal Cash — SATU sumber kebenaran untuk seluruh jalur "memo cash".
 *
 * Tiga hal dipusatkan di sini karena ketiganya HARUS sepakat, dan ketidaksepakatannya
 * tidak menghasilkan galat melainkan jalan buntu yang senyap:
 *
 *  1. SIAPA yang disebut "cash" — dipakai layar Rekom Batal Cash (daftar barisnya) DAN
 *     gerbang tombol Ajukan Pembatalan. Bila keduanya memakai penyaring sendiri-sendiri,
 *     sebuah kontrak bisa TERKUNCI di Pembatalan Kontrak tetapi TIDAK MUNCUL di layar yang
 *     satu-satunya bisa membukanya.
 *  2. PENULISAN PENANDA ke ipro_bo.kpr_cancel_recommendations.
 *  3. SUMBER MANA yang sah membuka kunci untuk sebuah cara bayar.
 *
 * ── Kenapa penyaringnya enum kontrak, bukan nama skema "CASH 1X"/"CASH 3X" ──
 * Kolom "Cara bayar" di layar FO menampilkan fo_skema.name (KontrakResource), jadi yang
 * DILIHAT operator memang tulisan "CASH 1X"/"CASH 3X". Tetapi itu teks master yang bisa
 * diganti kapan saja — di repo ini nama skema pernah diawali massal dengan "OLD " oleh
 * migrasi 2026_08_12_150000, dan satu query lama yang menyaring `name LIKE 'KPR DP 3X%'`
 * sejak itu tidak pernah cocok lagi tanpa ada yang menyadarinya. Lebih jauh: fo_kontrak
 * .skema_id BOLEH KOSONG, sehingga menyaring lewat relasi skema diam-diam membuang kontrak
 * yang skemanya belum tertaut. Yang dipakai karena itu adalah enum fo_kontrak.cara_bayar,
 * satu-satunya nilai yang selalu terisi dan tidak bisa diketik ulang operator.
 */
final class RekomBatalCashService
{
    /**
     * Enum fo_kontrak.cara_bayar yang berarti cash.
     *
     * CASH = cash keras / "CASH 1X" (sekali bayar), CASH_BERTAHAP = "CASH 3X" (bertahap).
     * CICILAN SENGAJA TIDAK IKUT: permintaannya menyebut cash 1x dan cash 3x, dan memasukkan
     * cicilan berarti mengunci pembatalannya tanpa ada layar yang menerbitkan memonya.
     */
    public const CARA_BAYAR = ['CASH', 'CASH_BERTAHAP'];

    /** Label yang dibaca manusia untuk tiap enum di atas (dipakai layar & lampiran memo). */
    public const LABEL = ['CASH' => 'CASH 1X', 'CASH_BERTAHAP' => 'CASH 3X'];

    /**
     * Nilai kolom `source` untuk penanda yang lahir dari layar ini.
     *
     * HARUS sama persis dengan KprCancelRecommendation::SUMBER_CASH di aplikasi Back Office
     * (marger/ipro-fo_1/ipro-fo). Keduanya tidak bisa saling meng-import — dua aplikasi,
     * dua autoload — jadi kesepakatannya hanya berupa string ini.
     */
    public const SUMBER = 'cash_fo';

    /** Sumber penanda yang lahir dari modul KPR (Back Office). */
    public const SUMBER_KPR = ['reject_bi', 'reject_kpr'];

    /** Tahap kontrak yang tampil di layar ini — disamakan dengan Pembatalan Kontrak. */
    public const TAHAP = ['SPR', 'SPRS'];

    /** Status kontrak yang tampil di layar ini — disamakan dengan Pembatalan Kontrak. */
    public const STATUS = 'AKTIF';

    // ── 1. Siapa yang disebut "cash" ────────────────────────────────────────

    public static function adalahCash(?string $caraBayar): bool
    {
        return in_array(strtoupper(trim((string) $caraBayar)), self::CARA_BAYAR, true);
    }

    /** "CASH 1X" / "CASH 3X"; nama skema dipakai bila ada karena itulah yang tampil di layar lain. */
    public static function label(?string $caraBayar, ?string $namaSkema = null): string
    {
        $skema = trim((string) $namaSkema);
        if ($skema !== '') {
            return $skema;
        }

        $cb = strtoupper(trim((string) $caraBayar));

        return self::LABEL[$cb] ?? ($cb !== '' ? $cb : '—');
    }

    /**
     * Kontrak cash yang berhak mendapat Memo Rekom Batal.
     *
     * Dipakai layar Rekom Batal Cash MAUPUN lembar cetaknya, supaya baris yang tidak boleh
     * tampil juga tidak bisa dicetak dengan menyusun ?ids= sendiri di alamat.
     *
     * @return Builder<Kontrak>
     */
    public static function daftar(): Builder
    {
        return Kontrak::query()
            ->whereIn('cara_bayar', self::CARA_BAYAR)
            ->whereIn('tahap', self::TAHAP)
            ->where('status', self::STATUS);
    }

    // ── 2. Sumber mana yang membuka kunci ───────────────────────────────────

    /**
     * Sumber penanda yang sah membuka "Ajukan Pembatalan" untuk sebuah cara bayar —
     * atau null bila cara bayar itu memang tidak digerbangi sama sekali.
     *
     * Kenapa sumbernya ikut diperiksa, bukan sekadar "ada penandanya": kunci uniknya
     * (tenant_id, spr_number) hanya mengizinkan SATU penanda per SPR, sedangkan cara bayar
     * sebuah kontrak masih bisa berubah sesudah penandanya terbit (KontrakGeneratorService
     * memanggil syncCaraBayar tiap kali jadwal dibuat ulang; docblock-nya sendiri menyebut
     * "Ganti skema CASH → KPR"). Tanpa pemeriksaan ini, memo cash lama akan membuka kunci
     * kontrak yang kini berjalan sebagai KPR — tepat kasus yang gerbangnya dibuat untuk cegah.
     *
     * @return list<string>|null
     */
    public static function sumberPembuka(?string $caraBayar): ?array
    {
        $cb = strtoupper(trim((string) $caraBayar));

        if ($cb === 'KPR') {
            return self::SUMBER_KPR;
        }

        return self::adalahCash($cb) ? [self::SUMBER] : null;
    }

    // ── 3. Penulisan penanda ────────────────────────────────────────────────

    /**
     * Catat penanda rekom batal untuk kontrak cash terpilih.
     *
     * Memulangkan ringkasan, bukan bool: layar pemanggilnya WAJIB bisa memberi tahu operator
     * berapa yang benar-benar tercatat. Kegagalannya DILEMPAR (bukan ditelan), karena memo
     * yang tercetak sementara penandanya gagal adalah bentuk kegagalan terburuk — tombolnya
     * tetap mati dan tidak ada apa pun di layar yang menjelaskan kenapa.
     *
     * @param  iterable<Kontrak>  $kontrak
     * @return array{tersimpan:int,tanpa_booking:int}
     */
    public function catat(iterable $kontrak, ?int $userId = null, ?string $userName = null): array
    {
        $tenant = $this->tenantId();
        $now = now();
        $tersimpan = 0;
        $tanpaBooking = 0;

        foreach ($kontrak as $k) {
            $spr = trim((string) $k->no_kontrak);
            if ($spr === '') {
                continue;
            }

            // Cerminan booking di ipro_bo boleh belum ada — kolomnya sudah dilonggarkan
            // menjadi nullable justru untuk kasus ini (lihat migrasi 2026_09_22_180000).
            // Tetap dihitung dan dilaporkan supaya operator tahu baris itu belum tercermin
            // di Back Office, bukan supaya memonya dibatalkan.
            $bookingId = $this->bookingId($spr);
            if ($bookingId === null) {
                $tanpaBooking++;
            }

            $isi = [
                'booking_id' => $bookingId,
                'source' => self::SUMBER,
                'source_id' => (int) $k->id,
                'reason' => 'Rekom batal cash — '.self::label($k->cara_bayar, $k->skema?->name),
                'recommended_at' => $now,
                'recommended_by' => $userId,
                'recommended_by_name' => $userName,
                'updated_at' => $now,
            ];

            $kunci = ['tenant_id' => $tenant, 'spr_number' => $spr];

            // updateOrInsert() tidak dipakai: ia mengirim $isi apa adanya pada UPDATE,
            // sehingga created_at baris lama ikut ditimpa bila dimasukkan ke dalamnya —
            // dan bila tidak dimasukkan, INSERT-nya berakhir dengan created_at NULL.
            if ($this->tabel()->where($kunci)->exists()) {
                $this->tabel()->where($kunci)->update($isi);
            } else {
                $this->tabel()->insert(array_merge($kunci, $isi, ['created_at' => $now]));
            }

            $tersimpan++;
        }

        return ['tersimpan' => $tersimpan, 'tanpa_booking' => $tanpaBooking];
    }

    // ── Pembantu ────────────────────────────────────────────────────────────

    private function bo(): ConnectionInterface
    {
        return DB::connection((string) config('ipro.connection', 'ipro_bo'));
    }

    private function tabel(): \Illuminate\Database\Query\Builder
    {
        return $this->bo()->table('kpr_cancel_recommendations');
    }

    private function tenantId(): int
    {
        return (int) config('ipro.tenant_id', 1);
    }

    private function bookingId(string $spr): ?int
    {
        $id = $this->bo()->table('bookings')
            ->where('tenant_id', $this->tenantId())
            ->where('booking_number', $spr)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
