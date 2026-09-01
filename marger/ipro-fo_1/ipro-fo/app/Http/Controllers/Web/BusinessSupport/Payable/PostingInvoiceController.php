<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use App\Domain\BusinessSupport\Models\BsKprBiayaEntry;
use App\Domain\BusinessSupport\Models\BsPurchaseOrder;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Models\BsWorkOrder;
use App\Domain\BusinessSupport\Models\BsWorkOrderProgress;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Posting Invoice — terbitkan invoice hutang (AP) dari sumber PO (penerimaan)
 * atau SPK (progress). Tidak men-jurnal ulang: hutang sudah dibukukan oleh
 * penerimaan barang / progress; invoice menjadi dokumen tagihan yang dibayar via Kuitansi.
 */
class PostingInvoiceController extends Controller
{
    /**
     * Sub modul "Posting Invoice dari FO" — HANYA antrean FO
     * (refund, BLT, komisi, gimmick). Purchase Order dan progress SPK punya
     * sub modulnya sendiri; ketiganya sengaja tidak lagi berbagi satu layar
     * karena alur, kolom, dan aturan posting-nya berbeda:
     * hanya antrean FO yang bisa dicentang untuk digabung jadi satu invoice.
     */
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        return Inertia::render('BusinessSupport/Payable/Posting', [
            'refunds'   => $this->antreanFo(),
            'suppliers' => $this->daftarSupplier(),
        ]);
    }

    /** Sub modul "Posting Invoice dari Purchase Order" — HANYA PO yang barangnya sudah diterima. */
    public function poIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        return Inertia::render('BusinessSupport/Payable/PostingPo', [
            'pos' => $this->antreanPo(),
        ]);
    }

    /** Sub modul "Posting Invoice SPK" — HANYA progress SPK kontraktor yang belum dibayar. */
    public function spkIndex(Request $request): Response
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        return Inertia::render('BusinessSupport/Payable/PostingSpk', [
            'progress' => $this->antreanSpk(),
        ]);
    }

    /**
     * PO siap diinvoice: berstatus disetujui/tutup, belum pernah diinvoice, dan
     * barangnya benar-benar sudah diterima (nilai penerimaan > 0).
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function antreanPo(): \Illuminate\Support\Collection
    {
        $invoicedPo = BsApInvoice::where('source_type', 'po')->pluck('source_id')->all();

        return BsPurchaseOrder::whereIn('status', ['approved', 'closed'])->whereNotIn('id', $invoicedPo)
            ->with(['supplier:id,code,name', 'lines'])->latest('po_date')->limit(100)->get()
            ->map(function ($po) {
                // Uraian singkat sejajar kolom "Uraian" pada kartu Progress SPK: berapa baris
                // barang yang sudah diterima dari seluruh baris PO — itulah yang menentukan
                // nilai yang boleh diinvoice. Catatan PO dipakai bila memang diisi.
                $diterima = $po->lines->filter(fn ($l) => (float) $l->qty_received > 0)->count();
                $catatan  = trim((string) ($po->notes ?? ''));

                return [
                    'id' => $po->id, 'po_number' => $po->po_number, 'supplier' => $po->supplier?->name,
                    'po_date' => $po->po_date?->toDateString(),
                    'uraian' => $catatan !== '' ? $catatan : $diterima.' dari '.$po->lines->count().' baris barang diterima',
                    'received_value' => round((float) $po->lines->sum(fn ($l) => (float) $l->qty_received * (float) $l->unit_price), 2),
                ];
            })->filter(fn ($p) => $p['received_value'] > 0.009)->values();
    }

    /**
     * Progress SPK siap diinvoice: belum dibayar dan belum pernah diinvoice.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function antreanSpk(): \Illuminate\Support\Collection
    {
        $invoicedSpk = BsApInvoice::where('source_type', 'spk')->pluck('source_id')->all();

        return BsWorkOrderProgress::where('status', 'unpaid')->whereNotIn('id', $invoicedSpk)
            ->whereHas('workOrder')->with('workOrder.supplier:id,code,name')->latest('progress_date')->limit(100)->get()
            ->map(fn ($p) => [
                'id' => $p->id, 'spk_number' => $p->workOrder?->spk_number, 'supplier' => $p->workOrder?->supplier?->name,
                'description' => $p->description, 'net_payable' => (float) $p->net_payable,
            ])->values();
    }

    /**
     * Antrian posting FO (J-ERP) — DITAMPUNG (pending) SEBELUM jadi hutang.
     * Klik "Posting Invoice" → terbit hutang AP. Badge per baris = SUMBER:
     * refund (per jenis SPR/SPRS/Transfer Anonim), Komisi, BLT, atau GMC.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function antreanFo(): \Illuminate\Support\Collection
    {
        $refundLabel = ['SPR' => 'SPR', 'SPRS' => 'SPRS', 'TRANSFER_ANONIM' => 'Transfer Anonim'];
        $queue = DB::table('bs_ap_refund_queue')->where('status', 'pending')
            ->orderByDesc('id')->limit(300)->get();

        // Nama kategori gimmick & nomor SPR untuk ditampilkan di antrian.
        $catNames = BsGimmickCategory::whereIn('id', $queue->pluck('gimmick_category_id')->filter()->unique()->all())
            ->pluck('code', 'id');
        $bookingNos = DB::table('bookings')->whereIn('id', $queue->pluck('booking_id')->filter()->unique()->all())
            ->pluck('booking_number', 'id');
        // Cluster yang dipotret FO saat baris masuk antrean. Sejak Kategori Gimmick bercluster,
        // dua kategori boleh bernama sama dengan akun berbeda — tanpa menyebut clusternya, orang
        // yang memposting tidak punya cara melihat kategori mana yang sebenarnya terpakai.
        $clusterNames = DB::table('clusters')
            ->whereIn('id', $queue->pluck('cluster_id')->filter()->unique()->all())
            ->pluck('code', 'id');

        return $queue->map(fn ($r) => [
            'id'           => $r->id,
            'refund_no'    => $r->refund_no,
            'customer'     => $r->customer_name,
            'sumber_label' => match ($r->kind) {
                'refund' => 'Refund: '.($refundLabel[$r->refund_source] ?? (string) $r->refund_source),
                'komisi' => 'Komisi',
                'blt'    => 'BLT',
                'gmc'    => 'GMC',
                default  => (string) $r->kind,
            },
            'amount'       => (float) $r->amount,
            'tgl'          => $r->tgl_refund,
            'keterangan'   => $r->keterangan, // mis. GMC → nama gimmick ("Gimmick BPHTB — SPR ...")
            'supplier_id'  => $r->supplier_id ? (int) $r->supplier_id : null, // GMC komponen → prefill supplier
            // Rantai GMC: kategori gimmick → kolom Rincian Biaya Master Data KPR → SPR yang dibiayai.
            'kategori'     => ($r->gimmick_category_id ?? null) ? ($catNames[$r->gimmick_category_id] ?? null) : null,
            'cluster'      => ($r->cluster_id ?? null) ? ($clusterNames[$r->cluster_id] ?? null) : null,
            'biaya_label'  => ($r->kpr_biaya_key ?? null) ? (BsGimmickCategory::KPR_BIAYA_COLUMNS[$r->kpr_biaya_key] ?? null) : null,
            'spr'          => ($r->booking_id ?? null) ? ($bookingNos[$r->booking_id] ?? null) : null,
            'akun_pecah'   => count(is_string($r->account_split ?? null) ? (json_decode($r->account_split, true) ?: []) : []),
        ])->values();
    }

    /**
     * Master supplier (Data Center → Supplier Center) untuk dropdown kolom Supplier.
     * Hanya dibutuhkan layar FO: PO dan SPK sudah membawa suppliernya dari dokumen asal.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function daftarSupplier(): \Illuminate\Support\Collection
    {
        return BsSupplier::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'label' => trim(($s->code ? $s->code.' — ' : '').$s->name)])
            ->values();
    }

    /** Terbitkan invoice hutang dari 1 baris antrian (refund/komisi/blt/gmc) → masuk Account Payable. */
    public function fromRefund(Request $request, JournalService $journal, int $queue): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        // Supplier WAJIB dipilih (integrasi Supplier Center) — jadi hutang AP tertaut supplier.
        $validated = $request->validate([
            'supplier_id' => ['required', 'integer', Rule::exists('bs_suppliers', 'id')],
            'due_date'    => self::ATURAN_JATUH_TEMPO,
        ]);

        $row = DB::table('bs_ap_refund_queue')->where('id', $queue)->first();
        if (! $row || $row->status === 'posted') {
            return back()->with('error', 'Antrian tidak ditemukan atau sudah diposting.');
        }

        $res = $this->issueFromRefundRow(
            $row, (int) $validated['supplier_id'], $journal, (int) $request->user()->id,
            $validated['due_date'] ?? null,
        );
        if (! $res['ok']) {
            return back()->with('error', $res['error']);
        }

        return redirect()->route('bs.ap.show', $res['invoice'])
            ->with('success', "Invoice {$res['invoice']->invoice_number} diterbitkan dari {$row->refund_no} — masuk Account Payable.");
    }

    /**
     * Posting MASSAL — item yang dicentang DIGABUNG jadi SATU Invoice Hutang (AP):
     * total = jumlah item; tiap item jadi 1 baris invoice (bs_ap_invoice_lines) +
     * sepasang baris jurnal (Dr beban/lawan · Cr hutang) dalam 1 jurnal. Header
     * supplier = supplier item pertama (item bisa beda supplier — pilihan user).
     */
    public function bulkRefund(Request $request, JournalService $journal): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        $validated = $request->validate([
            'items'               => ['required', 'array', 'min:1'],
            'items.*.id'          => ['required', 'integer'],
            'items.*.supplier_id' => ['required', 'integer', Rule::exists('bs_suppliers', 'id')],
            'due_date'            => self::ATURAN_JATUH_TEMPO,
        ]);

        // GERBANG SUPPLIER SERAGAM — harus di sini, sebelum satu baris pun ditulis.
        //
        // Satu invoice hutang gabungan hanya punya SATU supplier di kepalanya (lihat makeInvoice
        // di bawah yang memakai supplier item pertama). Kalau item dari supplier berbeda ikut
        // digabung, item milik supplier lain akan tertagih ke supplier yang pertama — salah
        // orang, dan baru ketahuan saat kuitansi dibayar. Karena itu ditolak, bukan diperbaiki
        // diam-diam: aplikasi tidak boleh menebak supplier mana yang dimaksud pengguna.
        //
        // Layar juga sudah mematikan tombolnya, tapi penjagaan itu tidak cukup — permintaan
        // bisa datang tanpa lewat layar.
        $supplierIds = array_values(array_unique(array_map(
            static fn (array $i): int => (int) $i['supplier_id'],
            $validated['items'],
        )));

        if (count($supplierIds) > 1) {
            $nama = BsSupplier::whereIn('id', $supplierIds)
                ->orderBy('name')->pluck('name')->implode(', ');

            return back()->with('error', 'Posting gabungan dibatalkan: item terpilih berasal dari '
                .count($supplierIds).' supplier berbeda ('.$nama.'). Satu invoice hutang gabungan '
                .'hanya boleh untuk satu supplier — samakan dulu pilihan suppliernya, atau posting terpisah.');
        }

        // Siapkan tiap baris (resolusi akun + deskripsi). Yang gagal/terposting dilewati.
        $prepared = [];
        $errors = [];
        foreach ($validated['items'] as $item) {
            $row = DB::table('bs_ap_refund_queue')->where('id', $item['id'])->first();
            if (! $row) {
                $errors[] = "#{$item['id']}: tidak ditemukan";

                continue;
            }
            $p = $this->prepRefundRow($row);
            if (! $p['ok']) {
                $errors[] = $p['error'];

                continue;
            }
            $p['row'] = $row;
            $p['supplier_id'] = (int) $item['supplier_id'];
            $prepared[] = $p;
        }

        if (! $prepared) {
            return back()->with('error', 'Tak ada item valid untuk diposting.'.($errors ? ' '.implode('; ', $errors) : ''));
        }

        $total = round(array_sum(array_column($prepared, 'amount')), 2);
        // PPh yang dipotong di sumbernya, dijumlahkan dari item yang membawanya (BLT).
        $totalPph = round(array_sum(array_column($prepared, 'pph')), 2);
        $akunPph = null;
        foreach ($prepared as $p) {
            $akunPph ??= $p['pphAccount'];
        }
        // Cadangan = tanggal invoice, persis perilaku sebelum kolom jatuh tempo ada.
        $tglInvoice = now()->toDateString();
        $jatuhTempo = $this->jatuhTempo($request, $tglInvoice, $tglInvoice);
        $userId = (int) $request->user()->id;

        $inv = DB::transaction(function () use ($prepared, $total, $totalPph, $akunPph, $journal, $userId, $tglInvoice, $jatuhTempo) {
            $first = $prepared[0];
            $inv = $this->makeInvoice([
                'supplier_id'        => $first['supplier_id'],
                'customer_id'        => null,
                'expense_account_id' => $first['offset'],
                'payable_account_id' => $first['payable'],
                'description'        => 'Invoice hutang gabungan ('.count($prepared).' item)',
                'invoice_date'       => $tglInvoice,
                'receive_date'       => $tglInvoice,
                'due_date'           => $jatuhTempo,
                'dpp'                => $total, 'total' => $total,
                // NET = total − PPh yang dipotong di sumbernya (BLT). Item tanpa PPh menyumbang 0,
                // jadi invoice gabungan lama tetap net_payable = total seperti sebelumnya.
                'net_payable'        => round($total - $totalPph, 2),
                'pph_mode'           => $totalPph > 0 ? 'withheld' : 'none',
                'pph_base'           => $totalPph > 0 ? $total : 0,
                'pph_pct'            => $totalPph > 0 && $total > 0 ? round($totalPph / $total * 100, 2) : 0,
                'pph_amount'         => $totalPph,
                'pph_account_id'     => $akunPph,
                'source_type'        => 'batch', 'source_id' => null,
            ], $userId);

            // Baris detail invoice + baris jurnal DEBIT per item (bisa lebih dari satu bila
            // Kategori Gimmick-nya multi-akun).
            //
            // Sisi KREDIT: item yang membawa rincian kreditnya sendiri (BLT — hutang ke penerima
            // dan hutang PPh-nya terpisah) ditulis PER ITEM, karena rincian itulah yang membuat
            // potongan pajaknya terbaca di buku besar. Item lain — seluruh sumber lama —
            // diakumulasikan menjadi SATU baris Utang Usaha Supplier di akhir, persis seperti
            // sebelumnya, sehingga invoice gabungan komisi/gimmick tidak berubah bentuknya.
            $jLines = [];
            $debitPolos = 0.0;
            foreach ($prepared as $p) {
                DB::table('bs_ap_invoice_lines')->insert([
                    'ap_invoice_id' => $inv->id,
                    'description'   => $p['desc'],
                    'qty'           => 1,
                    'unit_price'    => $p['amount'],
                    'amount'        => $p['amount'],
                ]);
                $debitItem = 0.0;
                foreach ($p['debitLines'] as $d) {
                    $jLines[] = ['account_id' => $d['account_id'], 'debit' => $d['amount'], 'credit' => 0, 'description' => $p['desc']];
                    $debitItem += (float) $d['amount'];
                }

                // Rincian kredit sendiri? Kenali dari adanya label per baris — itu yang hanya
                // dimiliki antrian yang memang mengirim rinciannya.
                $punyaRincian = false;
                foreach ($p['creditLines'] as $c) {
                    if (trim((string) ($c['label'] ?? '')) !== '') {
                        $punyaRincian = true;
                        break;
                    }
                }
                if ($punyaRincian) {
                    foreach ($p['creditLines'] as $c) {
                        $label = trim((string) ($c['label'] ?? ''));
                        $jLines[] = [
                            'account_id'  => $c['account_id'],
                            'debit'       => 0,
                            'credit'      => $c['amount'],
                            'description' => $label !== '' ? $label.' '.$p['desc'] : $p['hutangLabel'].' — '.$p['desc'],
                        ];
                    }
                } else {
                    $debitPolos += $debitItem;
                }
            }
            // Nilainya diambil dari penjumlahan baris debit yang BENAR-BENAR dibuat, supaya
            // jurnal dijamin balance walau ada pembulatan pecahan akun per item.
            if ($debitPolos > 0.009) {
                $jLines[] = [
                    'account_id'  => $first['payable'],
                    'debit'       => 0,
                    'credit'      => round($debitPolos, 2),
                    'description' => 'Utang Usaha Supplier — '.$inv->invoice_number,
                ];
            }

            $j = $journal->post([
                'journal_date' => $inv->invoice_date->toDateString(), 'source' => 'ap',
                'reference' => $inv->invoice_number, 'description' => 'AP — Invoice gabungan '.$inv->invoice_number, 'created_by' => $userId,
            ], $jLines);
            $inv->update(['journal_id' => $j->id]);

            foreach ($prepared as $p) {
                DB::table('bs_ap_refund_queue')->where('id', $p['row']->id)
                    ->update(['status' => 'posted', 'ap_invoice_id' => $inv->id, 'posted_at' => now(), 'updated_at' => now()]);
                // Tiap item tetap tercatat sendiri-sendiri ke Rincian Biaya Master Data KPR.
                $this->recordKprBiaya($p['row'], $inv, $p['amount']);
            }

            return $inv;
        });

        $msg = "1 Invoice Hutang {$inv->invoice_number} diterbitkan dari ".count($prepared)." item (total Rp ".number_format($total, 0, ',', '.').").";
        if ($errors) {
            return back()->with('error', $msg.' Dilewati: '.implode('; ', $errors));
        }

        return back()->with('success', $msg);
    }

    /**
     * Resolusi akun + deskripsi 1 baris antrian (tanpa menulis DB).
     *
     * Untuk GMC, akun berasal dari Kategori Gimmick dan bisa PECAH ke beberapa akun per sisi
     * (account_split, diisi FO saat Posting BO). Bila tidak ada pecahan, dipakai sepasang akun
     * tunggal seperti sebelumnya.
     *
     * @return array{ok:bool, amount?:float, payable?:int, offset?:int, debitLines?:array<int,array{account_id:int,amount:float}>, creditLines?:array<int,array{account_id:int,amount:float}>, desc?:string, hutangLabel?:string, date?:string, error?:string}
     */
    private function prepRefundRow(object $row): array
    {
        if ($row->status === 'posted') {
            return ['ok' => false, 'error' => "{$row->refund_no}: sudah diposting"];
        }

        // AKUN KREDIT SELURUH INVOICE HUTANG = Utang Usaha Supplier (ketentuan pemilik proses).
        // Sebelumnya diambil dari Kategori Gimmick (bs_ap_refund_queue.payable_account_id), sehingga
        // invoice bisa mengkredit akun lain — mis. Uang Muka Penjualan. Sisi kredit kini SELALU satu
        // akun ini; pengaturan kredit pada Kategori Gimmick tidak lagi menentukan akunnya.
        $payable = BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');
        // Akun lawan (sisi DEBIT) tetap wajib ter-set di antrian; tanpa itu baris ditolak seperti
        // sebelumnya — jangan sampai jatuh ke akun hutang dan mendebit akun yang keliru.
        $offset = $row->offset_account_id ?: null;
        if (! $payable || ! $offset || (int) $payable === (int) $offset) {
            return ['ok' => false, 'error' => "{$row->refund_no}: akun hutang/lawan belum ter-set"];
        }

        $amount = round((float) $row->amount, 2);
        $split  = $this->splitFromQueue($row, $amount, (int) $offset, (int) $payable);
        if (isset($split['error'])) {
            return ['ok' => false, 'error' => "{$row->refund_no}: {$split['error']}"];
        }

        $jenis = ['SPR' => 'SPR', 'SPRS' => 'SPRS', 'TRANSFER_ANONIM' => 'Transfer Anonim'][$row->refund_source] ?? (string) $row->refund_source;
        // Keterangan dasar. Untuk komisi & BLT dipakai `keterangan` dari antrian bila ada — di
        // situlah FO menaruh rincian batch (jumlah unit, PPh, NETT). Bila kosong, kembali ke
        // bentuk lama sehingga data lama tetap terbaca sama.
        $ketAntrian = trim((string) ($row->keterangan ?? ''));
        $desc = match ($row->kind) {
            'komisi' => $ketAntrian !== '' ? $ketAntrian : 'Komisi '.$row->refund_no,
            'blt'    => $ketAntrian !== '' ? $ketAntrian : 'BLT '.$row->refund_no,
            'gmc'    => 'Gimmick '.$row->refund_no.($row->customer_name ? ' — '.$row->customer_name : ''),
            default  => 'Refund '.$row->refund_no.($row->customer_name ? ' — '.$row->customer_name : '').' ('.$jenis.')',
        };
        // Lengkapi dengan NAMA PEMBELI & BLOK/UNIT — tanpa ini keterangan jurnal hanya bernomor
        // dokumen, sehingga tidak terbaca komisi/BLT/gimmick ini milik pembeli & rumah yang mana.
        $desc = $this->lengkapiPembeliUnit($desc, $row);
        $hutangLabel = ['komisi' => 'Hutang Komisi', 'blt' => 'Hutang BLT', 'gmc' => 'Hutang Gimmick'][$row->kind] ?? 'Hutang Refund';

        // PPh yang dipotong di sumbernya (BLT) — dikenali dari peran barisnya, bukan nama akunnya.
        // Nilainya dipakai mengisi kolom PPh invoice supaya SISA TAGIHAN di AP (net_payable)
        // sama dengan kredit Utang Usaha di jurnalnya. Tanpa ini invoice-nya menagih bruto
        // sementara jurnalnya mengakui nett — Aging Hutang dan Buku Besar beda angka.
        $pph = 0.0;
        $pphAccount = null;
        foreach ($split['credit'] as $c) {
            if (($c['role'] ?? null) === 'pph') {
                $pph += (float) $c['amount'];
                $pphAccount ??= (int) $c['account_id'];
            }
        }

        return [
            'ok'          => true,
            'amount'      => $amount,
            'payable'     => (int) $payable,
            'offset'      => (int) $offset,
            'debitLines'  => $split['debit'],
            'creditLines' => $split['credit'],
            'desc'        => $desc,
            'hutangLabel' => $hutangLabel,
            'pph'         => round($pph, 2),
            'pphAccount'  => $pphAccount,
            'date'        => $row->tgl_refund ?: now()->toDateString(),
        ];
    }

    /**
     * Tambahkan NAMA PEMBELI dan BLOK/UNIT ke keterangan jurnal.
     *
     * Sumbernya:
     *  - gmc  → antrian membawa customer_name & unit_id (SPR yang dibiayai).
     *  - komisi/blt → antrian membawa customer_name, dan blok/unit dititipkan di dalam
     *    `keterangan` bertanda "· Unit: " oleh sisi FO (satu pengajuan bisa lintas kontrak,
     *    jadi tidak muat di kolom unit_id tunggal).
     *
     * Nama yang sudah tercantum di keterangan tidak ditulis ulang, supaya tidak dobel.
     */
    private function lengkapiPembeliUnit(string $desc, object $row): string
    {
        // Titipan "· Unit: …" dipindahkan ke belakang sebagai bagian format "-NAMA-UNIT",
        // jadi dilepas dulu dari badan keterangan agar tidak tertulis dua kali.
        $unit = '';
        if (preg_match('/\s*·\s*Unit:\s*(.+)$/u', $desc, $m)) {
            $unit = trim($m[1]);
            $desc = trim(preg_replace('/\s*·\s*Unit:\s*.+$/u', '', $desc) ?? $desc);
        }
        if ($unit === '' && ! empty($row->unit_id)) {
            $u = DB::table('units')->where('id', $row->unit_id)->first(['unit_code']);
            $unit = trim((string) ($u->unit_code ?? ''));
        }

        $tambahan = [];
        $nama = trim((string) ($row->customer_name ?? ''));
        if ($nama !== '' && ! str_contains(mb_strtolower($desc), mb_strtolower($nama))) {
            $tambahan[] = $nama;
        }
        if ($unit !== '' && ! str_contains(mb_strtolower($desc), mb_strtolower($unit))) {
            $tambahan[] = $unit;
        }

        return $tambahan ? $desc.'-'.implode('-', $tambahan) : $desc;
    }

    /**
     * Pecahan akun untuk satu baris antrian.
     *
     * account_split (dari Kategori Gimmick) berisi [{side, account_id, portion}]. Untuk sisi DEBIT
     * yang dipakai porsinya; sisa pembulatan dilempar ke baris TERBESAR supaya total debit tetap
     * persis sama dengan nilai antrian — jurnal tidak boleh pincang.
     *
     * SISI KREDIT pada dasarnya TIDAK dipecah: satu baris Utang Usaha Supplier sebesar nilai
     * penuh. Pengecualiannya hanya bila antriannya SENDIRI membawa rincian kredit — dipakai BLT,
     * yang PPh-nya dipotong di FO sehingga hutang ke penerima hanya sebesar NETT dan sisanya
     * hutang PPh. Rincian itu berbentuk [{side:'credit', role, code|account_id, amount, label}]
     * dengan amount MUTLAK (rupiah), bukan porsi: nilai potongannya sudah dihitung dan dicetak di
     * permohonannya, jadi tidak boleh dihitung ulang di sini dan berbeda seangka dua.
     *
     * Rinciannya ditolak bila jumlahnya tidak persis sama dengan nilai antrian, atau ada akun yang
     * tak dikenal. Menerima yang meleset berarti melahirkan jurnal tak balance atau membuang
     * selisihnya diam-diam — dua-duanya lebih buruk daripada satu baris gagal yang terlihat.
     *
     * @return array{debit:array<int,array{account_id:int,amount:float}>, credit:array<int,array{account_id:int,amount:float,label?:string,role?:string}>}|array{error:string}
     */
    private function splitFromQueue(object $row, float $amount, int $offset, int $payable): array
    {
        $raw = $row->account_split ?? null;
        $rows = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);

        $debitParts = [];
        $creditParts = [];
        foreach ($rows as $r) {
            $side = (string) ($r['side'] ?? '');
            if ($side === 'debit') {
                $accId = (int) ($r['account_id'] ?? 0);
                $portion = (float) ($r['portion'] ?? 0);
                if ($accId > 0 && $portion > 0) {
                    $debitParts[] = ['account_id' => $accId, 'portion' => $portion];
                }

                continue;
            }
            if ($side !== 'credit') {
                continue;
            }

            // Akun boleh disebut lewat KODE — itulah yang dipakai sisi FO, karena id akun
            // hanya bermakna di basis data ini.
            $accId = (int) ($r['account_id'] ?? 0);
            $kode = trim((string) ($r['code'] ?? ''));
            if ($accId <= 0 && $kode !== '') {
                $accId = (int) (BsAccount::where('code', $kode)->value('id') ?? 0);
                if ($accId <= 0) {
                    return ['error' => "akun kredit {$kode} tidak ada di Bagan Akun"];
                }
            }
            $nilai = round((float) ($r['amount'] ?? 0), 2);
            if ($accId <= 0 || $nilai <= 0) {
                continue;
            }
            $creditParts[] = [
                'account_id' => $accId,
                'amount'     => $nilai,
                'label'      => trim((string) ($r['label'] ?? '')) ?: null,
                'role'       => trim((string) ($r['role'] ?? '')) ?: null,
            ];
        }

        if ($creditParts && abs(array_sum(array_column($creditParts, 'amount')) - $amount) >= 0.01) {
            return ['error' => 'rincian kredit tidak sama dengan nilai antriannya'];
        }

        $out = [
            'debit'  => $this->allocate($amount, $debitParts ?: [['account_id' => $offset, 'portion' => 100.0]]),
            'credit' => $creditParts ?: [['account_id' => $payable, 'amount' => round($amount, 2)]],
        ];

        // Akun kredit tak boleh ikut muncul di sisi debit — nilainya saling meniadakan, dan
        // jurnalnya jadi mengakui hutang yang langsung dihapusnya sendiri.
        $bentrok = array_intersect(array_column($out['credit'], 'account_id'), array_column($out['debit'], 'account_id'));
        if ($bentrok) {
            $nama = BsAccount::whereIn('id', $bentrok)->pluck('name')->implode(', ');

            return ['error' => 'akun '.($nama ?: 'hutang').' dipakai di sisi debit sekaligus kredit'];
        }

        return $out;
    }

    /**
     * Bagi $amount menurut porsi; selisih pembulatan ditambahkan ke baris terbesar.
     *
     * @param  array<int,array{account_id:int,portion:float}>  $parts
     * @return array<int,array{account_id:int,amount:float}>
     */
    private function allocate(float $amount, array $parts): array
    {
        $totalPortion = array_sum(array_column($parts, 'portion'));
        if ($totalPortion <= 0) {
            return [['account_id' => (int) $parts[0]['account_id'], 'amount' => round($amount, 2)]];
        }

        $out = [];
        foreach ($parts as $p) {
            $out[] = ['account_id' => (int) $p['account_id'], 'amount' => round($amount * (float) $p['portion'] / $totalPortion, 2)];
        }

        $diff = round($amount - array_sum(array_column($out, 'amount')), 2);
        if (abs($diff) >= 0.01) {
            $maxIdx = 0;
            foreach ($out as $i => $o) {
                if ($o['amount'] > $out[$maxIdx]['amount']) {
                    $maxIdx = $i;
                }
            }
            $out[$maxIdx]['amount'] = round($out[$maxIdx]['amount'] + $diff, 2);
        }

        return array_values(array_filter($out, fn ($o) => $o['amount'] > 0.009));
    }

    /**
     * Baris jurnal dari pecahan akun 1 item antrian: beberapa baris DEBIT + satu atau lebih baris
     * KREDIT (lihat splitFromQueue — lebih dari satu hanya bila antriannya membawa rinciannya).
     *
     * Keterangan sisi kredit: baris yang membawa label sendiri memakai "<label> <keterangan>"
     * (mis. "Hutang PPh 21 BLT Sales …") — bentuk yang diminta pemilik proses. Baris tanpa label —
     * yaitu SEMUA sumber lama — tetap "<Hutang X> — <keterangan>" persis seperti sebelumnya,
     * supaya jurnal komisi & gimmick yang sudah ada tidak berubah bentuknya.
     *
     * @param  array<string,mixed>  $p  hasil prepRefundRow()
     * @return array<int,array<string,mixed>>
     */
    private function journalLinesOf(array $p): array
    {
        $lines = [];
        foreach ($p['debitLines'] as $d) {
            $lines[] = ['account_id' => $d['account_id'], 'debit' => $d['amount'], 'credit' => 0, 'description' => $p['desc']];
        }
        foreach ($p['creditLines'] as $c) {
            $label = trim((string) ($c['label'] ?? ''));
            $lines[] = [
                'account_id'  => $c['account_id'],
                'debit'       => 0,
                'credit'      => $c['amount'],
                'description' => $label !== '' ? $label.' '.$p['desc'] : $p['hutangLabel'].' — '.$p['desc'],
            ];
        }

        return $lines;
    }

    /**
     * Catat biaya ke Rincian Biaya Master Data KPR — hanya bila antrian membawa SPR (booking_id)
     * dan kategorinya ditautkan ke salah satu kolom biaya. Idempoten via (source_type, source_ref)
     * sehingga posting ulang / batch tidak menggandakan angka.
     */
    private function recordKprBiaya(object $row, BsApInvoice $invoice, float $amount): void
    {
        $key = $this->kprBiayaKeyAktif($row);
        $bookingId = (int) ($row->booking_id ?? 0);
        if (! $key || ! $bookingId || ! array_key_exists($key, BsGimmickCategory::KPR_BIAYA_COLUMNS)) {
            return;
        }

        BsKprBiayaEntry::updateOrCreate(
            ['source_type' => (string) $row->kind, 'source_ref' => (string) $row->refund_no],
            [
                'booking_id'          => $bookingId,
                'biaya_key'           => $key,
                'tgl'                 => $row->tgl_refund ?: $invoice->invoice_date->toDateString(),
                'nominal'             => round($amount, 2),
                'source_id'           => $row->id,
                'ap_invoice_id'       => $invoice->id,
                'gimmick_category_id' => $row->gimmick_category_id ? (int) $row->gimmick_category_id : null,
                'description'         => $row->keterangan,
            ],
        );
    }

    /**
     * Kolom Rincian Biaya Master Data KPR yang BERLAKU untuk satu baris antrean.
     *
     * MENGAPA DIBACA ULANG DARI KATEGORINYA, BUKAN DARI ANTREAN.
     * FO memotret kpr_biaya_key ke bs_ap_refund_queue pada saat baris masuk antrean
     * (IproApPoster). Potret itu ikut membeku: bila operator baru memetakan Kategori
     * Gimmick ke kolom Master Data KPR SESUDAH gimmick-nya mengantre — urutan yang
     * justru paling lazim, karena pemetaan biasanya dirapikan belakangan — baris lama
     * selamanya bernilai NULL dan Master Data KPR tidak pernah terisi walau operator
     * sudah mengaturnya. Itulah sebabnya kolom Rincian Biaya bisa tampak "0/6 terpetakan"
     * padahal kategorinya sudah disetel.
     *
     * Maka yang menentukan adalah keadaan kategori PADA SAAT POSTING:
     *   1. kategori ditemukan  -> ikuti pemetaan kategori saat ini, dan hormati
     *                             sakelar "Sinkron ke Master Data KPR" (sync_kpr).
     *      Sakelar mati = operator sengaja menghentikan sinkron; potret lama TIDAK
     *      boleh menghidupkannya kembali diam-diam.
     *   2. kategori tidak ada  -> baru jatuh ke potret antrean, supaya baris lawas
     *                             (mis. kategorinya sudah dihapus) tetap tercatat
     *                             seperti perilaku sebelumnya.
     */
    private function kprBiayaKeyAktif(object $row): ?string
    {
        $categoryId = (int) ($row->gimmick_category_id ?? 0);
        $snapshot = ($row->kpr_biaya_key ?? null) ?: null;

        if ($categoryId <= 0) {
            return $snapshot;
        }

        $cat = BsGimmickCategory::query()->whereKey($categoryId)->first(['id', 'kpr_biaya_key', 'sync_kpr']);
        if (! $cat) {
            return $snapshot;
        }

        // Kolom sync_kpr baru ada sejak migrasi 2026_08_12; instalasi yang belum
        // bermigrasi diperlakukan seperti sebelumnya — terpetakan berarti sinkron.
        $sinkron = Schema::hasColumn('bs_gimmick_categories', 'sync_kpr')
            ? (bool) $cat->sync_kpr
            : (bool) $cat->kpr_biaya_key;

        return $sinkron ? (($cat->kpr_biaya_key ?: null)) : null;
    }

    /**
     * Terbitkan 1 invoice AP dari 1 baris antrian (posting satuan) + jurnal pengakuan.
     *
     * @return array{ok:bool, invoice?:BsApInvoice, error?:string}
     */
    /**
     * Tanggal jatuh tempo yang berlaku untuk sebuah posting.
     *
     * Diisi operator pada layar posting (Dari FO / Purchase Order / SPK). Bila tidak diisi,
     * dipakai cadangan yang selama ini berlaku pada jalur masing-masing — jadi menambahkan
     * kolom ini tidak mengubah perilaku posting yang sudah ada.
     *
     * Jatuh tempo TIDAK boleh mendahului tanggal invoice: tagihan yang jatuh tempo sebelum
     * terbit akan langsung terbaca menunggak di Aging Hutang, padahal belum apa-apa.
     */
    private function jatuhTempo(Request $request, string $tglInvoice, string $cadangan): string
    {
        $isian = trim((string) $request->input('due_date', ''));
        if ($isian === '') {
            return $cadangan;
        }

        return $isian < $tglInvoice ? $tglInvoice : $isian;
    }

    /** Aturan validasi bersama untuk kolom jatuh tempo pada layar posting. */
    private const ATURAN_JATUH_TEMPO = ['nullable', 'date'];

    private function issueFromRefundRow(object $row, int $supplierId, JournalService $journal, int $userId, ?string $dueDate = null): array
    {
        $p = $this->prepRefundRow($row);
        if (! $p['ok']) {
            return $p;
        }

        $inv = DB::transaction(function () use ($row, $supplierId, $p, $journal, $userId, $dueDate) {
            $inv = $this->makeInvoice([
                'supplier_id' => $supplierId, 'customer_id' => $row->customer_id,
                // unit_id dari antrian GMC → invoice tertaut ke unit/SPR yang dibiayai.
                'unit_id' => $row->unit_id ?? null,
                'expense_account_id' => $p['offset'], 'payable_account_id' => $p['payable'],
                'description' => $p['desc'], 'invoice_date' => $p['date'], 'receive_date' => $p['date'],
                // Cadangan = tanggal invoice, persis perilaku sebelum kolom jatuh tempo ada.
                'due_date' => $dueDate && $dueDate >= $p['date'] ? $dueDate : $p['date'],
                // NET = nilai − PPh dipotong. Untuk sumber tanpa PPh, $p['pph'] = 0 dan
                // ketiga angkanya tetap sama seperti sebelumnya.
                'dpp' => $p['amount'], 'total' => $p['amount'],
                'net_payable' => round($p['amount'] - $p['pph'], 2),
                'pph_mode' => $p['pph'] > 0 ? 'withheld' : 'none',
                'pph_base' => $p['pph'] > 0 ? $p['amount'] : 0,
                'pph_pct' => $p['pph'] > 0 && $p['amount'] > 0 ? round($p['pph'] / $p['amount'] * 100, 2) : 0,
                'pph_amount' => $p['pph'],
                'pph_account_id' => $p['pphAccount'],
                'source_type' => $row->kind, 'source_id' => $row->refund_id, 'refund_source' => $row->refund_source,
            ], $userId);

            // Jurnal pengakuan: Dr lawan (beban/titipan) / Cr Hutang (refund/komisi/blt).
            // Untuk GMC ber-Kategori Gimmick multi-akun, tiap sisi bisa lebih dari satu baris.
            $j = $journal->post([
                'journal_date' => $inv->invoice_date->toDateString(), 'source' => 'ap',
                'reference' => $inv->invoice_number, 'description' => 'AP — '.$p['desc'], 'created_by' => $userId,
            ], $this->journalLinesOf($p));
            $inv->update(['journal_id' => $j->id]);

            DB::table('bs_ap_refund_queue')->where('id', $row->id)
                ->update(['status' => 'posted', 'ap_invoice_id' => $inv->id, 'posted_at' => now(), 'updated_at' => now()]);

            // Rincian Biaya Master Data KPR ikut terisi otomatis.
            $this->recordKprBiaya($row, $inv, $p['amount']);

            return $inv;
        });

        return ['ok' => true, 'invoice' => $inv];
    }

    public function fromPo(Request $request, BsPurchaseOrder $po): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        if (BsApInvoice::where('source_type', 'po')->where('source_id', $po->id)->exists()) {
            return back()->with('error', 'PO ini sudah diposting menjadi invoice.');
        }
        // item + satuan ikut dimuat: rincian invoice harus menampilkan barang & satuan seperti di PO.
        $po->load('lines.item.uom');
        $value = round((float) $po->lines->sum(fn ($l) => (float) $l->qty_received * (float) $l->unit_price), 2);
        if ($value <= 0) {
            return back()->with('error', 'Belum ada barang diterima untuk PO ini.');
        }

        $payable = \App\Domain\BusinessSupport\Models\BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');
        $debit   = \App\Domain\BusinessSupport\Models\BsAccount::where('code', config('business_support.gl_accounts.inventory'))->value('id');

        $request->validate(['due_date' => self::ATURAN_JATUH_TEMPO]);
        $tglInvoice = now()->toDateString();
        // Cadangan = jatuh tempo dokumen PO-nya, lalu 30 hari — persis perilaku sebelumnya.
        $cadangan = $po->due_date?->toDateString() ?? now()->addDays(30)->toDateString();

        $inv = $this->makeInvoice([
            'supplier_id' => $po->supplier_id, 'expense_account_id' => $debit, 'payable_account_id' => $payable,
            'description' => 'Invoice dari PO '.$po->po_number, 'invoice_date' => $tglInvoice,
            'due_date' => $this->jatuhTempo($request, $tglInvoice, $cadangan),
            'dpp' => $value, 'total' => $value, 'net_payable' => $value,
            'source_type' => 'po', 'source_id' => $po->id,
        ], $request->user()->id, $this->poLines($po));

        return redirect()->route('bs.ap.show', $inv)->with('success', "Invoice {$inv->invoice_number} diterbitkan dari PO {$po->po_number}.");
    }

    public function fromSpk(Request $request, BsWorkOrderProgress $progress): RedirectResponse
    {
        abort_unless($request->user()?->canModule('ap'), 403);

        if ($progress->status !== 'unpaid' || BsApInvoice::where('source_type', 'spk')->where('source_id', $progress->id)->exists()) {
            return back()->with('error', 'Progress ini sudah diposting / dibayar.');
        }
        $request->validate(['due_date' => self::ATURAN_JATUH_TEMPO]);
        $tglInvoice = now()->toDateString();

        $wo = $progress->workOrder;
        $payable = \App\Domain\BusinessSupport\Models\BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id');

        $inv = $this->makeInvoice([
            'supplier_id' => $wo->supplier_id, 'expense_account_id' => $wo->construction_account_id, 'payable_account_id' => $payable,
            'category_id' => null, 'description' => 'Invoice SPK '.$wo->spk_number.' — '.$progress->description,
            'invoice_date' => $tglInvoice,
            'due_date' => $this->jatuhTempo($request, $tglInvoice, now()->addDays(30)->toDateString()),
            'dpp' => (float) $progress->progress_value, 'ppn_amount' => (float) $progress->ppn_amount,
            'total' => round((float) $progress->progress_value + (float) $progress->ppn_amount, 2),
            'net_payable' => (float) $progress->net_payable, 'journal_id' => $progress->journal_id,
            'source_type' => 'spk', 'source_id' => $progress->id,
        ], $request->user()->id);

        $progress->update(['status' => 'invoiced']);

        return redirect()->route('bs.ap.show', $inv)->with('success', "Invoice {$inv->invoice_number} diterbitkan dari SPK {$wo->spk_number}.");
    }

    /** @param array<string,mixed> $data */
    /**
     * @param  array<int,array{description:string,qty:float,unit_price:float,amount:float}>  $lines
     *         Rincian tagihan. Diisi agar "rincian invoice hutang = rincian sumbernya" (PO/SPK),
     *         bukan sekadar satu nilai gelondongan. Atomik bersama pembuatan invoice.
     */
    private function makeInvoice(array $data, ?int $userId, array $lines = []): BsApInvoice
    {
        return DB::transaction(function () use ($data, $userId, $lines): BsApInvoice {
            $inv = BsApInvoice::create(array_merge([
                'invoice_number' => $this->nextNumber(),
                'trx_type'       => 'normal',
                'ppn_pct'        => 0, 'ppn_amount' => 0, 'ppn_mode' => 'none', 'pph_mode' => 'none', 'pph_pct' => 0, 'pph_amount' => 0,
                'paid_amount'    => 0, 'status' => 'open', 'kurs' => 1, 'created_by' => $userId,
            ], $data));

            foreach ($lines as $l) {
                $inv->lines()->create($l);
            }

            return $inv;
        });
    }

    /**
     * Rincian PO → rincian invoice hutang, satu baris per baris PO.
     *
     * Qty memakai qty_received (yang benar-benar diterima) supaya JUMLAH baris persis sama dengan
     * nilai invoice yang dihitung di fromPo(); pada penerimaan penuh angkanya identik dengan PO.
     * Nama barang & satuan diambil dari master item agar tampilannya sama seperti rincian PO.
     *
     * @return array<int,array{description:string,qty:float,unit_price:float,amount:float}>
     */
    private function poLines(BsPurchaseOrder $po): array
    {
        $out = [];
        foreach ($po->lines as $l) {
            $qty = (float) $l->qty_received;
            if ($qty <= 0) {
                continue;                                  // belum diterima → belum ditagihkan
            }
            $price = (float) $l->unit_price;
            $item  = $l->item;
            $uom   = $item?->uom?->code ?? $item?->uom?->name;
            $name  = trim(($item?->code ? $item->code.' - ' : '').($item?->name ?? 'Barang'));
            $out[] = [
                'description' => mb_substr(trim($name.($uom ? ' ('.$uom.')' : '')), 0, 200),
                'qty'         => $qty,
                'unit_price'  => $price,
                'amount'      => round($qty * $price, 2),
            ];
        }

        return $out;
    }

    private function nextNumber(): string
    {
        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next('AP', null, [], fn () => $this->nextNumberLama());
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(): string
    {
        $d   = now();
        $seq = BsApInvoice::withTrashed()->whereYear('invoice_date', $d->year)->count() + 1;
        do {
            $number = sprintf('AP/%s/%04d', $d->format('y').$d->format('m'), $seq);
            $seq++;
        } while (BsApInvoice::withTrashed()->where('invoice_number', $number)->exists());

        return $number;
    }
}
