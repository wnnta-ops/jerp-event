<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/**
 * Pembayaran AR (read-only) — riwayat pembayaran rincian SPR per kontrak:
 *  - Pembayaran customer (BF / DP / Angsuran / TDP) dari Kwitansi (fo_bkm) ter-link baris tagihan.
 *  - Pencairan KPR dari Bankloan/BO (ipro_bo.kpr_realizations): tgl cair, kategori retensi, nominal.
 *  - Ringkasan Plafon / Sudah Cair / Outstanding Pencairan.
 * Hanya tampilan (tak ada aksi ubah) — formatnya seperti Jadwal Tagihan.
 */
class PembayaranAr extends Page implements HasTable
{
    use InteractsWithTable;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'pembayaran-ar';

    protected static string $view = 'filament.fo.pages.pembayaran-ar';

    protected static ?string $title = 'Pembayaran AR';

    protected static ?string $navigationGroup = 'Finance AR';

    public function table(Table $table): Table
    {
        return $table
            ->query(Kontrak::query()->with(['unit', 'customer'])->whereIn('tahap', ['SPR', 'SPRS']))
            ->heading('Pembayaran AR')
            ->description('Riwayat pembayaran rincian SPR (BF/DP/Angsuran) + Pencairan KPR. Klik Detail untuk melihat rincian (read-only).')
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. SPR')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tahap')->label('Tahap')->badge()->color(fn (?string $state) => $state === 'SPR' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('tgl_kontrak')->label('Tgl')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Customer')->searchable()->weight('bold')->placeholder('—'),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Nilai Kontrak')->money('IDR', divideBy: 0)->alignEnd(),
            ])
            ->actions([
                Tables\Actions\Action::make('detail')->label('Detail')->icon('heroicon-o-eye')->color('primary')->button()
                    ->modalHeading(fn (Kontrak $record) => 'Pembayaran AR — '.$record->no_kontrak)
                    ->modalContent(fn (Kontrak $record) => new HtmlString(self::riwayatHtml($record->id)))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Tutup')->modalWidth('3xl'),
            ])
            ->defaultSort('tgl_kontrak', 'desc')
            ->paginated([10, 25, 50]);
    }

    /** Tabel HTML read-only: pembayaran SPR (BKM) + pencairan KPR (BO) + ringkasan. */
    public static function riwayatHtml(?int $kontrakId): string
    {
        if (! $kontrakId) {
            return '<em style="color:#94a3b8;">Pilih SPR/Kontrak untuk melihat riwayat pembayaran.</em>';
        }
        $k = Kontrak::with(['customer', 'unit'])->find($kontrakId);
        if (! $k) {
            return '<em style="color:#94a3b8;">Kontrak tidak ditemukan.</em>';
        }

        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $tgl = fn ($d) => $d ? \Illuminate\Support\Carbon::parse((string) $d)->translatedFormat('d M Y') : '—';
        $th = fn ($t, $a = 'left') => '<th style="padding:6px 10px;text-align:'.$a.';color:#64748b;border-bottom:1px solid #cbd5e1;font-weight:600;">'.$t.'</th>';
        $td = fn ($t, $a = 'left') => '<td style="padding:5px 10px;text-align:'.$a.';border-bottom:1px solid #1e293b;">'.$t.'</td>';
        $tipeLabel = ['BF' => 'BF', 'DP' => 'DP', 'TDP' => 'Tambah DP', 'ANG' => 'Angsuran', 'ADM' => 'Administrasi', 'LAIN' => 'Lain'];

        // ── Pembayaran SPR (customer) dari Kwitansi (fo_bkm) ter-link baris tagihan ──
        $bkms = DB::table('fo_bkm')
            ->leftJoin('fo_kontrak_tagihan', 'fo_bkm.kontrak_tagihan_id', '=', 'fo_kontrak_tagihan.id')
            ->where('fo_bkm.kontrak_id', $k->id)
            ->whereNull('fo_bkm.deleted_at')
            ->orderBy('fo_bkm.tgl_bkm')->orderBy('fo_bkm.id')
            ->get(['fo_bkm.no_bkm', 'fo_bkm.tgl_bkm', 'fo_bkm.amount', 'fo_bkm.jenis_pembayaran', 'fo_kontrak_tagihan.tipe', 'fo_kontrak_tagihan.nama_tagihan']);

        // Kumpulkan SEMUA rincian dalam 1 tabel: pembayaran customer (BF/DP/Angsuran) dari
        // Kwitansi + pencairan KPR (tiap pencairan = 1 baris). Hanya rincian — tanpa blok ringkasan.
        $rincian = [];
        foreach ($bkms as $b) {
            $rincian[] = [
                'tgl' => $b->tgl_bkm,
                'tipe' => $tipeLabel[$b->tipe] ?? ($b->jenis_pembayaran ?: '—'),
                'ket' => (string) ($b->nama_tagihan ?? '—'),
                'bukti' => (string) $b->no_bkm,
                'nominal' => (float) $b->amount,
                'kpr' => false,
            ];
        }

        // ── Pencairan KPR dari Bankloan/BO (ipro_bo) → masuk sbg baris rincian ──
        $plafon = 0.0;
        $sudahCair = 0.0;
        $adaKpr = false;
        $kprErr = null;
        try {
            $conn = (string) config('ipro.connection', 'ipro_bo');
            // Link KPR BO ↔ kontrak J-ERP: utamakan no_kontrak = booking_number. Bila data BO
            // belum tersinkron (booking/unit/customer beda), fallback ke customer (kode → nama).
            $bookingId = DB::connection($conn)->table('bookings')->where('booking_number', $k->no_kontrak)->value('id');
            $kpr = $bookingId ? DB::connection($conn)->table('kpr_applications')->where('booking_id', $bookingId)->whereNull('deleted_at')->first() : null;
            if (! $kpr && $k->customer) {
                $ids = collect();
                if ($k->customer->code) {
                    $ids = $ids->merge(DB::connection($conn)->table('customers')->where('code', $k->customer->code)->pluck('id'));
                }
                if ($k->customer->full_name) {
                    $ids = $ids->merge(DB::connection($conn)->table('customers')->where('full_name', $k->customer->full_name)->pluck('id'));
                }
                $ids = $ids->unique()->values();
                if ($ids->isNotEmpty()) {
                    $kpr = DB::connection($conn)->table('kpr_applications as ka')
                        ->join('bookings as b', 'ka.booking_id', '=', 'b.id')
                        ->whereIn('b.customer_id', $ids->all())->whereNull('ka.deleted_at')
                        ->orderByDesc('ka.id')->select('ka.*')->first();
                }
            }
            if ($kpr) {
                $adaKpr = true;
                $plafon = (float) ($kpr->plafon_approved ?: $kpr->plafon_requested);
                $real = DB::connection($conn)->table('kpr_realizations as kr')
                    ->leftJoin('retention_categories as rc', 'kr.retention_category_id', '=', 'rc.id')
                    ->where('kr.kpr_application_id', $kpr->id)
                    ->orderBy('kr.disbursed_at')->orderBy('kr.id')
                    ->get(['kr.disbursed_at', 'kr.disbursed_amount', 'kr.retention_note', 'rc.name as kategori']);
                $sudahCair = (float) $real->sum('disbursed_amount');
                foreach ($real as $r) {
                    $rincian[] = [
                        'tgl' => $r->disbursed_at,
                        'tipe' => 'Pencairan KPR',
                        'ket' => trim((string) ($r->kategori ?? '—').($r->retention_note ? ' ('.$r->retention_note.')' : '')),
                        'bukti' => '—',
                        'nominal' => (float) $r->disbursed_amount,
                        'kpr' => true,
                    ];
                }
            }
        } catch (\Throwable $e) {
            $kprErr = $e->getMessage();
        }

        usort($rincian, fn ($a, $b) => strcmp((string) $a['tgl'], (string) $b['tgl']));

        // ── Render 1 tabel rincian ──
        $body = '';
        foreach ($rincian as $r) {
            $body .= '<tr style="'.($r['kpr'] ? 'color:#16a34a;' : '').'">'
                .$td($tgl($r['tgl']))
                .$td('<strong>'.e($r['tipe']).'</strong>')
                .$td(e($r['ket']))
                .$td('<span style="font-family:monospace;color:#64748b;">'.e($r['bukti']).'</span>')
                .$td($rp($r['nominal']), 'right').'</tr>';
        }
        if ($body === '') {
            $body = '<tr>'.$td('<em style="color:#94a3b8;">Belum ada pembayaran.</em>').'<td colspan="4"></td></tr>';
        }
        $total = array_sum(array_column($rincian, 'nominal'));
        $body .= '<tr style="font-weight:700;border-top:2px solid #475569;">'.$td('Total', 'left').'<td colspan="3"></td>'.$td($rp($total), 'right').'</tr>';

        $outstanding = max(0, $plafon - $sudahCair);
        $pct = $plafon > 0 ? min(100, round($sudahCair / $plafon * 100, 1)) : 0;

        $head = '<div style="margin-bottom:10px;font-size:13px;">'
            .'<strong style="font-size:15px;">'.e((string) $k->no_kontrak).'</strong> · '
            .e((string) ($k->customer?->full_name ?? '—')).' · Unit '.e((string) ($k->unit?->kode_unit ?? '—'))
            .'</div>';

        // Outstanding pencairan KPR → 1 baris catatan di bawah tabel (bukan baris rincian).
        $note = '';
        if ($adaKpr) {
            $note = '<div style="margin-top:8px;font-size:12.5px;color:#94a3b8;">'
                .'Plafon KPR Rp '.number_format($plafon, 0, ',', '.').' · Sudah Cair Rp '.number_format($sudahCair, 0, ',', '.').' ('.$pct.'%) · '
                .'<strong style="color:#d97706;">Outstanding Pencairan Rp '.number_format($outstanding, 0, ',', '.').'</strong></div>';
        } elseif ($kprErr) {
            $note = '<div style="margin-top:8px;font-size:12px;color:#dc2626;">Gagal baca data Bankloan: '.e($kprErr).'</div>';
        }

        return '<div style="max-width:920px;">'.$head
            .'<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12.5px;">'
            .'<thead><tr>'.$th('Tgl').$th('Tipe').$th('Keterangan').$th('No. Bukti').$th('Nominal', 'right').'</tr></thead>'
            .'<tbody>'.$body.'</tbody></table></div>'.$note.'</div>';
    }
}
