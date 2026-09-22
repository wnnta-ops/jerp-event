<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\BO\IproBridge\Services\RekomBatalCashService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * Collection → Rekom Batal Cash.
 *
 * Kembaran cash dari "Reject Proses KPR" / "Reject BI Checking" di modul KPR (Back Office):
 * daftar SPR cash, tombol Memo Rekom Batal per baris, dan satu tombol massal di pojok kanan
 * atas. Menekannya melakukan DUA hal sekaligus — mencetak memo DAN menulis penanda yang
 * membuka tombol "Ajukan Pembatalan" pada Pembatalan Kontrak untuk SPR itu.
 *
 * Kenapa layar ini ada di Front Office dan bukan ikut menumpang layar KPR: SPR cash tidak
 * pernah melewati inisiasi maupun pengajuan KPR, jadi ia tidak punya baris apa pun di modul
 * KPR yang bisa dipilih. Pemiliknya fo_kontrak, dan fo_kontrak hidup di sini.
 *
 * Urutan dua langkahnya SENGAJA "tulis dulu, cetak kemudian": memo yang tercetak sementara
 * penandanya gagal akan meninggalkan operator dengan dokumen resmi di tangan dan tombol yang
 * tetap mati — kegagalan yang tidak terbaca sebagai kegagalan. Karena itu bila penulisan
 * penanda melempar, layar ini BERHENTI dan menampilkan sebabnya, tanpa mencetak apa pun.
 */
class RekomBatalCash extends Page implements HasTable
{
    use InteractsWithTable;
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'Rekom Batal Cash';

    protected static ?string $navigationGroup = 'Collection';

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Slug TIDAK diawali "collection".
     *
     * Item sidebar Collection menandai dirinya aktif dengan request()->is('fo/collection*'),
     * sehingga slug seperti "collection-rekom-batal-cash" akan membuat "Surat SP" ikut
     * menyala saat layar ini dibuka.
     */
    protected static ?string $slug = 'rekom-batal-cash';

    protected static string $view = 'filament.fo.pages.rekom-batal-cash';

