<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

/**
 * Hitungan Nilai Transaksi → PPN → PPh → Dibayar (net) untuk hutang: dipakai Registrasi Invoice
 * Hutang (RegisterApInvoice) dan Memo Urgent (nominal memo = net), supaya keduanya tidak pernah
 * berbeda. Rumus pembulatan sama dengan Payable/Form.vue — lihat catatan di RegisterApInvoice.
 */
final class PajakHutang
{
    /**
     * @param  array{dpp:float|int|string|null, ppn_mode?:?string, ppn_pct?:mixed, ppn_base?:mixed, ppn_incl?:mixed,
     *               pph_mode?:?string, pph_type?:?string, pph_pct?:mixed, pph_base?:mixed}  $d
     * @return array{dpp:float, ppn_pct:float, ppn_base:float, ppn:float, incl:bool, total:float,
     *               pph_pct:float, pph_base:float, pph_journal:float, pph:float, net:float}
     */
    public static function hitung(array $d): array
    {
        $pphMode = $d['pph_mode'] ?? 'none';
        $pphOn = in_array($pphMode, ['withheld', 'borne'], true);

        $dpp = round((float) ($d['dpp'] ?? 0));
        $ppnPct = ($d['ppn_mode'] ?? 'none') === 'with' ? round((float) ($d['ppn_pct'] ?? 11), 2) : 0.0;
        // PPN dari NILAI OBJEK PPN (ppn_base), bukan seluruh DPP. Default = seluruh DPP; clamp ke DPP.
        $ppnBase = 0.0;
        if (($d['ppn_mode'] ?? 'none') === 'with') {
            $ppnBase = round((float) ($d['ppn_base'] ?? 0));
            if ($ppnBase <= 0) {
                $ppnBase = $dpp;
            }
            $ppnBase = min($ppnBase, $dpp);
        }

        /* PEMBULATAN PPN: desimal tepat 0,5 ke BAWAH, di atasnya ke atas — ceil(v − 0,5):
           550.000,50 → 550.000 · 550.000,51 → 550.001 (round() PHP membulatkan 0,5 ke atas).

           INCLUDE PPN — nilai yang dikirim adalah TOTAL, bukan DPP. PPN diambil dari DALAM total
           (tarif/(100+tarif)) lalu DPP = total − PPN, sehingga penjumlahannya kembali ke angka
           yang diketik menurut definisi (÷1,11 lalu ×11% meleset Rp 1 pada ±1/10 nilai). Mode ini
           tidak disimpan di invoice; yang tersimpan hanya hasilnya (dpp, ppn, total). */
        $incl = $ppnPct > 0 && (bool) ($d['ppn_incl'] ?? false);
        if ($incl) {
            $ppn = ceil($ppnBase * $ppnPct / (100 + $ppnPct) - 0.5);
            $dpp = $dpp - $ppn;
            $total = $dpp + $ppn;
        } else {
            $ppn = ceil($ppnBase * $ppnPct / 100 - 0.5);
            $total = round($dpp + $ppn);
        }

        // PPh dari NILAI OBJEK PPh (pph_base). Default = seluruh DPP; clamp ke DPP.
        $pphPct = $pphOn ? round((float) ($d['pph_pct'] ?? 0), 2) : 0.0;
        $pphBase = 0.0;
        if ($pphOn) {
            $pphBase = round((float) ($d['pph_base'] ?? 0));
            if ($pphBase <= 0) {
                $pphBase = $dpp;
            }
            $pphBase = min($pphBase, $dpp);
        }
        // PPh Pasal 4(2) → rounddown (floor); jenis lain → round. DITANGGUNG → tampil/tersimpan 0
        // (supplier dibayar penuh, net = total); jurnal tetap memakai pph_journal.
        $pphRaw = $pphBase * $pphPct / 100;
        $pphJournal = ($d['pph_type'] ?? null) === 'pph4_2' ? floor($pphRaw) : round($pphRaw);
        $pph = $pphMode === 'borne' ? 0.0 : $pphJournal;
        $net = $pphMode === 'borne' ? $total : round($total - $pph);

        return [
            'dpp' => $dpp, 'ppn_pct' => $ppnPct, 'ppn_base' => $ppnBase, 'ppn' => $ppn, 'incl' => $incl, 'total' => $total,
            'pph_pct' => $pphPct, 'pph_base' => $pphBase, 'pph_journal' => $pphJournal, 'pph' => $pph, 'net' => $net,
        ];
    }
}
