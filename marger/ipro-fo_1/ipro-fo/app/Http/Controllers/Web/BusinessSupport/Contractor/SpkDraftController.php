<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Contractor;

use App\Domain\BusinessSupport\Models\BsSpkDraft;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Set Up SPK — konfigurasi draft/template default dokumen Cetak SPK.
 * Isi (judul, tempat, pembukaan, pasal) dipakai Cetak SPK dengan mengganti
 * placeholder {…}. Bila kosong, Cetak SPK memakai default bawaan.
 */
class SpkDraftController extends Controller
{
    public function edit(Request $request): Response
    {
        abort_unless($request->user()?->canModule('contractor'), 403);

        $draft = BsSpkDraft::query()->first();

        return Inertia::render('BusinessSupport/Contractor/SpkDraft', [
            'draft'        => $draft ? $draft->only(['judul', 'subjudul', 'tempat', 'pembukaan', 'pasals']) : self::defaultDraft(),
            'isDefault'    => $draft === null,
            'placeholders' => self::PLACEHOLDERS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'judul'         => ['required', 'string', 'max:120'],
            'subjudul'      => ['nullable', 'string', 'max:60'],
            'tempat'        => ['nullable', 'string', 'max:80'],
            'pembukaan'     => ['nullable', 'string', 'max:1000'],
            'pasals'        => ['required', 'array', 'min:1'],
            'pasals.*.title' => ['required', 'string', 'max:150'],
            'pasals.*.body'  => ['required', 'string', 'max:4000'],
        ]);

        BsSpkDraft::updateOrCreate(
            ['tenant_id' => tenant_id()],
            array_merge($v, ['updated_by' => $request->user()->id]),
        );

        return back()->with('success', 'Draft SPK disimpan. Cetak SPK akan memakai template ini.');
    }

    public function reset(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);
        BsSpkDraft::query()->delete();

        return back()->with('success', 'Draft SPK dikembalikan ke default bawaan.');
    }

    /** Placeholder yang tersedia (dijelaskan di UI). */
    public const PLACEHOLDERS = [
        '{pekerjaan}'        => 'Judul/uraian pekerjaan SPK',
        '{jenis}'            => 'Jenis pekerjaan',
        '{proyek}'           => 'Nama proyek',
        '{nilai_rp}'         => 'Nilai kontrak (Rp)',
        '{nilai_terbilang}'  => 'Nilai kontrak (terbilang)',
        '{ppn_note}'         => 'Keterangan PPN / Non-PKP',
        '{durasi}'           => 'Durasi (hari kalender)',
        '{durasi_terbilang}' => 'Durasi (terbilang)',
        '{periode_note}'     => 'Keterangan periode mulai–selesai',
        '{dp}'               => 'Persentase uang muka',
        '{retensi}'          => 'Persentase retensi',
        '{kota}'             => 'Kota/kedudukan perusahaan',
    ];

    /** @return array<string,mixed> */
    public static function defaultDraft(): array
    {
        return [
            'judul'     => 'SURAT PERINTAH KERJA',
            'subjudul'  => '(SPK)',
            'tempat'    => '',
            'pembukaan' => 'Pada hari ini {tanggal}, bertempat di {kota}, yang bertanda tangan di bawah ini:',
            'pasals'    => [
                ['title' => 'PASAL 1 — LINGKUP PEKERJAAN', 'body' => 'PIHAK PERTAMA memberikan pekerjaan kepada PIHAK KEDUA, dan PIHAK KEDUA menerima pekerjaan berupa: {pekerjaan} (jenis: {jenis}), berlokasi pada proyek {proyek}. Rincian lingkup pekerjaan tercantum pada Rincian Anggaran Biaya (RAB) yang menjadi bagian tidak terpisahkan dari Surat Perintah Kerja ini.'],
                ['title' => 'PASAL 2 — NILAI PEKERJAAN', 'body' => 'Nilai pekerjaan disepakati sebesar {nilai_rp} ({nilai_terbilang}), {ppn_note}. Nilai tersebut bersifat lumpsum fixed price sesuai rincian pada RAB terlampir.'],
                ['title' => 'PASAL 3 — JANGKA WAKTU PELAKSANAAN', 'body' => 'Pekerjaan dilaksanakan dalam jangka waktu {durasi} ({durasi_terbilang}) hari kalender, {periode_note}. Keterlambatan yang disebabkan PIHAK KEDUA menjadi tanggung jawab PIHAK KEDUA.'],
                ['title' => 'PASAL 4 — CARA PEMBAYARAN', 'body' => 'Pembayaran dilakukan secara bertahap sesuai prestasi pekerjaan (progress termin) dengan Uang Muka (DP) sebesar {dp}% dari nilai kontrak, dan retensi sebesar {retensi}% ditahan pada setiap termin. Jadwal pembayaran termin sebagaimana tabel terlampir. Setiap pembayaran dilakukan setelah Berita Acara Pemeriksaan/Pembayaran (BAP) disetujui kedua belah pihak.'],
                ['title' => 'PASAL 5 — RETENSI & MASA PEMELIHARAAN', 'body' => 'Retensi sebesar {retensi}% dari setiap termin ditahan sebagai jaminan pemeliharaan selama 90 (sembilan puluh) hari kalender sejak Serah Terima Pertama (PHO). Retensi dicairkan setelah Serah Terima Kedua (FHO) dan seluruh kewajiban pemeliharaan diselesaikan.'],
                ['title' => 'PASAL 6 — DENDA KETERLAMBATAN', 'body' => 'Apabila PIHAK KEDUA terlambat menyelesaikan pekerjaan, dikenakan denda sebesar 1‰ (satu permil) per hari keterlambatan dari nilai kontrak, dengan denda maksimum 5% (lima persen) dari nilai kontrak.'],
                ['title' => 'PASAL 7 — MUTU & KESELAMATAN KERJA (K3)', 'body' => 'PIHAK KEDUA wajib melaksanakan pekerjaan sesuai spesifikasi teknis dan standar mutu yang disepakati, serta menerapkan Keselamatan dan Kesehatan Kerja (K3). Segala kerugian/kecelakaan akibat kelalaian PIHAK KEDUA menjadi tanggung jawab PIHAK KEDUA.'],
                ['title' => 'PASAL 8 — FORCE MAJEURE', 'body' => 'Hal-hal di luar kemampuan kedua belah pihak (bencana alam, kebijakan pemerintah, dan sejenisnya) yang mengakibatkan pekerjaan tidak dapat dilaksanakan akan diselesaikan secara musyawarah.'],
                ['title' => 'PASAL 9 — PENYELESAIAN PERSELISIHAN', 'body' => 'Segala perselisihan diselesaikan secara musyawarah untuk mufakat. Apabila tidak tercapai kesepakatan, kedua belah pihak sepakat menyelesaikannya melalui Pengadilan Negeri {kota}.'],
                ['title' => 'PASAL 10 — PENUTUP', 'body' => 'Surat Perintah Kerja ini dibuat rangkap 2 (dua) bermaterai cukup, masing-masing mempunyai kekuatan hukum yang sama, dan berlaku sejak tanggal ditandatangani oleh kedua belah pihak.'],
            ],
        ];
    }
}