    public function table(Table $table): Table
    {
        return $table
            ->query(RekomBatalCashService::daftar()->with(['unit', 'customer', 'agent', 'skema']))
            ->heading('SPR Cash (CASH 1X & CASH 3X)')
            ->description('Memo Rekom Batal untuk SPR cash. Setelah memo dibuat, tombol "Ajukan Pembatalan" pada Marketing & Sales → Pembatalan Kontrak terbuka untuk SPR yang dipilih.')
            // Wajib: tanpa ini kotak centang tidak dirender sama sekali (Filament baru
            // menyalakannya bila tabel punya bulk action), dan tombol massal di pojok kanan
            // atas tidak akan pernah punya baris untuk dikerjakan.
            ->selectable()
            ->columns([
                Tables\Columns\TextColumn::make('no_kontrak')->label('No. Kontrak / SPR Final')->badge()->color('info')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('tahap')->label('Tahap')->badge()->color(fn (?string $state) => $state === 'SPR' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('unit.kode_unit')->label('Unit')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('customer.full_name')->label('Nama Customer')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('agent.name')->label('Nama Sales')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('total_kontrak')->label('Nilai Kontrak')->money('IDR', divideBy: 0)->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('cara_bayar')->label('Cara Bayar')->badge()->color('gray')
                    ->state(fn (Kontrak $record) => RekomBatalCashService::label($record->cara_bayar, $record->skema?->name)),
            ])
            ->headerActions([
                // "Pojok kanan atas" — sama seperti tombol massal di kedua layar reject KPR,
                // dan seperti di sana ia mengerjakan BARIS YANG DICENTANG, bukan seluruh
                // halaman: memo massal yang diam-diam memuat SPR yang tidak dipilih akan
                // membuka kunci pembatalan untuk kontrak yang tidak pernah dimaksud siapa pun.
                Tables\Actions\Action::make('memoMassal')
                    ->label('Memo Rekom Batal (Massal)')
                    ->icon('heroicon-o-printer')
                    ->color('danger')
                    ->disabled(fn (Component $livewire) => count($livewire->selectedTableRecords) === 0)
                    ->tooltip(fn (Component $livewire) => count($livewire->selectedTableRecords) === 0
                        ? 'Centang dulu SPR yang akan dibuatkan rekom batal.'
                        : null)
                    ->modalHeading('Memo Rekom Batal (Massal)')
                    ->modalWidth('lg')
                    ->modalSubmitAction(false)
                    ->modalCancelAction(false)
                    ->modalContent(fn (Component $livewire) => view('filament.fo.partials.konfirmasi-rekom-batal', [
                        'jumlah' => count($livewire->selectedTableRecords),
                        'panggil' => 'callMountedTableAction',
                        'batal' => 'unmountTableAction',
                    ]))
                    ->action(fn (Component $livewire) => $this->prosesMemo(
                        RekomBatalCashService::daftar()->whereKey($livewire->selectedTableRecords)->get(),
                        $livewire,
                    )),
            ])
            ->actions([
                Tables\Actions\Action::make('memo')
                    ->label('Memo Rekom Batal')
                    ->icon('heroicon-o-printer')
                    ->color('danger')
                    ->button()
                    ->modalHeading('Memo Rekom Batal')
                    ->modalWidth('lg')
                    // Tombol bawaan modal dimatikan: dua tahap konfirmasinya hidup di dalam
                    // isi modal (lihat blade-nya), dan footer bawaan akan menyodorkan "Ya"
                    // ketiga yang melompati tahap kedua.
                    ->modalSubmitAction(false)
                    ->modalCancelAction(false)
                    ->modalContent(fn (Kontrak $record) => view('filament.fo.partials.konfirmasi-rekom-batal', [
                        'jumlah' => 1,
                        'nomor' => (string) $record->no_kontrak,
                        'panggil' => 'callMountedTableAction',
                        'batal' => 'unmountTableAction',
                    ]))
                    ->action(fn (Kontrak $record, Component $livewire) => $this->prosesMemo(collect([$record]), $livewire)),
            ])
            ->emptyStateHeading('Belum ada SPR cash')
            ->emptyStateDescription('Layar ini memuat SPR & SPRS berstatus AKTIF dengan cara bayar CASH 1X atau CASH 3X.')
            ->defaultSort('tgl_kontrak', 'desc')
            ->paginated([10, 25, 50]);
    }

    /**
     * Tulis penanda lalu antarkan operator ke lembar memonya.
     *
     * @param  Collection<int,Kontrak>  $kontrak
     */
    private function prosesMemo(Collection $kontrak, Component $livewire): void
    {
        if ($kontrak->isEmpty()) {
            Notification::make()->warning()
                ->title('Tidak ada SPR yang diproses')
                ->body('Baris yang dipilih tidak lagi memenuhi syarat (cash, tahap SPR/SPRS, status AKTIF).')
                ->send();

            return;
        }

        try {
            $hasil = app(RekomBatalCashService::class)->catat(
                $kontrak,
                auth()->id(),
                auth()->user()?->name,
            );
        } catch (\Throwable $e) {
            report($e);

            // Ditampilkan, tidak ditelan: tanpa pesan ini operator hanya melihat memo yang
            // tidak muncul, dan tombol Ajukan Pembatalan yang tetap mati tanpa sebab.
            Notification::make()->danger()
                ->title('Rekom batal GAGAL dicatat — memo tidak dicetak')
                ->body('Penanda ke modul Back Office tidak tersimpan, jadi tombol "Ajukan Pembatalan" belum akan terbuka. '.$e->getMessage())
                ->persistent()->send();

            return;
        }

        if ($hasil['tanpa_booking'] > 0) {
            Notification::make()->warning()
                ->title($hasil['tanpa_booking'].' SPR belum tercermin di Back Office')
                ->body('Penandanya tetap tersimpan dan tombol "Ajukan Pembatalan" tetap terbuka. Jalankan `ipro:backfill spr` bila SPR ini juga perlu muncul di Back Office.')
                ->persistent()->send();
        }

        // Tab baru sengaja TIDAK dipakai: window.open dari respons Livewire berjalan setelah
        // klik selesai, dan peramban memblokirnya tanpa pesan apa pun. Lembar memonya membawa
        // tautan "Kembali ke Rekom Batal Cash" supaya operator tidak kehilangan jalan pulang.
        $livewire->redirect(
            route('fo.cetak.rekom-batal-cash', ['ids' => $kontrak->pluck('id')->implode(',')]),
            navigate: false,
        );
    }
}
