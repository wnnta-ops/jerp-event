<?php

namespace App\Modules\Eprop\Services;

use App\Modules\Eprop\Support\DaftarTabel;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sinkron harian eProperty → J-ERP (input di dua tempat; J-ERP sumber utama).
 *
 * Membandingkan basis data BAYANGAN (J-ERP versi eProperty, dibangun `bangun_bayang.sh`) dengan
 * J-ERP sungguhan, dokumen per dokumen, memakai nomor dokumen eProperty sebagai kunci:
 *
 *  dokumen eProperty belum ada di J-ERP
 *    → ada input J-ERP yang SAMA (nominal + pihak + keterangan; tanggal diabaikan) → COCOK (ditautkan)
 *    → ada input J-ERP bernominal sama tapi detail lain                         → DITAHAN (dicek manual)
 *    → tidak ada sama sekali                                                    → MASUK (disalin otomatis)
 *  dokumen sudah ada di J-ERP tapi isinya berubah di eProperty
 *    → baris J-ERP belum diubah siapa pun sejak dimuat → DIPERBARUI
 *    → sudah diubah di J-ERP                          → KONFLIK (dicek manual, tidak ditimpa)
 *
 * Satu transaksi untuk semuanya: gagal di tengah = tidak ada yang berubah. Mode kering
 * menjalankan semuanya lalu membatalkan — untuk melihat laporannya dulu.
 */
class MesinSinkron
{
    private Connection $c;

    /** @var array<string,array> */
    private array $cfg;

    /** @var array<string,array> */
    private array $cfgMaster;

    /** tabel → [id bayangan → id J-ERP] untuk dokumen yang sudah dipetakan/disalin dalam jalan ini. */
    private array $peta = [];

    /** tabel → [kunci → [id_jerp, status, disinkron_pada]] dari eprop_tautan. */
    private array $tautan = [];

    /** tabel → [id_jerp → kunci eProperty] — kebalikan $tautan. */
    private array $tautanBalik = [];

    /** Nomor dokumen yang COCOK/DITAHAN: tidak boleh ikut tersalin lewat tabel lain (mis. jurnal/BKK-nya). */
    private array $lewatiNomor = [];

    /** id jurnal bayangan yang dirujuk dokumen — hanya disalin lewat dokumennya. */
    private ?array $jurnalDokumen = null;

    private array $hasil = [];

    private array $masterDisalin = [];

    private array $tundaan = [];

    /** tabel anak → [id J-ERP => true] untuk baris anak yang dibuat pada jalan ini. */
    private array $anakBaru = [];

    private array $kontrakBaru = [];

    /** SPR buatan J-ERP (sesudah batas) yang belum tertaut ke SPR eProperty — dilaporkan, tidak diubah. */
    private array $sprHanyaJerp = [];

    /** Nomor SPR J-ERP yang diganti nomor eProperty pada jalan ini: [lama, baru]. */
    private array $nomorDisamakan = [];

    private int $jalanId = 0;

    /**
     * @param  array{fo:string,bo:string,bfo:string,bbo:string}  $db
     * @param  string|null  $modul  'fo' / 'bo' = hanya dokumen modul itu; null = semua
     * @param  bool  $hanyaBaru  true = hanya MEMASUKKAN dokumen yang belum ada; data J-ERP yang sudah ada
     *                           tidak diperbarui (perubahan di eProperty dilaporkan sebagai konflik); penyamaan
     *                           nomor SPR dengan nomor eProperty tetap dijalankan
     */
    public function __construct(private array $db, private string $batas, private bool $kering = false, private ?string $kiriman = null, private ?string $modul = null, private bool $hanyaBaru = false)
    {
        $this->c = DB::connection();
        $this->cfg = DaftarTabel::dokumen();
        if ($modul !== null) {
            $this->cfg = array_filter($this->cfg, fn ($cfg) => $cfg['db'] === $modul);
        }
        $this->cfgMaster = DaftarTabel::master();
    }

    // ═════════════════════════════ jalan ═════════════════════════════

