<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\Eprop\Models\HasilSinkron;
use App\Modules\Eprop\Models\JalanSinkron;
use App\Modules\Eprop\Support\DaftarTabel;
use App\Support\Akses;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan → Rekonsiliasi eProperty: hasil sinkron harian eProperty → J-ERP.
 *
 * Yang MASUK/COCOK/DIPERBARUI sudah dikerjakan otomatis. Yang DITAHAN (ada input J-ERP mirip
 * tapi tidak sama persis) dan KONFLIK (berubah di dua tempat) menunggu keputusan di sini;
 * keputusannya dijalankan pada sinkron berikutnya.
 */
class RekonsiliasiEprop extends Page implements HasTable
{
    use InteractsWithTable;
    use MematuhiIzinHalaman;

    protected static ?string $slug = 'rekonsiliasi-eprop';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Rekonsiliasi eProperty';

    protected static string $view = 'filament.fo.pages.rekonsiliasi-eprop';

    public string $aksi = 'ditahan';

    public ?int $jalanId = null;

    public function mount(): void
    {
        $this->jalanId = JalanSinkron::query()->where('kering', false)->where('status', 'selesai')->max('id')
            ?: JalanSinkron::query()->max('id');
    }

    public function getJalan(): ?JalanSinkron
    {
        return $this->jalanId ? JalanSinkron::find($this->jalanId) : null;
    }

    /** @return array<int,string> */
    public function getDaftarJalan(): array
    {
        return JalanSinkron::query()->orderByDesc('id')->limit(30)->get()
            ->mapWithKeys(fn ($j) => [$j->id => '#' . $j->id . ' · kiriman ' . ($j->kiriman ?: '-') . ' · '
                . optional($j->mulai)->format('d M Y H:i') . ($j->kering ? ' · UJI' : '') . ($j->status !== 'selesai' ? ' · ' . strtoupper($j->status) : '')])
            ->all();
    }

    /** @return array<string,int> aksi → jumlah pada jalan terpilih */
    public function getHitung(): array
    {
        return HasilSinkron::query()->where('jalan_id', $this->jalanId)->where('modul', '!=', 'Master')
            ->selectRaw('aksi, COUNT(*) n')->groupBy('aksi')->pluck('n', 'aksi')->all();
    }

    public function updatedJalanId(): void
    {
        $this->resetTable();
    }

    public function updatedAksi(): void
    {
        $this->resetTable();
    }

