<?php

namespace App\Filament\System\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\System\Resources\AuditLogResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists\Components as Info;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

class AuditLogResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationGroup = 'Audit';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Audit log';

    protected static ?string $pluralModelLabel = 'Audit Log';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Info\Section::make('Aktivitas')
                ->columns(2)
                ->schema([
                    Info\TextEntry::make('description')->label('Aktivitas')->columnSpanFull()
                        ->formatStateUsing(fn ($state, Activity $record): string => static::humanDescription($record)),
                    Info\TextEntry::make('event')->label('Jenis')->badge()
                        ->formatStateUsing(fn (?string $state): string => static::eventLabel($state)),
                    Info\TextEntry::make('subject_type')->label('Data')
                        ->formatStateUsing(fn (?string $state, Activity $record): string => trim((static::modelLabel($state) ?? '—').($record->subject_id ? ' #'.$record->subject_id : ''))),
                    Info\TextEntry::make('causer.full_name')->label('Oleh')->placeholder('Sistem'),
                    Info\TextEntry::make('created_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                    Info\TextEntry::make('log_name')->label('Kategori Log')->badge(),
                ]),
            Info\Section::make('Rincian Perubahan')
                ->description('Nilai tiap kolom yang berubah — sebelum vs sesudah.')
                ->schema([
                    // Paket activitylog versi ini menyimpan diff di kolom
                    // `attribute_changes` ({old, attributes}), bukan `properties`.
                    Info\KeyValueEntry::make('sesudah')->label('Sesudah (nilai baru)')
                        ->keyLabel('Kolom')->valueLabel('Nilai')
                        ->state(fn (Activity $record): array => static::humanChangeSet($record, 'attributes')),
                    Info\KeyValueEntry::make('sebelum')->label('Sebelum (nilai lama)')
                        ->keyLabel('Kolom')->valueLabel('Nilai')
                        ->state(fn (Activity $record): array => static::humanChangeSet($record, 'old')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Waktu')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('log_name')->label('Log')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('event')->label('Jenis')->badge()
                    ->formatStateUsing(fn (?string $state) => static::eventLabel($state))
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'info',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('description')->label('Aktivitas')->limit(60)->searchable()
                    ->formatStateUsing(fn ($state, Activity $record) => static::humanDescription($record)),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Data')
                    ->formatStateUsing(fn (?string $state) => static::modelLabel($state))
                    ->toggleable(),
                Tables\Columns\TextColumn::make('subject_id')->label('ID')->toggleable(),
                Tables\Columns\TextColumn::make('causer.full_name')->label('Pelaku')->placeholder('System')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('event')
                    ->options(['created' => 'created', 'updated' => 'updated', 'deleted' => 'deleted']),
                Tables\Filters\SelectFilter::make('log_name')
                    ->options(fn () => Activity::query()->distinct()->pluck('log_name', 'log_name')->filter()->all()),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('Dari'),
                        Forms\Components\DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Ambil sisi perubahan ('attributes' = sesudah, 'old' = sebelum) dari kolom
     * attribute_changes, apa pun bentuk cast-nya (Collection/array/JSON string).
     *
     * @return array<string, mixed>
     */
    protected static function changeSet(Activity $record, string $side): array
    {
        $changes = $record->attribute_changes;

        if ($changes instanceof Collection) {
            $changes = $changes->all();
        } elseif (is_string($changes)) {
            $changes = json_decode($changes, true) ?: [];
        }

        return (array) data_get($changes, $side, []);
    }

    /** Nama model MANUSIAWI dari FQCN (App\...\Bg → "Bilyet Giro"). */
    protected static function modelLabel(?string $fqcn): ?string
    {
        if (! $fqcn) {
            return null;
        }
        $base = class_basename($fqcn);

        return self::MODEL_LABELS[$base]
            ?? \Illuminate\Support\Str::of($base)->snake()->replace('_', ' ')->title()->toString();
    }

    protected static function eventLabel(?string $event): string
    {
        return match ($event) {
            'created' => 'Input baru',
            'updated' => 'Perubahan',
            'deleted' => 'Dihapus',
            'restored' => 'Dipulihkan',
            default => (string) ($event ?? '—'),
        };
    }

    /** Deskripsi ramah: "{Data} dibuat/diperbarui/dihapus (#id)". */
    protected static function humanDescription(Activity $record): string
    {
        $label = static::modelLabel($record->subject_type);
        $verb = match ($record->event) {
            'created' => 'dibuat',
            'updated' => 'diperbarui',
            'deleted' => 'dihapus',
            'restored' => 'dipulihkan',
            default => (string) $record->event,
        };

        return $label
            ? $label.' '.$verb.($record->subject_id ? ' (#'.$record->subject_id.')' : '')
            : (string) ($record->description ?: '—');
    }

    /**
     * Perubahan dengan LABEL kolom manusiawi + nilai terformat (Rp, tanggal, ID→nama).
     *
     * @return array<string, string>
     */
    protected static function humanChangeSet(Activity $record, string $side): array
    {
        $out = [];
        foreach (static::changeSet($record, $side) as $key => $value) {
            $out[static::fieldLabel((string) $key)] = static::formatValue((string) $key, $value);
        }

        return $out;
    }

    /** Label kolom manusiawi (fallback: judul dari nama kolom). */
    protected static function fieldLabel(string $key): string
    {
        return self::FIELD_LABELS[$key]
            ?? \Illuminate\Support\Str::of($key)->replaceLast('_id', '')->replace('_', ' ')->title()->toString();
    }

    /** Format nilai agar mudah dibaca: Rp, tanggal, persen, Ya/Tidak, ID→nama, kosong→—. */
    protected static function formatValue(string $key, mixed $value): string
    {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if ($value === null || $value === '') {
            return '—';
        }
        // Persen
        if (str_contains($key, 'persen') || str_ends_with($key, '_pct') || str_contains($key, 'percent')) {
            return is_numeric($value) ? rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').'%' : (string) $value;
        }
        // Foreign key → nama record
        if (str_ends_with($key, '_id') && is_numeric($value)) {
            $name = static::resolveFk($key, (int) $value);

            return $name !== null && $name !== '' ? $name.' (#'.$value.')' : '#'.$value;
        }
        // Tanggal / waktu
        if (static::looksLikeDate($key)) {
            try {
                $c = \Illuminate\Support\Carbon::parse((string) $value);

                return str_ends_with($key, '_at') ? $c->format('d M Y H:i') : $c->format('d M Y');
            } catch (\Throwable $e) {
            }
        }
        // Boolean
        if (static::looksLikeBool($key) && (is_bool($value) || in_array($value, [0, 1, '0', '1'], true))) {
            return ((int) $value) ? 'Ya' : 'Tidak';
        }
        // Uang → Rupiah
        if (static::looksLikeMoney($key) && is_numeric($value)) {
            return 'Rp '.number_format((float) $value, 0, ',', '.');
        }

        return (string) $value;
    }

    protected static function looksLikeDate(string $k): bool
    {
        return str_starts_with($k, 'tgl') || str_ends_with($k, '_at') || str_ends_with($k, '_date')
            || in_array($k, ['due_date', 'receive_date', 'settled_date', 'disbursed_at', 'tanggal', 'jatuh_tempo'], true);
    }

    protected static function looksLikeMoney(string $k): bool
    {
        foreach (['amount', 'nilai', 'nominal', 'harga', 'total', 'biaya', 'plafon', 'saldo', 'ppn', 'diskon', 'pelunasan', 'bf_nominal', 'subsidi', 'booking_fee', 'debit', 'credit', 'disbursed_amount', 'angsuran', 'dp_amount'] as $t) {
            if (str_contains($k, $t)) {
                return true;
            }
        }

        return false;
    }

    protected static function looksLikeBool(string $k): bool
    {
        return str_starts_with($k, 'is_') || str_starts_with($k, 'has_') || str_starts_with($k, 'flag_')
            || in_array($k, ['aktif', 'is_active', 'tampil_siteplan', 'pernyataan_setuju', 'skip_gl_posting', 'diberikan', 'diterima'], true);
    }

    /** Resolusi ID → nama untuk kolom relasi umum (tanpa tenant scope; panel System). */
    protected static function resolveFk(string $key, int $id): ?string
    {
        $map = [
            'customer_id' => [\App\Modules\FO\MarketingSales\Models\Customer::class, 'full_name'],
            'bank_id' => [\App\Models\Bank::class, 'name'],
            'kontrak_id' => [\App\Modules\FO\MarketingSales\Models\Kontrak::class, 'no_kontrak'],
            'unit_id' => [\App\Modules\FO\MarketingSales\Models\Unit::class, 'kode_unit'],
            'agent_id' => [\App\Modules\FO\Komisi\Models\Agent::class, 'name'],
            'reservasi_id' => [\App\Modules\FO\MarketingSales\Models\Reservasi::class, 'no_reservasi'],
            'skema_id' => [\App\Modules\FO\Adminjual\Models\Skema::class, 'name'],
            'tts_id' => [\App\Modules\FO\FinanceAR\Models\Tts::class, 'no_tts'],
            'bg_pengganti_id' => [\App\Modules\FO\FinanceAR\Models\Bg::class, 'no_bg'],
            'project_id' => [\App\Models\Project::class, 'name'],
            'company_id' => [\App\Models\Company::class, 'name'],
        ];
        if (! isset($map[$key])) {
            return null;
        }
        [$model, $col] = $map[$key];
        try {
            if (! class_exists($model)) {
                return null;
            }

            return $model::query()->withoutGlobalScopes()->whereKey($id)->value($col);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @var array<string,string> FQCN basename → nama data manusiawi. */
    protected const MODEL_LABELS = [
        'Bg' => 'Bilyet Giro', 'Gmc' => 'GMC (Gimmick)', 'Bkm' => 'Kwitansi (BKM)',
        'Kontrak' => 'Kontrak / SPR', 'Customer' => 'Customer', 'Unit' => 'Unit',
        'Refund' => 'Refund / CB', 'Tts' => 'TTS (Tanda Terima)', 'Reservasi' => 'Booking / Reservasi',
        'Skema' => 'Skema Bayar', 'Agent' => 'Agen / Sales', 'NupRegistration' => 'Pendaftaran NUP',
        'Company' => 'Perusahaan', 'Project' => 'Proyek', 'Bank' => 'Bank', 'User' => 'Pengguna',
        'Komisi' => 'Komisi', 'Blt' => 'BLT', 'TransferAnonim' => 'Transfer Anonim',
        'Approval' => 'Approval / Persetujuan', 'ApprovalBatch' => 'Pengajuan Approval',
        'Gimmick' => 'Gimmick', 'PriceList' => 'Price List',
    ];

    /** @var array<string,string> nama kolom → label manusiawi. */
    protected const FIELD_LABELS = [
        'no_bg' => 'No. BG/Cek', 'tgl_bg' => 'Tgl BG', 'tgl_jatuh_tempo' => 'Jatuh Tempo',
        'bank_id' => 'Bank', 'bank_nama' => 'Nama Bank', 'customer_id' => 'Customer',
        'amount' => 'Nominal', 'nilai' => 'Nilai / Biaya', 'pengelola' => 'Pengelola',
        'keterangan' => 'Keterangan', 'status' => 'Status', 'tts_id' => 'No. TTS',
        'bg_pengganti_id' => 'BG Pengganti', 'no_kontrak' => 'No. Kontrak / SPR',
        'no_gmc' => 'No. GMC', 'no_bkm' => 'No. Kwitansi', 'kontrak_id' => 'Kontrak / SPR',
        'unit_id' => 'Unit', 'nama' => 'Nama', 'tgl' => 'Tanggal', 'harga_jual' => 'Harga Jual',
        'total_kontrak' => 'Total Kontrak', 'dp_persen' => 'DP', 'cara_bayar' => 'Cara Bayar',
        'tahap' => 'Tahap', 'diskon' => 'Diskon', 'ppn' => 'PPN', 'tenant_id' => 'Tenant',
        'created_at' => 'Dibuat', 'updated_at' => 'Diperbarui', 'deleted_at' => 'Dihapus',
        'jenis_pembayaran' => 'Jenis Pembayaran', 'agent_id' => 'Agen', 'skema_id' => 'Skema Bayar',
        'reservasi_id' => 'Booking', 'project_id' => 'Proyek', 'company_id' => 'Perusahaan',
        'no_reservasi' => 'No. Booking', 'no_tts' => 'No. TTS', 'full_name' => 'Nama Lengkap',
        'nik' => 'NIK', 'npwp' => 'NPWP', 'pelunasan' => 'Terbayar', 'sumber' => 'Sumber',
        'bo_posted_at' => 'Waktu Posting BO', 'dp_amount' => 'Nominal DP', 'tenor_bulan' => 'Tenor (bln)',
        // Approval / pengajuan
        'no_approval' => 'No. Approval', 'tgl_approval' => 'Tgl Approval', 'jenis' => 'Jenis Pengajuan',
        'no_pengajuan' => 'No. Pengajuan', 'current_level' => 'Level Persetujuan', 'catatan' => 'Catatan',
        'alasan' => 'Alasan', 'approved_by' => 'Disetujui Oleh', 'rejected_by' => 'Ditolak Oleh',
    ];

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
            'view' => Pages\ViewAuditLog::route('/{record}'),
        ];
    }
}
