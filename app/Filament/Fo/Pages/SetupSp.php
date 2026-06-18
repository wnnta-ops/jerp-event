<?php

namespace App\Filament\Fo\Pages;

use App\Settings\CollectionSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Setup SP — template subjek & isi pesan untuk reminder dan Surat Peringatan
 * I/II/III (dipakai Email & WA). Placeholder yang didukung tampil di helper.
 */
class SetupSp extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Setup SP';

    protected static ?string $navigationLabel = 'Setup SP';

    protected static string $view = 'filament.fo.pages.setup-sp';

    protected static ?string $slug = 'collection-setup-sp';

    public ?array $data = [];

    public function mount(): void
    {
        $s = app(CollectionSettings::class);
        $this->form->fill([
            'reminder_subject' => $s->reminder_subject,
            'reminder_body' => $s->reminder_body,
            'sp1_subject' => $s->sp1_subject,
            'sp1_body' => $s->sp1_body,
            'sp2_subject' => $s->sp2_subject,
            'sp2_body' => $s->sp2_body,
            'sp3_subject' => $s->sp3_subject,
            'sp3_body' => $s->sp3_body,
            'wa_template_reminder' => $s->wa_template_reminder,
            'wa_template_sp1' => $s->wa_template_sp1,
            'wa_template_sp2' => $s->wa_template_sp2,
            'wa_template_sp3' => $s->wa_template_sp3,
        ]);
    }

    public function form(Form $form): Form
    {
        $placeholders = 'Placeholder: {nama} {no_kontrak} {unit} {tagihan} {jatuh_tempo} {nominal} {sisa} {hari_telat}';

        return $form->statePath('data')->schema([
            Forms\Components\Section::make('Reminder (sebelum jatuh tempo)')->schema([
                Forms\Components\TextInput::make('reminder_subject')->label('Subjek')->required(),
                Forms\Components\Textarea::make('reminder_body')->label('Isi pesan')->rows(6)->required()->helperText($placeholders),
                Forms\Components\TextInput::make('wa_template_reminder')->label('ID Template WhatsApp (opsional)')
                    ->helperText('Diisi bila gateway WA berbasis template (Qontak). Kosong = pakai log/stub.'),
            ])->collapsible(),

            Forms\Components\Section::make('Surat Peringatan I')->schema([
                Forms\Components\TextInput::make('sp1_subject')->label('Subjek')->required(),
                Forms\Components\Textarea::make('sp1_body')->label('Isi pesan')->rows(6)->required()->helperText($placeholders),
                Forms\Components\TextInput::make('wa_template_sp1')->label('ID Template WhatsApp (opsional)'),
            ])->collapsible()->collapsed(),

            Forms\Components\Section::make('Surat Peringatan II')->schema([
                Forms\Components\TextInput::make('sp2_subject')->label('Subjek')->required(),
                Forms\Components\Textarea::make('sp2_body')->label('Isi pesan')->rows(6)->required()->helperText($placeholders),
                Forms\Components\TextInput::make('wa_template_sp2')->label('ID Template WhatsApp (opsional)'),
            ])->collapsible()->collapsed(),

            Forms\Components\Section::make('Surat Peringatan III (Terakhir)')->schema([
                Forms\Components\TextInput::make('sp3_subject')->label('Subjek')->required(),
                Forms\Components\Textarea::make('sp3_body')->label('Isi pesan')->rows(6)->required()->helperText($placeholders),
                Forms\Components\TextInput::make('wa_template_sp3')->label('ID Template WhatsApp (opsional)'),
            ])->collapsible()->collapsed(),
        ]);
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Simpan')->submit('save')];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $s = app(CollectionSettings::class);
        foreach ($data as $key => $value) {
            $s->{$key} = $value;
        }
        $s->save();

        Notification::make()->title('Template SP disimpan')->success()->send();
    }
}