    public static function bolehMemutuskan(): bool
    {
        return Akses::boleh('fo.rekonsiliasi_eprop.update');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(HasilSinkron::query()->where('jalan_id', $this->jalanId)->where('aksi', $this->aksi)->where('modul', '!=', 'Master'))
            ->columns([
                Tables\Columns\TextColumn::make('modul')->label('Modul')->badge()->sortable(),
                Tables\Columns\TextColumn::make('kunci')->label('No. eProperty')->searchable()->copyable()->wrap(),
                Tables\Columns\TextColumn::make('tanggal')->label('Tanggal')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('ringkas')->label('Isi (eProperty)')->wrap()->limit(120)->searchable(),
                Tables\Columns\TextColumn::make('nominal')->label('Nominal')->numeric(0, ',', '.')->alignEnd()->sortable(),
                Tables\Columns\TextColumn::make('jerp')->label('J-ERP / kandidat')->wrap()->limit(160)->placeholder('—'),
                Tables\Columns\TextColumn::make('alasan')->label('Keterangan')->wrap()->limit(160)->placeholder('—'),
                Tables\Columns\TextColumn::make('keputusan')->label('Keputusan')->badge()
                    ->state(fn (HasilSinkron $r) => $this->keputusan($r))->placeholder('—')
                    ->visible(fn () => in_array($this->aksi, ['ditahan', 'konflik'], true)),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('modul')->label('Modul')->options(fn () => HasilSinkron::query()
                    ->where('jalan_id', $this->jalanId)->distinct()->orderBy('modul')->pluck('modul', 'modul')->all()),
            ])
            ->actions([
                Tables\Actions\Action::make('tautkan')->label('Tautkan')->icon('heroicon-o-link')->color('success')
                    ->visible(fn (HasilSinkron $r) => $r->aksi === 'ditahan' && isset(DaftarTabel::dokumen()[$r->tabel]) && self::bolehMemutuskan())
                    ->modalHeading('Tautkan ke input J-ERP')
                    ->modalDescription(fn (HasilSinkron $r) => 'Dokumen eProperty ' . $r->kunci . ' dianggap SAMA dengan dokumen J-ERP berikut — tidak akan dimasukkan.')
                    ->form(fn (HasilSinkron $r) => [
                        Forms\Components\Select::make('nomor')->label('No. dokumen J-ERP')->required()->searchable()
                            ->options($this->kandidat($r))
                            ->getSearchResultsUsing(fn (string $cari) => $this->cariDokumen($r->tabel, $cari))
                            ->helperText('Kandidat bernominal sama tampil lebih dulu. Ketik untuk mencari nomor lain.'),
                    ])
                    ->action(function (HasilSinkron $r, array $data) {
                        $id = $this->idDokumen($r->tabel, (string) $data['nomor']);
                        if (! $id) {
                            Notification::make()->danger()->title('Dokumen J-ERP tidak ditemukan')->send();

                            return;
                        }
                        $this->simpanKeputusan($r, 'cocok', $id);
                        Notification::make()->success()->title('Ditautkan')->body('Berlaku mulai sinkron berikutnya.')->send();
                    }),
                Tables\Actions\Action::make('masukkan')->label('Masukkan saja')->icon('heroicon-o-arrow-down-tray')->color('warning')
                    ->visible(fn (HasilSinkron $r) => $r->aksi === 'ditahan' && isset(DaftarTabel::dokumen()[$r->tabel]) && self::bolehMemutuskan())
                    ->requiresConfirmation()
                    ->modalDescription(fn (HasilSinkron $r) => 'Dokumen eProperty ' . $r->kunci . ' akan DISALIN ke J-ERP pada sinkron berikutnya, walau ada input J-ERP yang mirip. Pastikan memang bukan dobel.')
                    ->action(function (HasilSinkron $r) {
                        $this->simpanKeputusan($r, 'paksa', null);
                        Notification::make()->success()->title('Akan dimasukkan pada sinkron berikutnya')->send();
                    }),
                Tables\Actions\Action::make('pakaiEprop')->label('Pakai data eProperty')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (HasilSinkron $r) => $r->aksi === 'konflik' && self::bolehMemutuskan())
                    ->requiresConfirmation()
                    ->modalDescription('Perubahan dari eProperty akan MENIMPA isian J-ERP dokumen ini pada sinkron berikutnya.')
                    ->action(function (HasilSinkron $r) {
                        $this->simpanKeputusan($r, 'pakai', $this->idDokumen($r->tabel, $r->kunci));
                        Notification::make()->success()->title('Data eProperty dipakai pada sinkron berikutnya')->send();
                    }),
                Tables\Actions\Action::make('tetapJerp')->label('Tetap J-ERP')->icon('heroicon-o-shield-check')->color('gray')
                    ->visible(fn (HasilSinkron $r) => $r->aksi === 'konflik' && self::bolehMemutuskan())
                    ->requiresConfirmation()
                    ->modalDescription('Isian J-ERP dipertahankan; perubahan eProperty atas dokumen ini tidak dibawa lagi.')
                    ->action(function (HasilSinkron $r) {
                        $this->simpanKeputusan($r, 'abaikan', $this->idDokumen($r->tabel, $r->kunci));
                        Notification::make()->success()->title('Isian J-ERP dipertahankan')->send();
                    }),
            ])
            ->defaultSort('modul')
            ->paginated([25, 50, 100]);
    }

    private function keputusan(HasilSinkron $r): ?string
    {
        $t = DB::table('eprop_tautan')->where('tabel', $r->tabel)->where('kunci', $r->kunci)->first();
        if (! $t || $t->updated_at < $r->created_at) {
            return null;
        }

        return match ($t->status) {
            'cocok' => 'Ditautkan', 'paksa' => 'Masukkan', 'pakai' => 'Pakai eProperty', 'abaikan' => 'Tetap J-ERP',
            'masuk' => 'Sudah masuk', 'diperbarui' => 'Sudah diperbarui', default => $t->status,
        };
    }

    private function simpanKeputusan(HasilSinkron $r, string $status, ?int $idJerp): void
    {
        DB::table('eprop_tautan')->updateOrInsert(['tabel' => $r->tabel, 'kunci' => $r->kunci], [
            'id_jerp' => $idJerp, 'status' => $status,
            // 'pakai': batas "belum diubah" digeser ke sekarang → sinkron berikutnya menerapkan perubahan eProperty.
            'disinkron_pada' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        activity('eprop')->withProperties(['tabel' => $r->tabel, 'kunci' => $r->kunci, 'keputusan' => $status, 'id_jerp' => $idJerp])
            ->log('Keputusan rekonsiliasi eProperty');
    }

    private function koneksi(string $tabel)
    {
        $db = DaftarTabel::dokumen()[$tabel]['db'] ?? 'fo';

        return $db === 'bo' ? DB::connection((string) config('ipro.connection', 'ipro_bo')) : DB::connection();
    }

    private function idDokumen(string $tabel, string $nomor): ?int
    {
        $cfg = DaftarTabel::dokumen()[$tabel] ?? null;
        if (! $cfg) {
            return null;
        }
        $id = $this->koneksi($tabel)->table($tabel)->where($cfg['kunci'], $nomor)->value('id');

        return $id ? (int) $id : null;
    }

    /** @return array<string,string> */
    private function kandidat(HasilSinkron $r): array
    {
        $cfg = DaftarTabel::dokumen()[$r->tabel] ?? null;
        if (! $cfg) {
            return [];
        }
        $nom = $cfg['cocok']['nominal'] ?? null;
        $q = $this->koneksi($r->tabel)->table($r->tabel)->where('created_at', '>=', (string) env('EPROP_BATAS', '2026-10-18 23:59:59'));
        if ($nom && $r->nominal !== null) {
            $q->whereRaw('ABS(`' . $nom . '` - ?) < 1', [(float) $r->nominal]);
        }

        return $q->orderByDesc('id')->limit(30)->pluck($cfg['kunci'], $cfg['kunci'])->all();
    }

    /** @return array<string,string> */
    private function cariDokumen(string $tabel, string $cari): array
    {
        $cfg = DaftarTabel::dokumen()[$tabel];

        return $this->koneksi($tabel)->table($tabel)->where($cfg['kunci'], 'like', '%' . $cari . '%')
            ->orderByDesc('id')->limit(30)->pluck($cfg['kunci'], $cfg['kunci'])->all();
    }
}
