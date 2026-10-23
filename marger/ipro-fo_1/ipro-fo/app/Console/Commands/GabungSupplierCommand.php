<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gabungkan supplier DOBEL ke baris yang LAMA (23 Okt 2026, keputusan Yogi: "jadikan 1, ikut
 * yang lama").
 *
 * Dobel = nama sama persis (abaikan huruf besar/kecil & spasi ganda) — mis. supplier lama dari
 * eProperty (SP.xxx) + baris penerima Reimburse/Deposit (KRY-/CST-) untuk orang yang sama, atau
 * input ganda. Yang dipertahankan: yang dibuat paling awal. Pasangan yang namanya tidak persis
 * sama (nama pendek vs lengkap) diberikan sendiri: --pasangan=ID_LAMA:ID_DOBEL.
 *
 * Semua kolom supplier_id di database (dibaca dari skema — tabel baru ikut tercakup) dipindah ke
 * baris lama; rekening yang kosong di baris lama diisi dari baris dobel; baris dobel dinonaktifkan
 * & dihapus lunak (jejaknya tetap). Satu transaksi. Tanpa --jalankan hanya laporan.
 */
class GabungSupplierCommand extends Command
{
    protected $signature = 'ap:gabung-supplier
        {--jalankan : terapkan (tanpa ini hanya laporan)}
        {--pasangan=* : pasangan manual ID_LAMA:ID_DOBEL (boleh diulang)}';

    protected $description = 'Gabungkan supplier dobel (nama sama) ke baris yang lama';

    private const REKENING = ['bank_name', 'bank_account_no', 'bank_account_name'];

    public function handle(): int
    {
        $jalankan = (bool) $this->option('jalankan');
        $sup = DB::table('bs_suppliers')->whereNull('deleted_at')->orderBy('created_at')->orderBy('id')->get()->keyBy('id');
        $jmlInvoice = DB::table('bs_ap_invoices')->selectRaw('supplier_id, COUNT(*) n')->groupBy('supplier_id')->pluck('n', 'supplier_id');

        // pasangan: id_dobel => id_lama
        // Baris CUSTOMER (CST-) tidak ikut otomatis — nama customer sering kembar dengan orang lain
        // (customer "IRFAN PRASETYA" ≠ karyawan Irfan Prasetya); gabungkan lewat --pasangan.
        $pasangan = [];
        $otomatis = $sup->filter(fn ($s) => $s->category !== 'customer');
        foreach ($otomatis->groupBy(fn ($s) => mb_strtoupper(trim(preg_replace('/\s+/', ' ', (string) $s->name) ?? ''))) as $nama => $grup) {
            if ($nama === '' || $grup->count() < 2) {
                continue;
            }
            // Yang lama = dibuat paling awal; bila sama (mis. dimuat bersamaan saat migrasi) →
            // yang dokumennya paling banyak, supaya yang dipindah sesedikit mungkin.
            $lama = $grup->sortBy(fn ($s) => [(string) $s->created_at, -($jmlInvoice[$s->id] ?? 0), $s->id])->first();
            $grup = $grup->reject(fn ($s) => $s->id === $lama->id)->prepend($lama);
            foreach ($grup->slice(1) as $d) {
                $pasangan[$d->id] = $lama->id;
            }
        }
        foreach ((array) $this->option('pasangan') as $p) {
            [$lama, $dobel] = array_map('intval', explode(':', (string) $p) + [1 => 0]);
            if (! isset($sup[$lama], $sup[$dobel]) || $lama === $dobel) {
                $this->error("Pasangan tidak valid: {$p}");

                return self::FAILURE;
            }
            $pasangan[$dobel] = $lama;
        }
        // Yang SUDAH digabung sebelumnya: dokumen baru (mis. dari sinkron eProperty) yang masih
        // menunjuk ke sana ikut dipindah.
        $sudah = DB::table('bs_suppliers')->whereNotNull('digabung_ke_id')->get()->keyBy('id');
        foreach ($sudah as $d) {
            $pasangan[$d->id] ??= (int) $d->digabung_ke_id;
        }
        $sup = $sup->union($sudah)->union(DB::table('bs_suppliers')->whereIn('id', $sudah->pluck('digabung_ke_id'))->get()->keyBy('id'));
        // rantai (A→B, B→C) diluruskan ke ujungnya
        foreach ($pasangan as $d => $l) {
            while (isset($pasangan[$l])) {
                $l = $pasangan[$l];
            }
            $pasangan[$d] = $l;
        }
        $kolom = collect(DB::select("SELECT table_name t, column_name c FROM information_schema.columns
                WHERE table_schema = DATABASE() AND column_name = 'supplier_id' AND table_name <> 'bs_suppliers'
                UNION SELECT table_name, column_name FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE() AND referenced_table_name = 'bs_suppliers'"))
            ->map(fn ($r) => [(string) ($r->t ?? $r->TABLE_NAME), (string) ($r->c ?? $r->COLUMN_NAME)])->unique()->values();

        $baris = [];
        foreach ($pasangan as $d => $l) {
            $jml = $kolom->mapWithKeys(fn ($k) => [$k[0] => DB::table($k[0])->where($k[1], $d)->count()])->filter();
            if (isset($sudah[$d]) && $jml->isEmpty()) {
                unset($pasangan[$d]);   // sudah digabung & tidak ada rujukan baru

                continue;
            }
            $baris[] = [$sup[$l]->code.' '.$sup[$l]->name, $sup[$d]->code.' '.$sup[$d]->name,
                $jml->map(fn ($n, $t) => str_replace('bs_', '', $t).' '.$n)->implode(', ') ?: '-'];
        }
        if (! $pasangan) {
            $this->info('Tidak ada supplier dobel.');

            return self::SUCCESS;
        }
        $this->table(['Dipertahankan (lama)', 'Digabung (dobel)', 'Dokumen yang dipindah'], $baris);

        if (! $jalankan) {
            $this->line(count($pasangan).' supplier dobel akan digabung. <comment>Mode laporan — jalankan ulang dengan --jalankan untuk menerapkan.</comment>');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($pasangan, $kolom, $sup): void {
            foreach ($pasangan as $d => $l) {
                foreach ($kolom as [$t, $c]) {
                    DB::table($t)->where($c, $d)->update([$c => $l]);
                }
                $isi = [];
                foreach (self::REKENING as $r) {
                    if (blank($sup[$l]->{$r} ?? null) && filled($sup[$d]->{$r} ?? null)) {
                        $isi[$r] = $sup[$d]->{$r};
                    }
                }
                if ($sup[$d]->is_active && ! $sup[$l]->is_active) {
                    $isi['is_active'] = true;   // dokumennya pindah ke sini — baris lama harus bisa dipilih
                }
                if ($isi) {
                    DB::table('bs_suppliers')->where('id', $l)->update($isi);
                }
                DB::table('bs_suppliers')->where('id', $d)->update(['is_active' => false, 'deleted_at' => now(), 'digabung_ke_id' => $l]);
            }
        });
        $this->info(count($pasangan).' supplier dobel digabung ke baris lama.');

        return self::SUCCESS;
    }
}
