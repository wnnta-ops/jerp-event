<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Concerns\MematuhiIzin;
use App\Filament\Fo\Resources\ApprovalResource\Pages;
use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\Approval\Services\ApprovalService;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

class ApprovalResource extends Resource
{
    use MematuhiIzin;

    protected static ?string $model = Approval::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'Approval';

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = 'Approval';

    protected static ?string $pluralModelLabel = 'Approval';

    /** Sub-resource per jenis meng-override ini (mis. 'DISKON') untuk membatasi & default. */
    protected static ?string $jenisScope = null;

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()
            // Subject Kontrak beserta SPR/customer/unit di-eager-load: kolom tabel
            // membacanya per baris, tanpa ini jadi N+1.
            ->with(['subject' => function (MorphTo $morphTo) {
                $morphTo->morphWith([Kontrak::class => ['reservasi', 'customer', 'unit']]);
            }]);

        return static::$jenisScope ? $q->where('jenis', static::$jenisScope) : $q;
    }

    /** Subject sebagai Kontrak (null bila jenisnya tak terikat kontrak, mis. BLT/Komisi). */
    protected static function kontrakOf(Approval $r): ?Kontrak
    {
        return $r->subject instanceof Kontrak ? $r->subject : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Approval')->columns(3)->schema([
                Forms\Components\TextInput::make('no_approval')->label('No approval')->disabled()->dehydrated()->placeholder('Otomatis'),
                Forms\Components\DatePicker::make('tgl_approval')->label('Tanggal')->default(now())->required(),
                Forms\Components\Select::make('jenis')->label('Jenis')->options([
                    'DISKON' => 'Diskon harga (SPR)',
                    'DISKON_SPRS' => 'Diskon SPRS (GM)',
                    'BATAL_KONTRAK' => 'Batal kontrak',
                    'ADJ_HARGA' => 'Adjust harga',
                    'RESCHEDULE' => 'Reschedule angsuran',
                    'GANTI_NAMA' => 'Ganti nama (pengalihan hak)',
                    'GANTI_UNIT' => 'Ganti unit (pindah unit)',
                    'REFUND' => 'Refund',
                    'BLT' => 'Pengajuan BLT',
                    'KOMISI' => 'Pengajuan Komisi',
                    'LAIN' => 'Lain (customize)',
                ])->default(static::$jenisScope ?? 'DISKON')
                    ->disabled(fn () => filled(static::$jenisScope))->dehydrated()->required(),
            ]),

            Forms\Components\Section::make('Subject (objek yang dimohon)')->columns(2)->schema([
                Forms\Components\Select::make('subject_type')->label('Tipe objek')->options([
                    'App\\Modules\\FO\\MarketingSales\\Models\\Kontrak' => 'Kontrak',
                    'App\\Modules\\FO\\MarketingSales\\Models\\Reservasi' => 'Reservasi',
                    'App\\Modules\\FO\\FinanceAR\\Models\\Tts' => 'TTS',
                ]),
                Forms\Components\TextInput::make('subject_id')->label('ID objek')->numeric(),
                Forms\Components\TextInput::make('amount_dampak')->label('Dampak (Rp)')->numeric()->rupiah(),
            ]),

            Forms\Components\Section::make('Detail Permohonan')->schema([
                Forms\Components\Textarea::make('alasan')->label('Alasan')->rows(3)->required(),
                Forms\Components\KeyValue::make('detail_lama')->label('Detail sebelum')
                    ->keyLabel('Field')->valueLabel('Nilai sebelum'),
                Forms\Components\KeyValue::make('detail_baru')->label('Detail diminta')
                    ->keyLabel('Field')->valueLabel('Nilai diminta'),
            ])->collapsible(),

            // Pemohon otomatis = user saat ini (set di model; tak bisa dipalsukan dari form).
            // Status & approver: alur normal lewat tombol Approve/Reject. Koreksi manual
            // HANYA super admin (cegah bypass workflow lewat form edit).
            Forms\Components\Section::make('Status & Approver (koreksi manual)')->columns(2)
                ->visible(fn () => (bool) auth()->user()?->hasRole('super_admin'))
                ->description('Hanya super admin. Alur normal memakai tombol Approve/Reject.')
                ->schema([
                    Forms\Components\Select::make('status')->label('Status')->options([
                        'DRAFT' => 'Draft', 'PENDING' => 'Pending', 'APPROVED' => 'Approved',
                        'REJECTED' => 'Rejected', 'EXECUTED' => 'Executed',
                    ])->required(),
                    Forms\Components\Select::make('requested_by')->label('Diminta oleh')->relationship('requester', 'name')->searchable()->preload(),
                    Forms\Components\Select::make('approved_by')->label('Di-approve oleh')->relationship('approver', 'name')->searchable()->preload(),
                    Forms\Components\DateTimePicker::make('approved_at')->label('Tgl approve'),
                    Forms\Components\Textarea::make('catatan_approver')->label('Catatan approver')->rows(2)->columnSpanFull(),
                ]),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Permohonan')->columns(3)->schema([
                Infolists\Components\TextEntry::make('no_approval')->label('No')->badge()->color('primary'),
                Infolists\Components\TextEntry::make('jenis')->label('Jenis')->badge(),
                Infolists\Components\TextEntry::make('status')->label('Status')->badge()
                    ->formatStateUsing(fn (Approval $r) => $r->status === 'PENDING'
                    ? 'PENDING · '.(ApprovalService::isSingleLevel($r->jenis) ? 'GM' : 'L'.$r->current_level)
                    : $r->status)
                    ->color(fn (Approval $r) => match ($r->status) {
                        'DRAFT' => 'gray', 'PENDING' => 'warning', 'APPROVED' => 'success',
                        'REJECTED' => 'danger', 'EXECUTED' => 'primary', default => 'gray',
                    }),
                Infolists\Components\TextEntry::make('tgl_approval')->label('Tanggal')->date('d M Y'),
                Infolists\Components\TextEntry::make('requester.name')->label('Pemohon')->placeholder('—'),
                Infolists\Components\TextEntry::make('amount_dampak')->label('Dampak')->money('IDR'),
                Infolists\Components\TextEntry::make('objek')->label('Objek')->state(fn (Approval $r) => self::subjectLabel($r))->columnSpanFull(),
                Infolists\Components\TextEntry::make('alasan')->label('Alasan')->placeholder('—')->columnSpanFull(),
            ]),
            Infolists\Components\Section::make('Detail Perubahan yang Diminta')
                ->description('Bandingkan kondisi sebelum vs yang diminta sebelum menyetujui.')
                ->visible(fn (Approval $r) => ! in_array($r->jenis, ['BLT', 'KOMISI'], true))
                ->schema([
                    Infolists\Components\TextEntry::make('perubahan')->hiddenLabel()
                        ->state(fn (Approval $r) => self::renderPerubahan($r))->html()->columnSpanFull(),
                ]),
            Infolists\Components\Section::make('Jadwal Tagihan: Sebelum vs Sesudah Reschedule')
                ->description('Jadwal baru dihitung ulang saat disetujui — bisa sedikit berbeda bila ada pembayaran masuk sesudah pengajuan.')
                ->visible(fn (Approval $r) => $r->jenis === 'RESCHEDULE')
                ->columns(2)
                ->schema([
                    Infolists\Components\TextEntry::make('jadwal_lama')->label('⊖ Sebelum')
                        ->state(fn (Approval $r) => \App\Filament\Fo\Pages\RescheduleTagihan::jadwalHtml((array) (((array) $r->detail_lama)['jadwal'] ?? [])))->html(),
                    Infolists\Components\TextEntry::make('jadwal_baru')->label('⊕ Sesudah')
                        ->state(fn (Approval $r) => \App\Filament\Fo\Pages\RescheduleTagihan::jadwalHtml((array) (((array) $r->detail_baru)['jadwal'] ?? [])))->html(),
                ]),
            Infolists\Components\Section::make('Daftar BLT (Pengajuan)')
                ->description('Rincian BLT yang diajukan dalam batch ini.')
                ->visible(fn (Approval $r) => $r->jenis === 'BLT')
                ->schema([
                    Infolists\Components\TextEntry::make('blt_lines')->hiddenLabel()
                        ->state(fn (Approval $r) => self::renderBltLines($r))->html()->columnSpanFull(),
                ]),
            Infolists\Components\Section::make('Daftar Komisi (Pengajuan)')
                ->description('Rincian komisi yang diajukan dalam batch ini.')
                ->visible(fn (Approval $r) => $r->jenis === 'KOMISI')
                ->schema([
                    Infolists\Components\TextEntry::make('komisi_lines')->hiddenLabel()
                        ->state(fn (Approval $r) => self::renderKomisiLines($r))->html()->columnSpanFull(),
                ]),
            Infolists\Components\Section::make('Catatan & Hasil')->columns(2)->schema([
                Infolists\Components\TextEntry::make('level1_note')->label('Catatan Level 1')->placeholder('—'),
                Infolists\Components\TextEntry::make('catatan_approver')->label('Catatan Level 2')->placeholder('—'),
                Infolists\Components\TextEntry::make('executed_result')->label('Hasil Eksekusi')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }

    /** Label objek (subject) — Kontrak tampil no/customer/unit. */
    public static function subjectLabel(Approval $r): string
    {
        $s = $r->subject;
        if ($s instanceof Kontrak) {
            return trim($s->no_kontrak.' — '.($s->customer?->full_name ?? '').' — '.($s->unit?->kode_unit ?? ''), ' —');
        }

        return $r->subject_type ? class_basename($r->subject_type).' #'.$r->subject_id : '—';
    }

    /** Tabel HTML perbandingan Sebelum vs Diminta (dari detail_lama & detail_baru). */
    public static function renderPerubahan(Approval $r): string
    {
        $label = fn (string $k) => ucwords(trim(str_replace(['_', ' baru', ' id'], [' ', '', ''], $k)));
        $val = fn ($v) => is_numeric($v) ? 'Rp '.number_format((float) $v, 0, ',', '.') : e((string) $v);
        $col = function (?array $arr) use ($label, $val): string {
            if (empty($arr)) {
                return '<em style="color:#94a3b8;">—</em>';
            }
            $rows = '';
            foreach ($arr as $k => $v) {
                // Jadwal (RESCHEDULE) tampil di bagiannya sendiri; *_id hanya untuk eksekusi.
                if (is_array($v) || str_ends_with((string) $k, '_id')) {
                    continue;
                }
                $rows .= '<tr><td style="color:#94a3b8;padding:3px 14px 3px 0;">'.$label((string) $k).'</td>'
                    .'<td style="text-align:right;font-weight:600;padding:3px 0;">'.$val($v).'</td></tr>';
            }

            return '<table style="width:100%;border-collapse:collapse;">'.$rows.'</table>';
        };

        return '<div style="display:grid;grid-template-columns:1fr 1fr;gap:28px;">'
            .'<div><div style="font-weight:700;color:#ef4444;margin-bottom:6px;">⊖ Sebelum</div>'.$col((array) $r->detail_lama).'</div>'
            .'<div><div style="font-weight:700;color:#22c55e;margin-bottom:6px;">⊕ Diminta</div>'.$col((array) $r->detail_baru).'</div>'
            .'</div>';
    }

    /** Tabel rincian BLT dalam batch pengajuan (No. Pengajuan dari detail_baru) — gaya printout. */
    public static function renderBltLines(Approval $r): string
    {
        $no = (string) (((array) $r->detail_baru)['no_pengajuan'] ?? '');
        if ($no === '') {
            return '<em style="color:#94a3b8;">—</em>';
        }
        $rows = \App\Modules\FO\Komisi\Models\Blt::query()
            ->with(['agent', 'kontrak.customer', 'kontrak.unit'])
            ->where('no_pengajuan', $no)
            ->orderByRaw("FIELD(level_type,'SALES','KOORDINATOR','KANTOR')")->get();
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $th = fn ($t, $align = 'left') => '<th style="padding:5px 8px;border-bottom:1px solid #334155;text-align:'.$align.';white-space:nowrap;">'.$t.'</th>';
        $td = fn ($t, $align = 'left') => '<td style="padding:4px 8px;border-bottom:1px solid #1e293b;text-align:'.$align.';'.($align !== 'left' ? 'white-space:nowrap;' : '').'">'.$t.'</td>';

        $head = '<tr>'.$th('No').$th('Nama Pembeli').$th('Penerima').$th('Agent').$th('Blok/Kav')
            .$th('Cara Bayar').$th('Nilai BLT', 'right').$th('PPh', 'right').$th('NETT', 'right').'</tr>';
        $body = '';
        $i = 0;
        foreach ($rows as $b) {
            $i++;
            $a = $b->agent;
            $unit = $b->kontrak?->unit;
            $body .= '<tr>'.$td((string) $i, 'center')
                .$td(e((string) ($b->kontrak?->customer?->full_name ?: '—')))
                .$td(e((string) ($b->nama_user ?: '—')))
                .$td($a?->is_arebi ? 'AGENT AREBI' : 'AGENT NON AREBI')
                .$td(e((string) ($unit?->kode_unit ?: $unit?->no_unit ?: '—')), 'center')
                .$td(e((string) ($b->kontrak?->cara_bayar ?: '—')), 'center')
                .$td($rp($b->nilai_blt), 'right').$td($rp($b->pph_amount), 'right')
                .$td('<strong>'.$rp($b->nett).'</strong>', 'right')
                .'</tr>';
        }
        $tot = '<tr style="font-weight:700;border-top:2px solid #475569;">'
            .'<td colspan="6" style="padding:6px 8px;text-align:right;">TOTAL</td>'
            .$td($rp($rows->sum('nilai_blt')), 'right').$td($rp($rows->sum('pph_amount')), 'right').$td($rp($rows->sum('nett')), 'right')
            .'</tr>';

        return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">'
            .'<thead>'.$head.'</thead><tbody>'.$body.$tot.'</tbody></table></div>';
    }

    /** Tabel rincian Komisi dalam batch pengajuan (No. Pengajuan dari detail_baru). */
    public static function renderKomisiLines(Approval $r): string
    {
        $no = (string) (((array) $r->detail_baru)['no_pengajuan'] ?? '');
        if ($no === '') {
            return '<em style="color:#94a3b8;">—</em>';
        }
        $rows = \App\Modules\FO\Komisi\Models\Komisi::query()
            ->with(['agent', 'kontrak.customer', 'kontrak.unit'])
            ->where('no_pengajuan', $no)->orderBy('id')->get();
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        $th = fn ($t, $align = 'left') => '<th style="padding:5px 8px;border-bottom:1px solid #334155;text-align:'.$align.';white-space:nowrap;">'.$t.'</th>';
        $td = fn ($t, $align = 'left') => '<td style="padding:4px 8px;border-bottom:1px solid #1e293b;text-align:'.$align.';'.($align !== 'left' ? 'white-space:nowrap;' : '').'">'.$t.'</td>';

        $head = '<tr>'.$th('No').$th('Nama Pembeli').$th('Kantor').$th('Blok/Kav').$th('Cara Bayar')
            .$th('DPP', 'right').$th('Komisi', 'right').$th('PPh', 'right').$th('NETT', 'right').'</tr>';
        $body = '';
        $i = 0;
        foreach ($rows as $k) {
            $i++;
            $unit = $k->kontrak?->unit;
            $body .= '<tr>'.$td((string) $i, 'center')
                .$td(e((string) ($k->kontrak?->customer?->full_name ?: '—')))
                .$td(e((string) ($k->agent?->namaKantor() ?: '—')))
                .$td(e((string) ($unit?->kode_unit ?: $unit?->no_unit ?: '—')), 'center')
                .$td(e((string) ($k->kontrak?->cara_bayar ?: '—')), 'center')
                .$td($rp($k->dpp), 'right').$td($rp($k->amount), 'right')
                .$td($rp($k->pph_amount), 'right').$td('<strong>'.$rp($k->amount_net).'</strong>', 'right')
                .'</tr>';
        }
        $tot = '<tr style="font-weight:700;border-top:2px solid #475569;">'
            .'<td colspan="6" style="padding:6px 8px;text-align:right;">TOTAL</td>'
            .$td($rp($rows->sum('amount')), 'right').$td($rp($rows->sum('pph_amount')), 'right').$td($rp($rows->sum('amount_net')), 'right')
            .'</tr>';

        return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;">'
            .'<thead>'.$head.'</thead><tbody>'.$body.$tot.'</tbody></table></div>';
    }

    /** @return array<int,Tables\Actions\Action> Aksi alur 2-level (dipakai di baris & modal Detail). */
    protected static function workflowActions(): array
    {
        return [
            Tables\Actions\Action::make('submit')->label('Submit')->icon('heroicon-o-paper-airplane')->color('info')
                ->visible(fn (Approval $r) => $r->status === 'DRAFT')->requiresConfirmation()
                ->action(function (Approval $r) {
                    app(ApprovalService::class)->submit($r);
                    Notification::make()->title('Diajukan — menunggu Approval Level 1')->success()->send();
                }),
            Tables\Actions\Action::make('approve_l1')->label('Approve L1')->icon('heroicon-o-check')->color('warning')
                ->visible(fn (Approval $r) => $r->isAwaitingLevel1() && (bool) auth()->user()?->can('approveLevel1', $r))
                ->form([Forms\Components\Textarea::make('note')->label('Catatan Level 1')->rows(2)])
                ->action(function (Approval $r, array $data) {
                    app(ApprovalService::class)->approveLevel1($r, $data['note'] ?? null);
                    Notification::make()->title('Disetujui Level 1 → menunggu Level 2 (final)')->success()->send();
                }),
            Tables\Actions\Action::make('approve_l2')
                ->label(fn (Approval $r) => ApprovalService::isSingleLevel($r->jenis) ? 'Approve (GM)' : 'Approve L2 (final)')
                ->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Approval $r) => $r->isAwaitingLevel2() && (bool) auth()->user()?->can('approveLevel2', $r))
                ->requiresConfirmation()
                ->modalDescription('Persetujuan final akan LANGSUNG menerapkan perubahan ke kontrak.')
                ->form([Forms\Components\Textarea::make('note')->label('Catatan Level 2')->rows(2)])
                ->action(function (Approval $r, array $data) {
                    try {
                        $a = app(ApprovalService::class)->approveLevel2($r, $data['note'] ?? null);
                        Notification::make()->title('Disetujui & dieksekusi')->body($a->executed_result)->success()->persistent()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('Gagal eksekusi')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
            Tables\Actions\Action::make('reject')->label('Reject')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Approval $r) => $r->status === 'PENDING' && (bool) auth()->user()?->can('reject', $r))
                ->form([Forms\Components\Textarea::make('note')->label('Alasan reject')->rows(2)->required()])
                ->action(function (Approval $r, array $data) {
                    app(ApprovalService::class)->reject($r, $data['note']);
                    Notification::make()->title('Ditolak')->warning()->send();
                }),
        ];
    }

    /**
     * Empat kolom pengenal baris approval. Dipisah supaya bisa diganti per menu.
     *
     * @return array<int,Tables\Columns\TextColumn>
     */
    protected static function kolomIdentitas(): array
    {
        return [
            // Nomor SPR ada di no_kontrak (mis. "000955/SPR/JR/VIII/2025"), BUKAN di
            // reservasi. Sebelumnya kolom ini mengambil reservasi->no_reservasi yang
            // isinya sekadar nomor urut ("548") — dan 923 dari 1.576 kontrak memang
            // tidak punya tautan reservasi, sehingga hampir semua baris tampil "—".
            Tables\Columns\TextColumn::make('no_spr')->label('No. SPR')->badge()->color('primary')
                ->state(fn (Approval $r) => static::kontrakOf($r)?->no_kontrak ?: '—'),

            // Tanggal PENGAJUAN approval, bukan tanggal reservasi: yang ditanyakan
            // orang saat membuka daftar ini adalah kapan permohonannya diajukan.
            Tables\Columns\TextColumn::make('tgl_approval')->label('Tanggal Pengajuan')->date('d M Y')
                ->sortable()->placeholder('—'),

            Tables\Columns\TextColumn::make('nama_user')->label('Nama User')->wrap()
                ->state(fn (Approval $r) => static::kontrakOf($r)?->customer?->full_name ?? '—'),

            Tables\Columns\TextColumn::make('no_unit')->label('No. Unit')
                ->state(fn (Approval $r) => ($u = static::kontrakOf($r)?->unit) ? ($u->kode_unit ?: $u->no_unit ?: '—') : '—'),
        ];
    }

    public static function table(Table $table): Table
    {
        // FORMAT APPROVAL (berlaku di SEMUA menu approval):
        //   No. SPR · Tanggal Pengajuan · Nama User · No. Unit · Status · Detail.
        // Kolom "Jenis" hanya ditambahkan pada daftar gabungan (tanpa jenisScope),
        // karena di menu per-jenis isinya selalu sama.
        //
        // Empat kolom pertama dipisah ke kolomIdentitas() agar menu yang objeknya
        // BUKAN kontrak — Approval BLT & Komisi, yang mengajukan sekumpulan komisi
        // sekaligus — bisa menggantinya tanpa menyalin seluruh definisi tabel.
        return $table->columns([
            ...static::kolomIdentitas(),

            Tables\Columns\TextColumn::make('jenis')->label('Jenis')->badge()
                ->visible(fn () => blank(static::$jenisScope)),

            Tables\Columns\TextColumn::make('status')->label('Status')->badge()
                ->formatStateUsing(fn (Approval $r) => $r->status === 'PENDING'
                    ? 'PENDING · '.(ApprovalService::isSingleLevel($r->jenis) ? 'GM' : 'L'.$r->current_level)
                    : $r->status)
                ->color(fn (Approval $r) => match ($r->status) {
                    'DRAFT' => 'gray', 'PENDING' => 'warning', 'APPROVED' => 'success',
                    'REJECTED' => 'danger', 'EXECUTED' => 'primary', default => 'gray',
                })->description(fn (Approval $r) => $r->executed_result ? Str::limit($r->executed_result, 50) : null),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'DRAFT' => 'Draft', 'PENDING' => 'Pending', 'APPROVED' => 'Approved',
                'REJECTED' => 'Rejected', 'EXECUTED' => 'Executed',
            ]),
            Tables\Filters\SelectFilter::make('jenis')->visible(fn () => blank(static::$jenisScope))->options([
                'DISKON' => 'Diskon', 'DISKON_SPRS' => 'Diskon SPRS', 'BATAL_KONTRAK' => 'Batal kontrak', 'ADJ_HARGA' => 'Adj harga',
                'RESCHEDULE' => 'Reschedule', 'GANTI_NAMA' => 'Ganti nama', 'GANTI_UNIT' => 'Ganti unit',
                'REFUND' => 'Refund', 'BLT' => 'Pengajuan BLT', 'KOMISI' => 'Pengajuan Komisi', 'LAIN' => 'Lain',
            ]),
        ])->headerActions([
            Tables\Actions\CreateAction::make()->label('Ajukan Approval'),
        ])->actions([
            Tables\Actions\ViewAction::make()->label('Detail')->icon('heroicon-o-eye')->color('gray')
                ->modalWidth('5xl')
                ->extraModalFooterActions(self::workflowActions()),
            ...self::workflowActions(),
            Tables\Actions\EditAction::make()->visible(fn (Approval $r) => $r->status === 'DRAFT'),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()]),
        ])->defaultSort('tgl_approval', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListApprovals::route('/'),
        ];
    }
}
