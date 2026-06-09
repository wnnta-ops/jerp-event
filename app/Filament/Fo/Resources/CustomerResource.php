<?php

namespace App\Filament\Fo\Resources;

use App\Filament\Fo\Resources\CustomerResource\Pages;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Services\CustomerQrService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    // URL modul Sales: daftar customer tampil di /fo/sales (bukan /fo/customers).
    // Route name jadi filament.fo.resources.sales.* — rujukan di FoPanelProvider
    // & dashboard ikut disesuaikan.
    protected static ?string $slug = 'sales';

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Marketing & Sales';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Master Customer';

    protected static ?string $modelLabel = 'Customer';

    protected static ?string $pluralModelLabel = 'Master Customer';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Identitas')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('No. Customer')
                        ->maxLength(32)
                        ->disabled()
                        ->dehydrated()
                        ->placeholder('Otomatis')
                        ->helperText('Di-generate otomatis (dipakai sebagai isi QR).'),
                    Forms\Components\Select::make('project_id')
                        ->label('Project')
                        ->relationship('project', 'name')
                        ->searchable()->preload(),
                    Forms\Components\TextInput::make('full_name')
                        ->label('Nama lengkap')
                        ->required()
                        ->maxLength(255)
                        ->helperText('Sumber kebenaran nama — perubahan di sini langsung berlaku di Pilih Unit/launching.'),
                    Forms\Components\TextInput::make('nik')->label('NIK')->maxLength(32),
                    Forms\Components\TextInput::make('npwp')->label('NPWP')->maxLength(32),
                    Forms\Components\Select::make('gender')
                        ->label('Jenis kelamin')
                        ->options(['L' => 'Laki-laki', 'P' => 'Perempuan']),
                    Forms\Components\Select::make('marital_status')
                        ->label('Status kawin')
                        ->options(['BELUM' => 'Belum kawin', 'KAWIN' => 'Kawin', 'CERAI' => 'Cerai']),
                    Forms\Components\DatePicker::make('birth_date')->label('Tanggal lahir'),
                    Forms\Components\TextInput::make('birth_place')->label('Tempat lahir')->maxLength(255),
                    Forms\Components\TextInput::make('religion')->label('Agama')->maxLength(32),
                ]),

            Forms\Components\Section::make('Kontak')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('phone')->label('No. Telp')->tel()->maxLength(32),
                    Forms\Components\TextInput::make('mobile')->label('No. HP')->tel()->maxLength(32),
                    Forms\Components\TextInput::make('email')->label('Email')->email()->maxLength(255),
                ]),

            Forms\Components\Section::make('Alamat (sesuai KTP)')
                ->columns(2)
                ->schema([
                    Forms\Components\Textarea::make('jalan')->label('Jalan')->rows(2)->columnSpanFull(),
                    Forms\Components\TextInput::make('rt_rw')->label('RT/RW')->maxLength(16),
                    Forms\Components\TextInput::make('kelurahan')->label('Kelurahan')->maxLength(255),
                    Forms\Components\TextInput::make('kecamatan')->label('Kecamatan')->maxLength(255),
                    Forms\Components\TextInput::make('city')->label('Kota')->maxLength(255),
                    Forms\Components\TextInput::make('province')->label('Provinsi')->maxLength(255),
                    Forms\Components\TextInput::make('postal_code')->label('Kode pos')->maxLength(16),
                    Forms\Components\Textarea::make('address')->label('Alamat lengkap (fallback)')->rows(2)->columnSpanFull()
                        ->helperText('Dipakai bila rincian per-baris di atas kosong.'),
                ])->collapsed(),

            Forms\Components\Section::make('Pekerjaan & Penghasilan')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('occupation')->label('Pekerjaan')->maxLength(255),
                    Forms\Components\TextInput::make('company_name')->label('Nama perusahaan')->maxLength(255),
                    Forms\Components\TextInput::make('income_monthly')->label('Penghasilan / bulan')->numeric()->prefix('Rp'),
                ])->collapsed(),

            Forms\Components\Section::make('Pendaftaran NUP / Launching')
                ->schema([
                    Forms\Components\Placeholder::make('nup_info')
                        ->hiddenLabel()
                        ->content(function (?Customer $record): HtmlString {
                            if (! $record) {
                                return new HtmlString('<span class="text-gray-400">Customer baru — belum ada pendaftaran NUP.</span>');
                            }
                            $regs = NupRegistration::query()
                                ->where('customer_id', $record->id)
                                ->with('event')
                                ->orderByDesc('id')->get();
                            if ($regs->isEmpty()) {
                                return new HtmlString('<span class="text-gray-400">Customer ini belum mengikuti launching.</span>');
                            }
                            $rows = $regs->map(function (NupRegistration $r) {
                                $nup = $r->no_nup ? '<strong>'.e($r->no_nup).'</strong>' : '<em class="text-gray-400">(belum bernomor)</em>';
                                $ev = e($r->event?->name ?? '—');
                                $st = e($r->status).' · '.e($r->verif_status);

                                return '<tr><td class="pr-4 py-1">'.$nup.'</td><td class="pr-4 py-1">'.$ev.'</td><td class="py-1 text-gray-500">'.$st.'</td></tr>';
                            })->implode('');

                            return new HtmlString(
                                '<table class="text-sm"><thead><tr class="text-gray-400 text-left"><th class="pr-4">No. NUP</th><th class="pr-4">Event</th><th>Status</th></tr></thead><tbody>'.$rows.'</tbody></table>'
                            );
                        }),
                ])->collapsible(),

            Forms\Components\Section::make('Status')
                ->schema([
                    Forms\Components\Toggle::make('is_active')->label('Aktif')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('No. Customer')->searchable()->sortable()->badge()
                    ->placeholder('Belum diverifikasi')
                    ->color(fn (?string $state) => $state ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('full_name')->label('Nama')->searchable()->sortable()->wrap(),
                Tables\Columns\TextColumn::make('phone')->label('No. Telp')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('mobile')->label('No. HP')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('email')->label('Email')->searchable()->placeholder('—')->wrap(),
                Tables\Columns\TextColumn::make('project.name')->label('Project')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('lead_status')
                    ->label('Lead')->badge()->toggleable(isToggledHiddenByDefault: true)
                    ->color(fn (?string $state) => match ($state) {
                        'LEAD' => 'warning', 'PROSPEK' => 'info', 'CUSTOMER' => 'success', default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('is_active')->label('Aktif')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project')
                    ->label('Project')
                    ->relationship('project', 'name')
                    ->searchable()->preload(),
                Tables\Filters\SelectFilter::make('lead_status')->label('Lead status')->options([
                    'LEAD' => 'Lead (belum verifikasi)', 'PROSPEK' => 'Prospek', 'CUSTOMER' => 'Customer',
                ]),
                Tables\Filters\TernaryFilter::make('is_active')->label('Status aktif'),
            ], layout: FiltersLayout::AboveContent)
            ->actions([
                Tables\Actions\ViewAction::make()->label('Lihat'),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('qr')
                    ->label('QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->modalHeading(fn (Customer $record) => 'QR Customer — '.$record->full_name)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalContent(function (Customer $record) {
                        $qr = app(CustomerQrService::class);
                        $code = $qr->ensureCode($record);
                        $reg = NupRegistration::query()->where('customer_id', $record->id)->orderByDesc('id')->first();

                        return view('filament.fo.customer-qr-modal', [
                            'customer' => $record,
                            'code' => $code,
                            'svg' => $qr->svg($code, 240),
                            'noNup' => $reg?->no_nup,
                        ]);
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