    /** @return array{jalan_id:int, ringkasan:array, hasil:array} */
    public function jalankan(?callable $log = null): array
    {
        $log ??= fn () => null;
        $this->jalanId = (int) DB::table('eprop_jalan')->insertGetId([
            'kiriman' => $this->kiriman, 'kering' => $this->kering, 'status' => 'berjalan',
            'mulai' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->muatTautan();

        $this->c->beginTransaction();
        try {
            $rencana = [];
            foreach ($this->cfg as $tabel => $cfg) {
                if (! $this->adaTabel('b', $tabel) || ! $this->adaTabel('n', $tabel)) {
                    continue;
                }
                $rencana[$tabel] = $this->klasifikasi($tabel, $cfg);
            }
            $this->tahanYangBergantung($rencana);
            foreach ($rencana as $tabel => $r) {
                $log(sprintf('  %-22s masuk %d · cocok %d · ditahan %d · diperbarui %d · konflik %d', $this->cfg[$tabel]['modul'],
                    count(array_diff_key($r['masuk'], $this->lewatiNomor)), count($r['cocok']), count($r['ditahan']),
                    count($r['ubah']), count($r['konflik'])));
            }
            foreach ($this->nomorDisamakan as [$lama, $baru]) {
                $log("  Nomor SPR disamakan dengan eProperty: {$lama} → {$baru}");
            }
            if ($this->sprHanyaJerp) {
                $log('  SPR J-ERP yang belum tertaut ke SPR eProperty (tidak diubah — di eProperty kemungkinan masih verifikasi / belum SPR):');
                foreach ($this->sprHanyaJerp as $s) {
                    $log('    - ' . $s);
                }
            }

            foreach ($rencana as $tabel => $r) {
                foreach ($r['masuk'] as $kunci => $idB) {
                    if (! isset($this->lewatiNomor[$kunci])) {
                        $this->salinDokumen($tabel, (int) $idB);
                    }
                }
                foreach ($r['ubah'] as $kunci => [$idB, $idN]) {
                    if ($this->hanyaBaru) {
                        $rowB = $this->baris('b', $tabel, (int) $idB);
                        $rowN = $this->baris('n', $tabel, (int) $idN);
                        $beda = $tabel === 'bs_journals' ? ['isi baris jurnal'] : $this->bedaKolom(
                            $this->kanonik('b', $tabel, $this->cfg[$tabel], $rowB), $this->kanonik('n', $tabel, $this->cfg[$tabel], $rowN));
                        if ($beda === ['category_id']) {
                            continue;   // kategori AP sudah dibetulkan di J-ERP — beda yang disengaja, tidak dilaporkan
                        }
                        $this->tambahHasil($tabel, $this->cfg[$tabel], (string) $kunci, 'konflik', $rowB, $this->labelNyata($tabel, $this->cfg[$tabel], $rowN),
                            'Berubah di eProperty — TIDAK diterapkan (mode hanya data baru). Data J-ERP tetap. Beda: ' . implode(', ', $beda));

                        continue;
                    }
                    $this->perbaruiDokumen($tabel, (int) $idB, (int) $idN, (string) $kunci);
                }
            }
            $this->isiTundaan();

            $ringkasan = $this->ringkas();
            if ($this->kering) {
                $this->c->rollBack();
            } else {
                $this->c->commit();
            }
        } catch (Throwable $e) {
            $this->c->rollBack();
            DB::table('eprop_jalan')->where('id', $this->jalanId)->update(['status' => 'gagal', 'galat' => mb_substr($e->getMessage(), 0, 60000), 'selesai' => now(), 'updated_at' => now()]);
            throw $e;
        }

        // Di luar transaksi: pada mode kering hasilnya tetap tersimpan untuk dibaca.
        $this->simpanHasil();
        DB::table('eprop_jalan')->where('id', $this->jalanId)->update([
            'status' => 'selesai', 'ringkasan' => json_encode($ringkasan), 'selesai' => now(), 'updated_at' => now(),
        ]);

        return ['jalan_id' => $this->jalanId, 'ringkasan' => $ringkasan, 'hasil' => $this->hasil, 'kontrak_baru' => $this->kontrakBaru];
    }

    // ═════════════════════════════ klasifikasi ═════════════════════════════

    private function klasifikasi(string $tabel, array $cfg): array
    {
        $k = $cfg['kunci'];
        $bayang = $this->kunciKeId('b', $tabel, $k);
        $nyata = $this->kunciKeId('n', $tabel, $k);
        $taut = $this->tautan[$tabel] ?? [];
        $r = ['masuk' => [], 'cocok' => [], 'ditahan' => [], 'ubah' => [], 'konflik' => []];
        $calonDitahan = [];   // id input J-ERP yang menjadi kandidat dokumen ditahan

        // Input J-ERP (nomor J-ERP) sesudah data dimuat, yang belum bertaut ke dokumen eProperty mana pun.
        $idTertaut = array_flip(array_filter(array_column($taut, 0)));
        $native = $this->c->table($this->n($tabel))
            ->when($this->adaKolom('n', $tabel, 'created_at'), fn ($q) => $q->where('created_at', '>=', $this->batas))
            ->when(isset($cfg['native_sumber']), fn ($q) => $q->whereIn('source', $cfg['native_sumber']))
            ->get()->filter(fn ($row) => ! isset($bayang[$row->{$k}]) && ! isset($idTertaut[$row->id]))
            ->keyBy('id')->all();

        if ($tabel === 'bs_journals') {
            $this->jurnalDokumen ??= $this->idJurnalDokumen();
        }
        foreach ($bayang as $kunci => $idB) {
            if (isset($nyata[$kunci])) {
                $this->peta[$tabel][$idB] = $nyata[$kunci];
                continue;
            }
            if ($tabel === 'bs_journals' && isset($this->jurnalDokumen[$idB])) {
                continue;   // jurnal milik dokumen: ikut (atau tertahan bersama) dokumennya
            }
            if (($taut[$kunci][1] ?? null) === 'paksa') {
                // Diputuskan di layar Rekonsiliasi: "Masukkan saja" — tanpa dicocokkan lagi.
                $r['masuk'][$kunci] = $idB;
                continue;
            }
            if (isset($taut[$kunci])) {
                if ($taut[$kunci][0]) {
                    $this->peta[$tabel][$idB] = (int) $taut[$kunci][0];
                    // Ditautkan manual di layar Rekonsiliasi → nomor SPR J-ERP ikut disamakan.
                    if ($tabel === 'fo_kontrak' && $taut[$kunci][1] === 'cocok') {
                        $this->samakanNomorSpr($cfg, (int) $taut[$kunci][0], (string) $kunci, $this->baris('b', $tabel, $idB));
                    }
                }
                $this->lewatiNomor[$kunci] = true;
                continue;
            }
            $rowB = $this->baris('b', $tabel, $idB);
            [$aksi, $pasangan, $calon] = $this->cocokkan($tabel, $cfg, $rowB, $native);
            if ($aksi !== 'masuk') {
                $this->tahanJurnalDari($tabel, $cfg, $rowB, $aksi, $kunci);
            }
            if ($aksi === 'cocok') {
                unset($native[$pasangan->id]);
                $this->peta[$tabel][$idB] = (int) $pasangan->id;
                $this->lewatiNomor[$kunci] = true;
                $r['cocok'][$kunci] = $idB;
                $this->catatTautan($tabel, $kunci, (int) $pasangan->id, 'cocok');
                $this->tambahHasil($tabel, $cfg, $kunci, 'cocok', $rowB, $this->labelNyata($tabel, $cfg, $pasangan));
                if ($tabel === 'fo_kontrak') {
                    $this->samakanNomorSpr($cfg, (int) $pasangan->id, (string) $kunci, $rowB);
                }
            } elseif ($aksi === 'ditahan') {
                foreach ($calon as $x) {
                    $calonDitahan[$x->id] = true;
                }
                $this->lewatiNomor[$kunci] = true;
                $r['ditahan'][$kunci] = $idB;
                $this->tambahHasil($tabel, $cfg, $kunci, 'ditahan', $rowB,
                    implode(' | ', array_map(fn ($x) => $this->labelNyata($tabel, $cfg, $x), array_slice($calon, 0, 3))),
                    'Ada input J-ERP bernominal sama tetapi keterangan/pihaknya berbeda — dicek manual (tautkan atau masukkan).');
            } else {
                $r['masuk'][$kunci] = $idB;
            }
        }

        // SPR yang dibuat di J-ERP tetapi belum ada di eProperty: TIDAK diubah apa pun — biasanya
        // customer-nya di eProperty masih verifikasi data / belum sampai SPR. Hanya dilaporkan.
        if ($tabel === 'fo_kontrak') {
            foreach (array_diff_key($native, $calonDitahan) as $x) {
                $this->sprHanyaJerp[] = $this->labelNyata($tabel, $cfg, $x) . ' · customer '
                    . ($this->c->table($this->n('fo_customers'))->where('id', $x->customer_id)->value('code') ?? '—');
            }
        }

        // Dokumen yang sudah ada di keduanya: berubah di eProperty?
        if (! empty($cfg['perbarui'])) {
            foreach ($bayang as $kunci => $idB) {
                if (! isset($nyata[$kunci]) || isset($this->lewatiNomor[$kunci]) || ($taut[$kunci][1] ?? null) === 'abaikan') {
                    continue;   // 'abaikan' = konflik yang diputuskan "Tetap J-ERP"
                }
                $idN = $nyata[$kunci];
                $kb = $this->kanonik('b', $tabel, $cfg, $this->baris('b', $tabel, $idB));
                $rowN = $this->baris('n', $tabel, $idN);
                $kn = $this->kanonik('n', $tabel, $cfg, $rowN);
                if ($this->samaKanonik($kb, $kn)) {
                    continue;
                }
                $batasBaris = max($this->batas, (string) ($taut[$kunci][2] ?? ''));
                $belumDiubah = ! property_exists($rowN, 'updated_at') || $rowN->updated_at === null || (string) $rowN->updated_at <= $batasBaris;
                if ($belumDiubah) {
                    $r['ubah'][$kunci] = [$idB, $idN];
                } else {
                    $r['konflik'][$kunci] = [$idB, $idN];
                    $this->tahanJurnalDari($tabel, $cfg, $this->baris('b', $tabel, $idB), 'konflik', $kunci);
                    $this->tambahHasil($tabel, $cfg, $kunci, 'konflik', $this->baris('b', $tabel, $idB), $this->labelNyata($tabel, $cfg, $rowN),
                        'Berubah di eProperty, tetapi baris J-ERP-nya sudah diubah sesudah dimuat (' . $rowN->updated_at . ') — tidak ditimpa: '
                        . implode(', ', $this->bedaKolom($kb, $kn)));
                }
            }
        }

        // Jurnal: isi baris berubah di eProperty (mis. invoice disunting) — dibandingkan sekaligus lewat SQL.
        if ($tabel === 'bs_journals') {
            foreach ($this->jurnalBerubah() as $no => [$idB, $idN, $diubah]) {
                if (isset($this->lewatiNomor[$no]) || ($taut[$no][1] ?? null) === 'abaikan') {
                    continue;
                }
                $batasBaris = max($this->batas, (string) ($taut[$no][2] ?? ''));
                if ($diubah === null || (string) $diubah <= $batasBaris) {
                    $r['ubah'][$no] = [$idB, $idN];
                } else {
                    $r['konflik'][$no] = [$idB, $idN];
                    $this->tambahHasil($tabel, $cfg, $no, 'konflik', $this->baris('b', $tabel, $idB), null,
                        'Isi jurnal berubah di eProperty, tetapi jurnal J-ERP-nya sudah disunting sesudah dimuat (' . $diubah . ') — tidak ditimpa.');
                }
            }
        }

        // Dokumen hasil muat yang hilang dari eProperty (dihapus di sana) — dilaporkan saja.
        $hilang = array_diff_key($nyata, $bayang);
        if (isset($cfg['kunci_jerp'])) {
            // Nomor terbitan J-ERP (mis. customer NUP publik CUST-…) memang tidak pernah ada di eProperty.
            $hilang = array_filter($hilang, fn ($kunci) => ! preg_match($cfg['kunci_jerp'], (string) $kunci), ARRAY_FILTER_USE_KEY);
        }
        if ($hilang && $this->adaKolom('n', $tabel, 'created_at')) {
            $nHilang = $this->c->table($this->n($tabel))->whereIn('id', array_values($hilang))->where('created_at', '<', $this->batas)
                ->when(isset($cfg['native_sumber']), fn ($q) => $q->whereNotIn('source', $cfg['native_sumber']))->count();
            // Penghapusan sungguhan di eProperty jarang & sedikit. Ratusan sekaligus = kiriman rusak/terpotong.
            if ($nHilang > max(20, (int) ceil(count($nyata) * 0.02))) {
                throw new RuntimeException(sprintf('Kiriman eProperty tampak TIDAK LENGKAP: %d dokumen %s ada di J-ERP tetapi tidak ada di kiriman (dari %d). '
                    . 'Sinkron dihentikan, tidak ada yang berubah. Periksa / minta ulang backup eProperty.', $nHilang, $cfg['modul'], count($nyata)));
            }
            $lama = $this->c->table($this->n($tabel))->whereIn('id', array_values($hilang))->where('created_at', '<', $this->batas)
                ->when(isset($cfg['native_sumber']), fn ($q) => $q->whereNotIn('source', $cfg['native_sumber']))
                ->limit(500)->get();
            foreach ($lama as $row) {
                $this->tambahHasil($tabel, $cfg, (string) $row->{$k}, 'hilang', $row, null, 'Ada di J-ERP (hasil muat) tetapi tidak ada lagi di eProperty — kemungkinan dihapus di eProperty. Tidak diubah.');
            }
        }

        return $r;
    }

    /**
     * @return array{0:string,1:object|null,2:array} [masuk|cocok|ditahan, pasangan, calon]
     */
    private function cocokkan(string $tabel, array $cfg, object $rowB, array $native): array
    {
        $m = $cfg['cocok'] ?? [];
        if (! $native || ! $m) {
            return ['masuk', null, []];
        }
        $nominal = $m['nominal'] ?? null;
        $calon = [];
        foreach ($native as $x) {
            if ($nominal && abs((float) $x->{$nominal} - (float) $rowB->{$nominal}) >= 1) {
                continue;
            }
            if (isset($m['jenis']) && (string) $x->{$m['jenis']} !== (string) $rowB->{$m['jenis']}) {
                continue;
            }
            if (! empty($m['pihak_wajib']) && ! $this->pihakSama($tabel, $cfg, $rowB, $x)) {
                continue;
            }
            $calon[] = $x;
        }
        if (! $calon) {
            return ['masuk', null, []];
        }
        $persis = array_values(array_filter($calon, fn ($x) => (! isset($m['teks']) || $this->teksSama($rowB->{$m['teks']}, $x->{$m['teks']}))
            && (! isset($m['pihak']) || $this->pihakSama($tabel, $cfg, $rowB, $x))
            && $this->pihakJugaSama($cfg, $rowB, $x)));
        if (! $persis && ! isset($m['teks']) && ! isset($m['pihak_juga']) && count($calon) === 1) {
            $persis = $calon;
        }
        if (count($persis) > 1 && isset($m['tanggal'])) {
            // Beberapa input J-ERP sama persis: ambil yang tanggalnya paling dekat.
            $t = strtotime((string) $rowB->{$m['tanggal']});
            usort($persis, fn ($a, $b) => abs(strtotime((string) $a->{$m['tanggal']}) - $t) <=> abs(strtotime((string) $b->{$m['tanggal']}) - $t));
        }

        return $persis ? ['cocok', $persis[0], $calon] : ['ditahan', null, $calon];
    }

    private function pihakSama(string $tabel, array $cfg, object $b, object $n): bool
    {
        $kol = $cfg['cocok']['pihak'] ?? null;
        if (! $kol) {
            return true;
        }
        if (! empty($cfg['cocok']['pihak_teks'])) {
            $x = $this->norm($b->{$kol});
            $y = $this->norm($n->{$kol});
            if ($x === '' || $y === '') {
                return false;
            }
            // "AGUS" (J-ERP) ↔ "AGUS SETIAWAN" (eProperty): kata pertama sama atau satu memuat yang lain.
            $kx = strtok(strtoupper(trim((string) $b->{$kol})), ' ');
            $ky = strtok(strtoupper(trim((string) $n->{$kol})), ' ');

            return $x === $y || str_starts_with($x, $y) || str_starts_with($y, $x) || ($kx !== false && $kx === $ky);
        }
        // Kolom tautan: bandingkan setelah dipetakan ke id J-ERP.
        $vb = $b->{$kol};
        if (isset($cfg['ref'][$kol])) {
            $vb = $this->idNyataRef($cfg['ref'][$kol], $vb);
        }

        return $vb !== null && (string) $vb === (string) $n->{$kol};
    }

    /**
     * SPR J-ERP yang sama dengan SPR eProperty memakai NOMOR eProperty — selama input masih di dua
     * tempat, eProperty-lah yang menerbitkan nomor resmi; nomor J-ERP hanya sementara. Ikut diganti:
     * booking BO (BO mengenal SPR lewat nomornya) dan rujukan nomor di data MUK. Nomor lama dicatat di
     * keterangan SPR. Teks lama yang menyebut nomor sementara (keterangan TTS/kwitansi) tidak diubah.
     */
    private function samakanNomorSpr(array $cfg, int $idN, string $nomorEprop, ?object $rowB): void
    {
        $lama = (string) $this->c->table($this->n('fo_kontrak'))->where('id', $idN)->value('no_kontrak');
        if ($lama === '' || $lama === $nomorEprop) {
            return;
        }
        $bentrok = $this->c->table($this->n('fo_kontrak'))->where('no_kontrak', $nomorEprop)->where('id', '!=', $idN)->exists();
        if ($bentrok) {
            $this->tambahHasil('fo_kontrak', $cfg, $nomorEprop, 'konflik', $rowB, $lama,
                "Nomor eProperty {$nomorEprop} sudah dipakai SPR J-ERP lain — nomor {$lama} TIDAK diganti. Periksa manual.");

            return;
        }

        // Nomor lama disimpan di kolom tersendiri (dijaga, tidak ikut dibandingkan) — bukan di
        // keterangan, yang ikut diperbarui dari eProperty sehingga catatannya akan tertimpa.
        $ubah = ['no_kontrak' => $nomorEprop];
        if ($this->adaKolom('n', 'fo_kontrak', 'no_kontrak_sementara')) {
            $ubah['no_kontrak_sementara'] = $lama;
        }
        $this->c->table($this->n('fo_kontrak'))->where('id', $idN)->update($ubah);

        $bo = $this->db['bo'];
        if ($this->c->table('information_schema.tables')->where('table_schema', $bo)->where('table_name', 'bookings')->exists()) {
            $this->c->table("{$bo}.bookings")->where('booking_number', $lama)
                ->update(['booking_number' => $nomorEprop, 'notes' => 'SPR ' . $nomorEprop]);
        }
        foreach (['bs_muk_contracts', 'bs_muk_lokasi', 'bs_muk_tagihan', 'bs_muk_contract_cogs', 'bs_cogs_hpp'] as $t) {
            if ($this->c->table('information_schema.columns')->where('table_schema', $bo)->where('table_name', $t)->where('column_name', 'no_kontrak')->exists()) {
                $this->c->table("{$bo}.{$t}")->where('no_kontrak', $lama)->update(['no_kontrak' => $nomorEprop]);
            }
        }

        $this->nomorDisamakan[] = [$lama, $nomorEprop];
        $alasan = "Nomor SPR J-ERP {$lama} diganti nomor eProperty {$nomorEprop} (booking BO ikut diganti).";
        // Catatan pada baris hasil "cocok" yang sudah ada; tautan manual (tanpa baris hasil) → baris baru.
        for ($i = count($this->hasil) - 1; $i >= 0; $i--) {
            if ($this->hasil[$i]['tabel'] === 'fo_kontrak' && $this->hasil[$i]['kunci'] === $nomorEprop) {
                $this->hasil[$i]['alasan'] = $alasan;

                return;
            }
        }
        $this->tambahHasil('fo_kontrak', $cfg, $nomorEprop, 'diperbarui', $rowB, $lama, $alasan);
    }

    /** Kolom tautan tambahan yang wajib sama (cocok.pihak_juga), dibandingkan sesudah dipetakan ke id J-ERP. */
    private function pihakJugaSama(array $cfg, object $b, object $n): bool
    {
        foreach ($cfg['cocok']['pihak_juga'] ?? [] as $kol) {
            $vb = isset($cfg['ref'][$kol]) ? $this->idNyataRef($cfg['ref'][$kol], $b->{$kol}) : $b->{$kol};
            if ($vb === null || (string) $vb !== (string) $n->{$kol}) {
                return false;
            }
        }

        return true;
    }

    private function teksSama($a, $b): bool
    {
        $x = $this->norm($a);
        $y = $this->norm($b);
        if ($x === '' && $y === '') {
            return true;
        }
        if ($x === '' || $y === '') {
            return false;
        }

        return $x === $y || (min(strlen($x), strlen($y)) >= 20 && (str_starts_with($x, $y) || str_starts_with($y, $x)));
    }

    private function norm($s): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $s)) ?? '';
    }

    /**
     * Jurnal yang ada di kedua sisi tetapi isinya beda (akun · debit · kredit per baris, + tanggal).
     *
     * @return array<string,array{0:int,1:int,2:?string}> nomor → [id bayangan, id J-ERP, updated_at J-ERP]
     */
    private function jurnalBerubah(): array
    {
        $sidik = function (string $sisi) {
            $db = $sisi === 'b' ? $this->db['bbo'] : $this->db['bo'];

            return $this->c->select("SELECT j.journal_number no, j.id, j.updated_at,
                    MD5(CONCAT(j.journal_date, '#', IFNULL(GROUP_CONCAT(CONCAT(a.code, ':', ROUND(l.debit, 2), ':', ROUND(l.credit, 2))
                        ORDER BY a.code, l.debit, l.credit SEPARATOR ','), ''))) sidik
                FROM `$db`.bs_journals j
                LEFT JOIN `$db`.bs_journal_lines l ON l.journal_id = j.id
                LEFT JOIN `$db`.bs_accounts a ON a.id = l.account_id
                WHERE j.source IN ('import', 'saldo_awal')" . ($sisi === 'n' ? ' AND j.deleted_at IS NULL' : '') . "
                GROUP BY j.id, j.journal_number, j.journal_date, j.updated_at");
        };
        $this->c->statement('SET SESSION group_concat_max_len = 1000000');
        $b = [];
        foreach ($sidik('b') as $r) {
            $b[$r->no] = [(int) $r->id, $r->sidik];
        }
        $out = [];
        foreach ($sidik('n') as $r) {
            if (isset($b[$r->no]) && $b[$r->no][1] !== $r->sidik) {
                $out[$r->no] = [$b[$r->no][0], (int) $r->id, $r->updated_at];
            }
        }

        return $out;
    }

    /** Jurnal yang dirujuk dokumen yang cocok/ditahan/konflik tidak boleh tersalin sendiri. */
    private function tahanJurnalDari(string $tabel, array $cfg, ?object $rowB, string $aksi = 'ditahan', string $milik = ''): void
    {
        if (! $rowB) {
            return;
        }
        foreach ($cfg['ref'] ?? [] as $kol => $ke) {
            if ($ke === 'bs_journals' && $rowB->{$kol}) {
                $no = $this->kunciDari('b', 'bs_journals', $rowB->{$kol});
                if ($no !== null && $no !== '' && ! isset($this->lewatiNomor[$no])
                    && ! $this->c->table($this->n('bs_journals'))->where('journal_number', $no)->exists()) {
                    $this->lewatiNomor[$no] = true;
                    if ($no !== $milik) {
                        $j = $this->c->table($this->b('bs_journals'))->where('journal_number', $no)->first();
                        $this->hasil[] = ['modul' => 'Jurnal GL', 'tabel' => 'bs_journals', 'kunci' => $no, 'aksi' => $aksi === 'cocok' ? 'cocok' : 'ditahan',
                            'ringkas' => $j ? mb_substr((string) $j->description, 0, 300) : null, 'jerp' => null,
                            'alasan' => 'Jurnal milik ' . $cfg['modul'] . ' ' . $milik . ($aksi === 'cocok' ? ' (sudah diinput di J-ERP — jurnalnya dari J-ERP)' : ' yang ' . $aksi),
                            'nominal' => $j ? (float) $j->total_debit : null, 'tanggal' => $j ? substr((string) $j->journal_date, 0, 10) : null];
                    }
                }
            }
        }
    }

    /** @return array<int,true> id jurnal bayangan yang dirujuk dokumen mana pun (termasuk baris anaknya). */
    private function idJurnalDokumen(): array
    {
        $ids = [];
        $tambah = function (string $tabel, string $kol) use (&$ids) {
            if ($this->adaTabel('b', $tabel) && $this->adaKolom('b', $tabel, $kol)) {
                foreach ($this->c->table($this->b($tabel))->whereNotNull($kol)->pluck($kol) as $id) {
                    $ids[(int) $id] = true;
                }
            }
        };
        foreach ($this->cfg as $tabel => $cfg) {
            foreach ($cfg['ref'] ?? [] as $kol => $ke) {
                if ($ke === 'bs_journals') {
                    $tambah($tabel, $kol);
                }
            }
            foreach ($cfg['anak'] ?? [] as $a) {
                foreach ($a['ref'] ?? [] as $kol => $ke) {
                    if ($ke === 'bs_journals') {
                        $tambah($a['tabel'], $kol);
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * Dokumen "masuk" yang merujuk dokumen tertahan (ditahan / konflik) ikut ditahan — mis.
     * kuitansi untuk invoice yang masih dicek. Diulang sampai tidak ada lagi yang berubah.
     */
    private function tahanYangBergantung(array &$rencana): void
    {
        do {
            $berubah = false;
            foreach ($rencana as $tabel => &$r) {
                $cfg = $this->cfg[$tabel];
                foreach ($r['masuk'] as $kunci => $idB) {
                    if (isset($this->lewatiNomor[$kunci])) {
                        continue;
                    }
                    $row = $this->baris('b', $tabel, (int) $idB);
                    $tertahan = $row ? $this->rujukanTertahan($cfg, $row) : null;
                    if ($tertahan) {
                        unset($r['masuk'][$kunci]);
                        $r['ditahan'][$kunci] = $idB;
                        $this->lewatiNomor[$kunci] = true;
                        $this->tahanJurnalDari($tabel, $cfg, $row, 'ditahan', (string) $kunci);
                        $this->tambahHasil($tabel, $cfg, (string) $kunci, 'ditahan', $row, null,
                            'Merujuk dokumen yang masih ditahan/konflik (' . $tertahan . ') — ikut menunggu.');
                        $berubah = true;
                    }
                }
            }
            unset($r);
        } while ($berubah);
    }

    private function rujukanTertahan(array $cfg, object $row): ?string
    {
        $cek = function (string $ke, $id) {
            if ($id === null || ! isset($this->cfg[$ke])) {
                return null;
            }
            $no = $this->kunciDari('b', $ke, $id);
            if ($no === null || $no === '' || ! isset($this->lewatiNomor[$no])) {
                return null;
            }
            // Bertaut ke input J-ERP (cocok) → rujukannya dialihkan ke pasangan itu: aman.
            if (! empty($this->tautan[$ke][$no][0]) || ! empty($this->peta[$ke][(int) $id])) {
                return null;
            }

            return $no;
        };
        foreach ($cfg['ref'] ?? [] as $kol => $ke) {
            if ($ke !== 'bs_journals' && ($no = $cek($ke, $row->{$kol}))) {
                return $no;
            }
        }
        foreach ($cfg['anak'] ?? [] as $a) {
            if (empty($a['ref']) || ! $this->adaTabel('b', $a['tabel'])) {
                continue;
            }
            $q = $this->c->table($this->b($a['tabel']));
            if ($a['fk']) {
                $q->where($a['fk'], $row->id);
            } else {
                foreach ($a['pengikat'] as $kolAnak => $kolInduk) {
                    $q->where($kolAnak, $row->{$kolInduk});
                }
            }
            foreach ($q->get() as $x) {
                foreach ($a['ref'] as $kol => $ke) {
                    if ($ke !== 'bs_journals' && ($no = $cek($ke, $x->{$kol}))) {
                        return $no;
                    }
                }
            }
        }

        return null;
    }

    // ═════════════════════════════ salin & perbarui ═════════════════════════════

    /** Salin satu dokumen bayangan (beserta anak & dokumen yang dirujuknya) ke J-ERP. */
    private function salinDokumen(string $tabel, int $idB): ?int
    {
        if (isset($this->peta[$tabel][$idB])) {
            return $this->peta[$tabel][$idB];
        }
        $cfg = $this->cfg[$tabel];
        $row = $this->baris('b', $tabel, $idB);
        if (! $row) {
            return null;
        }
        $kunci = (string) $row->{$cfg['kunci']};
        if (isset($this->lewatiNomor[$kunci])) {
            return null;
        }
        $nilai = $this->petakanBaris($tabel, $cfg, $row);
        unset($nilai['id']);
        foreach (array_keys($cfg['tunda'] ?? []) as $kol) {
            $nilai[$kol] = null;
        }
        $idN = (int) $this->c->table($this->n($tabel))->insertGetId($nilai);
        $this->peta[$tabel][$idB] = $idN;
        foreach ($cfg['tunda'] ?? [] as $kol => $ke) {
            if ($row->{$kol} !== null) {
                $this->tundaan[] = [$tabel, $idN, $kol, $ke, (int) $row->{$kol}];
            }
        }
        $this->salinAnak($tabel, $cfg, $row, $idN, false);
        $this->catatTautan($tabel, $kunci, $idN, 'masuk');
        $this->tambahHasil($tabel, $cfg, $kunci, 'masuk', $row, null, null);
        // Pelunasan hanya ditambahkan ke tagihan yang SUDAH ada sebelum jalan ini. Tagihan yang baru
        // disalin (kontrak baru / baris tagihan baru) sudah membawa pelunasan dari eProperty.
        if (! empty($cfg['tambahPelunasan']) && ! empty($nilai['kontrak_tagihan_id'])
            && ! isset($this->anakBaru['fo_kontrak_tagihan'][(int) $nilai['kontrak_tagihan_id']])) {
            $this->c->table($this->n('fo_kontrak_tagihan'))->where('id', $nilai['kontrak_tagihan_id'])
                ->update(['pelunasan' => DB::raw('pelunasan + ' . (float) $row->amount)]);
        }
        if ($tabel === 'fo_kontrak') {
            $this->kontrakBaru[] = $idN;
        }

        return $idN;
    }

    private function perbaruiDokumen(string $tabel, int $idB, int $idN, string $kunci): void
    {
        $cfg = $this->cfg[$tabel];
        $rowB = $this->baris('b', $tabel, $idB);
        $rowN = $this->baris('n', $tabel, $idN);
        $baru = $this->petakanBaris($tabel, $cfg, $rowB);
        $ubah = [];
        foreach ($cfg['tunda'] ?? [] as $kol => $ke) {
            unset($baru[$kol]);
            if ($rowB->{$kol} !== null) {
                $this->tundaan[] = [$tabel, $idN, $kol, $ke, (int) $rowB->{$kol}];
            }
        }
        foreach ($baru as $kol => $v) {
            if (in_array($kol, DaftarTabel::SISTEM, true) || $kol === $cfg['kunci'] || in_array($kol, $cfg['jaga'] ?? [], true)) {
                continue;
            }
            if ($v === null && $rowN->{$kol} !== null) {
                continue;   // nilai kosong dari eProperty tidak menghapus isian J-ERP
            }
            if ((string) $v !== (string) $rowN->{$kol}) {
                $ubah[$kol] = $v;
            }
        }
        if ($ubah && $this->adaKolom('n', $tabel, 'updated_at')) {
            $ubah['updated_at'] = now();
        }
        if ($ubah) {
            $this->c->table($this->n($tabel))->where('id', $idN)->update($ubah);
        }
        $this->salinAnak($tabel, $cfg, $rowB, $idN, true);
        $this->catatTautan($tabel, $kunci, $idN, 'diperbarui');
        $this->tambahHasil($tabel, $cfg, $kunci, 'diperbarui', $rowB, $this->labelNyata($tabel, $cfg, $rowN),
            'Berubah: ' . (implode(', ', array_diff(array_keys($ubah), ['updated_at'])) ?: 'rincian baris'));
    }

    private function salinAnak(string $tabel, array $cfg, object $rowB, int $idN, bool $ganti): void
    {
        $idPembayaran = [];
        foreach ($cfg['anak'] ?? [] as $a) {
            $t = $a['tabel'];
            if (! $this->adaTabel('b', $t) || ! $this->adaTabel('n', $t)) {
                continue;
            }
            $qb = $this->c->table($this->b($t));
            $qn = $this->c->table($this->n($t));
            if ($a['fk']) {
                $qb->where($a['fk'], $rowB->id);
                $qn->where($a['fk'], $idN);
            } else {
                foreach ($a['pengikat'] as $kolAnak => $kolInduk) {
                    $qb->where($kolAnak, $rowB->{$kolInduk});
                    $qn->where($kolAnak, $rowB->{$kolInduk});
                }
            }
            $anakB = $qb->orderBy('id')->get();

            if ($a['kunci']) {
                // Dicocokkan di tempat (id baris J-ERP tetap — bisa dirujuk dokumen lain, mis. kwitansi → tagihan).
                $anakN = $qn->get()->keyBy(fn ($x) => implode('|', array_map(fn ($k) => (string) $x->{$k}, $a['kunci'])));
                $ada = [];
                foreach ($anakB as $x) {
                    $kk = implode('|', array_map(fn ($k) => (string) $x->{$k}, $a['kunci']));
                    $ada[$kk] = true;
                    $v = $this->petakanAnak($a, $x, $idN);
                    if (isset($anakN[$kk])) {
                        $set = [];
                        foreach ($v as $kol => $nil) {
                            if (! in_array($kol, DaftarTabel::SISTEM, true) && ! in_array($kol, $a['jaga'] ?? [], true)
                                && ! ($nil === null && $anakN[$kk]->{$kol} !== null) && (string) $nil !== (string) $anakN[$kk]->{$kol}) {
                                $set[$kol] = $nil;
                            }
                        }
                        if ($set) {
                            $this->c->table($this->n($t))->where('id', $anakN[$kk]->id)->update($set);
                        }
                        $this->peta[$t][$x->id] = (int) $anakN[$kk]->id;
                    } elseif (! empty($a['nominal']) && ($kembar = $this->anakInputJerp($t, $a, $idN, $x))) {
                        // Rincian yang sama (nominal sama) sudah diinput di J-ERP pada induk ini → tautkan, jangan dobel.
                        $this->peta[$t][$x->id] = (int) $kembar->id;
                        $this->hasil[] = ['modul' => ($this->cfg[$tabel]['modul'] ?? $tabel) . ' (rincian)', 'tabel' => $t, 'kunci' => $kk,
                            'aksi' => 'cocok', 'ringkas' => $this->cfg[$tabel]['modul'] . ' ' . $rowB->{$cfg['kunci']} . ' · ' . number_format((float) $x->{$a['nominal']}, 0, ',', '.'),
                            'jerp' => 'baris J-ERP #' . $kembar->id, 'alasan' => 'Rincian bernominal sama sudah diinput di J-ERP', 'nominal' => (float) $x->{$a['nominal']}, 'tanggal' => null];
                    } else {
                        unset($v['id']);
                        $this->peta[$t][$x->id] = (int) $this->c->table($this->n($t))->insertGetId($v);
                        $this->anakBaru[$t][$this->peta[$t][$x->id]] = true;
                    }
                }
                // Baris anak yang tidak ada lagi di eProperty dibiarkan (bisa sudah dirujuk transaksi J-ERP).
                continue;
            }

            if ($ganti) {
                $lama = $this->kanonikDaftar('n', $a, $qn->orderBy('id')->get());
                $baru = $this->kanonikDaftar('b', $a, $anakB);
                if ($lama === $baru) {
                    foreach ($anakB as $i => $x) {
                        $this->peta[$t][$x->id] = null;
                    }
                    continue;
                }
                (clone $qn)->delete();
            }
            foreach ($anakB as $x) {
                $v = $this->petakanAnak($a, $x, $idN);
                if (! empty($a['refPembayaran'])) {
                    $v[$a['refPembayaran']] = $idPembayaran[$x->{$a['refPembayaran']}] ?? null;
                }
                unset($v['id']);
                $baruId = (int) $this->c->table($this->n($t))->insertGetId($v);
                $this->peta[$t][$x->id] = $baruId;
                $this->anakBaru[$t][$baruId] = true;
                if ($t === 'bs_ap_payments') {
                    $idPembayaran[$x->id] = $baruId;
                }
            }
        }
    }

    /** Baris rincian J-ERP (dibuat sesudah batas) di induk yang sama, bernominal sama, yang belum dipakai. */
    private function anakInputJerp(string $t, array $a, int $idInduk, object $x): ?object
    {
        static $dipakai = [];
        $q = $this->c->table($this->n($t))->where($a['fk'], $idInduk)
            ->whereRaw('ABS(' . $a['nominal'] . ' - ?) < 1', [(float) $x->{$a['nominal']}]);
        if ($this->adaKolom('n', $t, 'created_at')) {
            $q->where('created_at', '>=', $this->batas);
        }
        if ($a['kunci'] ?? null) {
            // baris yang kuncinya sama sudah ditangani sebagai baris yang sama
            $q->whereRaw('CONCAT_WS(\'|\', ' . implode(', ', array_map(fn ($k) => "IFNULL(`$k`, '')", $a['kunci'])) . ') <> ?',
                [implode('|', array_map(fn ($k) => (string) $x->{$k}, $a['kunci']))]);
        }
        foreach ($q->orderBy('id')->get() as $c) {
            if (! isset($dipakai[$t][$c->id])) {
                $dipakai[$t][$c->id] = true;

                return $c;
            }
        }

        return null;
    }

    private function petakanAnak(array $a, object $x, int $idInduk): array
    {
        $v = (array) $x;
        if ($a['fk']) {
            $v[$a['fk']] = $idInduk;
        }
        foreach ($a['ref'] ?? [] as $kol => $ke) {
            $v[$kol] = $this->idNyataRef($ke, $x->{$kol}, true);
        }
        foreach ($a['master'] ?? [] as $kol => $ke) {
            $v[$kol] = $this->pastikanMaster($ke, $x->{$kol});
        }

        return $v;
    }

    private function petakanBaris(string $tabel, array $cfg, object $row): array
    {
        $v = (array) $row;
        foreach ($cfg['ref'] ?? [] as $kol => $ke) {
            $v[$kol] = $this->idNyataRef($ke, $row->{$kol}, true);
        }
        foreach ($cfg['refPoli'] ?? [] as $kol => [$kolJenis, $peta]) {
            if (isset($peta[$row->{$kolJenis} ?? ''])) {
                $v[$kol] = $this->idNyataRef($peta[$row->{$kolJenis}], $row->{$kol}, true);
            }
        }
        foreach ($cfg['refAnak'] ?? [] as $kol => [$tabelAnak, $kolInduk]) {
            $v[$kol] = $this->idNyataAnak($tabelAnak, $row->{$kol}, $kolInduk, $v[$kolInduk] ?? null);
        }
        foreach ($cfg['master'] ?? [] as $kol => $ke) {
            $v[$kol] = $this->pastikanMaster($ke, $row->{$kol});
        }

        return $v;
    }

    /** id dokumen bayangan → id J-ERP. $salin = salin dokumen itu bila belum ada (dan tidak dilewati). */
    private function idNyataRef(string $tabel, $idB, bool $salin = false): ?int
    {
        if ($idB !== null && ! isset($this->cfg[$tabel]) && ($info = $this->infoAnak($tabel))) {
            // Rujukan ke BARIS ANAK (mis. progress SPK): induk dipetakan dulu, lalu dicari kunci anaknya.
            [$induk, $a] = $info;
            $x = $this->baris('b', $tabel, (int) $idB);
            if (! $x || ! $a['kunci']) {
                return null;
            }
            $idIndukN = $this->idNyataRef($induk, $x->{$a['fk']}, $salin);
            if (! $idIndukN) {
                return null;
            }
            $q = $this->c->table($this->n($tabel))->where($a['fk'], $idIndukN);
            foreach ($a['kunci'] as $k) {
                $x->{$k} === null ? $q->whereNull($k) : $q->where($k, $x->{$k});
            }
            $id = $q->value('id');

            return $id ? (int) $id : null;
        }
        if ($idB === null || ! isset($this->cfg[$tabel])) {
            return $idB === null ? null : (int) $idB;
        }
        $idB = (int) $idB;
        if (array_key_exists($idB, $this->peta[$tabel] ?? [])) {
            return $this->peta[$tabel][$idB];
        }
        $row = $this->baris('b', $tabel, $idB);
        if (! $row) {
            return null;
        }
        $kunci = (string) $row->{$this->cfg[$tabel]['kunci']};
        $idN = $this->c->table($this->n($tabel))->where($this->cfg[$tabel]['kunci'], $kunci)->value('id');
        if ($idN) {
            return $this->peta[$tabel][$idB] = (int) $idN;
        }
        if (isset($this->tautan[$tabel][$kunci][0])) {
            return $this->peta[$tabel][$idB] = (int) $this->tautan[$tabel][$kunci][0];
        }
        if (! $salin || isset($this->lewatiNomor[$kunci])) {
            return null;
        }

        return $this->salinDokumen($tabel, $idB);
    }

    /** id baris anak bayangan (mis. tagihan) → id J-ERP lewat induk + kunci anak. */
    private function idNyataAnak(string $tabelAnak, $idB, string $kolInduk, ?int $idIndukN): ?int
    {
        if ($idB === null || ! $idIndukN) {
            return null;
        }
        if (isset($this->peta[$tabelAnak][(int) $idB])) {
            return $this->peta[$tabelAnak][(int) $idB];
        }
        $x = $this->baris('b', $tabelAnak, (int) $idB);
        if (! $x) {
            return null;
        }
        $id = $this->c->table($this->n($tabelAnak))->where($kolInduk, $idIndukN)->where('urutan', $x->urutan)->value('id');

        return $id ? (int) $id : null;
    }

    /** Master: id sama di bayangan & J-ERP. Belum ada di J-ERP → salin barisnya apa adanya. */
    private function pastikanMaster(string $tabel, $id): ?int
    {
        if ($id === null || $id === '' || ! $this->adaTabel('n', $tabel)) {
            return $id === null || $id === '' ? null : (int) $id;
        }
        $id = (int) $id;
        if (isset($this->masterDisalin[$tabel][$id]) || $this->c->table($this->n($tabel))->where('id', $id)->exists()) {
            return $id;
        }
        $row = $this->adaTabel('b', $tabel) ? $this->baris('b', $tabel, $id) : null;
        if (! $row) {
            return null;
        }
        $v = (array) $row;
        foreach ($this->cfgMaster[$tabel]['master'] ?? [] as $kol => $ke) {
            $v[$kol] = $this->pastikanMaster($ke, $row->{$kol});
        }
        $this->c->table($this->n($tabel))->insert($v);
        $this->masterDisalin[$tabel][$id] = true;
        $this->hasil[] = ['modul' => 'Master', 'tabel' => $tabel, 'kunci' => (string) $id, 'aksi' => 'masuk',
            'ringkas' => mb_substr(json_encode(array_intersect_key($v, array_flip(['code', 'kode', 'name', 'nama', 'kode_unit', 'booking_number']))), 0, 500),
            'jerp' => null, 'alasan' => 'Master baru dari eProperty', 'nominal' => null, 'tanggal' => null];

        return $id;
    }

    private function isiTundaan(): void
    {
        foreach ($this->tundaan as [$tabel, $idN, $kol, $ke, $idB]) {
            $nilai = $this->idNyataRef($ke, $idB, false);
            if ($nilai) {
                $this->c->table($this->n($tabel))->where('id', $idN)->update([$kol => $nilai]);
            }
        }
    }

    // ═════════════════════════════ perbandingan ═════════════════════════════

    private function kanonik(string $sisi, string $tabel, array $cfg, object $row): array
    {
        $v = [];
        foreach ((array) $row as $kol => $nil) {
            if (in_array($kol, DaftarTabel::SISTEM, true) || in_array($kol, $cfg['jaga'] ?? [], true) || isset($cfg['tunda'][$kol])) {
                continue;
            }
            if (isset($cfg['ref'][$kol])) {
                $nil = $this->kunciDari($sisi, $cfg['ref'][$kol], $nil);
            } elseif (isset($cfg['refPoli'][$kol])) {
                [$kolJenis, $peta] = $cfg['refPoli'][$kol];
                $nil = isset($peta[$row->{$kolJenis} ?? '']) ? $this->kunciDari($sisi, $peta[$row->{$kolJenis}], $nil) : $nil;
            } elseif (isset($cfg['refAnak'][$kol])) {
                $nil = $nil === null ? null : (string) $this->c->table($this->t($sisi, $cfg['refAnak'][$kol][0]))->where('id', $nil)->value('urutan');
            }
            $v[$kol] = $this->normNilai($nil);
        }
        foreach ($cfg['anak'] ?? [] as $a) {
            if (! $this->adaTabel($sisi, $a['tabel'])) {
                continue;
            }
            $q = $this->c->table($this->t($sisi, $a['tabel']));
            if ($a['fk']) {
                $q->where($a['fk'], $row->id);
            } else {
                foreach ($a['pengikat'] as $kolAnak => $kolInduk) {
                    $q->where($kolAnak, $row->{$kolInduk});
                }
            }
            $v['@' . $a['tabel']] = $this->kanonikDaftar($sisi, $a, $q->orderBy('id')->get());
        }

        return $v;
    }

    private function kanonikDaftar(string $sisi, array $a, $rows): array
    {
        $out = [];
        foreach ($rows as $x) {
            $v = [];
            foreach ((array) $x as $kol => $nil) {
                if (in_array($kol, DaftarTabel::SISTEM, true) || $kol === $a['fk'] || in_array($kol, $a['jaga'] ?? [], true)
                    || $kol === ($a['refPembayaran'] ?? null)) {
                    continue;
                }
                if (isset($a['ref'][$kol])) {
                    $nil = $this->kunciDari($sisi, $a['ref'][$kol], $nil);
                }
                $v[$kol] = $this->normNilai($nil);
            }
            ksort($v);
            $out[] = json_encode($v);
        }
        sort($out);

        return $out;
    }

    /** @return array{0:string,1:array}|null [tabel induk, konfigurasi anak] bila $tabel adalah baris anak berkunci. */
    private function infoAnak(string $tabel): ?array
    {
        foreach ($this->cfg as $induk => $cfg) {
            foreach ($cfg['anak'] ?? [] as $a) {
                if ($a['tabel'] === $tabel && $a['fk'] && $a['kunci']) {
                    return [$induk, $a];
                }
            }
        }

        return null;
    }

    private function kunciDari(string $sisi, string $tabel, $id): ?string
    {
        if ($id !== null && ! isset($this->cfg[$tabel]) && ($info = $this->infoAnak($tabel))) {
            [$induk, $a] = $info;
            $x = $this->c->table($this->t($sisi, $tabel))->where('id', $id)->first();

            return $x ? $this->kunciDari($sisi, $induk, $x->{$a['fk']}) . '|' . implode('|', array_map(fn ($k) => (string) $x->{$k}, $a['kunci'])) : null;
        }
        if ($id === null || ! isset($this->cfg[$tabel])) {
            return $id === null ? null : (string) $id;
        }
        // Dokumen J-ERP yang DITAUTKAN ke dokumen eProperty (mis. customer CUST-2026-00107 ↔ 2075)
        // dikenali dengan nomor eProperty-nya. Tanpa ini setiap dokumen yang merujuknya selalu
        // tampak "berubah" (kodenya memang beda) dan diperbarui ulang pada setiap sinkron.
        if ($sisi === 'n' && isset($this->tautanBalik[$tabel][(int) $id])) {
            return $this->tautanBalik[$tabel][(int) $id];
        }
        static $cache = [];
        $ck = $sisi . $tabel . ':' . $id;

        return $cache[$ck] ??= (string) $this->c->table($this->t($sisi, $tabel))->where('id', $id)->value($this->cfg[$tabel]['kunci']);
    }

    private function normNilai($v)
    {
        if ($v === null) {
            return null;
        }
        if (is_numeric($v)) {
            return rtrim(rtrim(number_format((float) $v, 4, '.', ''), '0'), '.');
        }

        return (string) $v;
    }

    private function samaKanonik(array $a, array $b): bool
    {
        foreach ($a as $k => $v) {
            // Nilai kosong di eProperty tidak dianggap perubahan atas isian J-ERP.
            if ($v === null && ($b[$k] ?? null) !== null) {
                continue;
            }
            if (($b[$k] ?? null) !== $v) {
                return false;
            }
        }

        return true;
    }

    private function bedaKolom(array $a, array $b): array
    {
        $beda = [];
        foreach ($a as $k => $v) {
            if (! ($v === null && ($b[$k] ?? null) !== null) && ($b[$k] ?? null) !== $v) {
                $beda[] = ltrim($k, '@');
            }
        }

        return array_slice($beda, 0, 8);
    }

    // ═════════════════════════════ laporan ═════════════════════════════

    private function tambahHasil(string $tabel, array $cfg, string $kunci, string $aksi, ?object $row, ?string $jerp, ?string $alasan = null): void
    {
        $m = $cfg['cocok'] ?? [];
        $this->hasil[] = [
            'modul' => $cfg['modul'], 'tabel' => $tabel, 'kunci' => $kunci, 'aksi' => $aksi,
            'ringkas' => $row ? $this->label('b', $tabel, $cfg, $row) : null,
            'jerp' => $jerp ? mb_substr($jerp, 0, 500) : null,
            'alasan' => $alasan ? mb_substr($alasan, 0, 500) : null,
            'nominal' => $row && isset($m['nominal']) ? (float) $row->{$m['nominal']} : null,
            'tanggal' => $row && isset($m['tanggal']) && $row->{$m['tanggal']} ? substr((string) $row->{$m['tanggal']}, 0, 10) : null,
        ];
    }

    private function label(string $sisi, string $tabel, array $cfg, object $row): string
    {
        $bagian = [];
        foreach ($cfg['label'] ?? [] as $kol) {
            $v = $row->{$kol} ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            if (isset($cfg['master'][$kol])) {
                $v = $this->namaMaster($sisi, $cfg['master'][$kol], $v) ?? $v;
            } elseif (isset($cfg['ref'][$kol])) {
                $v = $this->kunciDari($sisi, $cfg['ref'][$kol], $v) ?? $v;
            } elseif (is_numeric($v) && str_contains((string) $v, '.')) {
                $v = number_format((float) $v, 0, ',', '.');
            }
            $bagian[] = mb_substr(preg_replace('/\s+/', ' ', (string) $v), 0, 140);
        }

        return mb_substr(implode(' · ', $bagian), 0, 500);
    }

    private function labelNyata(string $tabel, array $cfg, object $row): string
    {
        return $row->{$cfg['kunci']} . ' — ' . $this->label('n', $tabel, $cfg, $row);
    }

    private function namaMaster(string $sisi, string $tabel, $id): ?string
    {
        foreach (['kode_unit', 'booking_number', 'name', 'nama', 'code'] as $kol) {
            if ($this->adaKolom($sisi, $tabel, $kol)) {
                $v = $this->c->table($this->t($sisi, $tabel))->where('id', $id)->value($kol);

                return $v === null ? null : (string) $v;
            }
        }

        return null;
    }

    private function ringkas(): array
    {
        $r = [];
        foreach ($this->hasil as $h) {
            $r[$h['modul']][$h['aksi']] = ($r[$h['modul']][$h['aksi']] ?? 0) + 1;
        }

        return $r;
    }

    private function simpanHasil(): void
    {
        $now = now();
        foreach (array_chunk($this->hasil, 500) as $potong) {
            DB::table('eprop_hasil')->insert(array_map(fn ($h) => $h + ['jalan_id' => $this->jalanId, 'created_at' => $now, 'updated_at' => $now], $potong));
        }
    }

    // ═════════════════════════════ tautan ═════════════════════════════

    private function muatTautan(): void
    {
        foreach (DB::table('eprop_tautan')->get() as $t) {
            $this->tautan[$t->tabel][$t->kunci] = [$t->id_jerp, $t->status, $t->disinkron_pada];
            if ($t->id_jerp) {
                $this->tautanBalik[$t->tabel][(int) $t->id_jerp] = (string) $t->kunci;
            }
        }
    }

    private function catatTautan(string $tabel, string $kunci, int $idJerp, string $status): void
    {
        DB::table('eprop_tautan')->updateOrInsert(['tabel' => $tabel, 'kunci' => $kunci],
            ['id_jerp' => $idJerp, 'status' => $status, 'disinkron_pada' => now(), 'updated_at' => now(), 'created_at' => now()]);
        $this->tautan[$tabel][$kunci] = [$idJerp, $status, (string) now()];
        $this->tautanBalik[$tabel][$idJerp] = $kunci;
    }

    // ═════════════════════════════ akses tabel ═════════════════════════════

    private function kunciKeId(string $sisi, string $tabel, string $kunci): array
    {
        $out = [];
        foreach ($this->c->table($this->t($sisi, $tabel))->whereNotNull($kunci)->select(['id', $kunci])->orderBy('id')->cursor() as $r) {
            $out[(string) $r->{$kunci}] ??= (int) $r->id;
        }

        return $out;
    }

    private function baris(string $sisi, string $tabel, int $id): ?object
    {
        return $this->c->table($this->t($sisi, $tabel))->where('id', $id)->first();
    }

    private function t(string $sisi, string $tabel): string
    {
        $db = match (($this->cfg[$tabel]['db'] ?? $this->cfgMaster[$tabel]['db'] ?? $this->dbAnak($tabel)) . $sisi) {
            'fon' => $this->db['fo'], 'bon' => $this->db['bo'], 'fob' => $this->db['bfo'], 'bob' => $this->db['bbo'],
            default => throw new RuntimeException('Tabel tidak dikenal: ' . $tabel),
        };

        return $db . '.' . $tabel;
    }

    private function n(string $tabel): string
    {
        return $this->t('n', $tabel);
    }

    private function b(string $tabel): string
    {
        return $this->t('b', $tabel);
    }

    private function dbAnak(string $tabel): string
    {
        foreach ($this->cfg as $cfg) {
            foreach ($cfg['anak'] ?? [] as $a) {
                if ($a['tabel'] === $tabel) {
                    return $cfg['db'];
                }
            }
        }

        return str_starts_with($tabel, 'fo_') ? 'fo' : 'bo';
    }

    private function adaTabel(string $sisi, string $tabel): bool
    {
        static $cache = [];
        $nama = $this->t($sisi, $tabel);
        [$db, $tb] = explode('.', $nama);

        return $cache[$nama] ??= DB::table('information_schema.tables')->where('table_schema', $db)->where('table_name', $tb)->exists();
    }

    private function adaKolom(string $sisi, string $tabel, string $kolom): bool
    {
        static $cache = [];
        $nama = $this->t($sisi, $tabel);
        [$db, $tb] = explode('.', $nama);
        $cache[$nama] ??= DB::table('information_schema.columns')->where('table_schema', $db)->where('table_name', $tb)->selectRaw('COLUMN_NAME AS c')->pluck('c')->flip()->all();

        return isset($cache[$nama][$kolom]);
    }
}
