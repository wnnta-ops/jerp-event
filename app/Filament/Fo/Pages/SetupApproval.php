<?php

namespace App\Filament\Fo\Pages;

use App\Models\User;
use App\Modules\FO\Approval\Models\ApprovalSetup;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Setup Approval — tunjuk USER approver Level 1 & Level 2 per jenis approval.
 *
 *  - L1 diisi, L2 kosong → SINGLE LEVEL: persetujuan user L1 langsung final &
 *    tereksekusi (L2 tidak berjalan).
 *  - L1 & L2 diisi → DUA LEVEL berjalan penuh (L1 dulu, lalu L2 final).
 *  - Keduanya kosong → ikut aturan lama (permission per jenis + final_roles).
 */
class SetupApproval extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-plus';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Setup Approval';

    protected static ?string $navigationLabel = 'Setup Approval';

    protected static string $view = 'filament.fo.pages.setup-approval';

    protected static ?string $slug = 'setup-approval';

    public ?array $data = [];

    public function mount(): void
    {
        $rows = ApprovalSetup::query()->get()->keyBy('jenis');
        $fill = [];
        foreach (array_keys(ApprovalSetup::JENIS_LABELS) as $jenis) {
            $fill[$jenis] = [
                'l1' => $rows[$jenis]->level1_user_id ?? null,
                'l2' => $rows[$jenis]->level2_user_id ?? null,
                'bypass' => (bool) ($rows[$jenis]->bypass_saat_event ?? false),
            ];
        }
        $this->form->fill($fill);
    }

    public function form(Form $form): Form
    {
        $userOptions = fn (string $search) => User::query()
            ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
            ->orderBy('name')->limit(30)->pluck('name', 'id')->all();
        $userLabel = fn ($value) => $value ? User::find($value)?->name : null;

        $sections = [
            Forms\Components\Placeholder::make('petunjuk')->hiddenLabel()
                ->content('Tunjuk user approver per jenis. L1 diisi & L2 kosong = cukup persetujuan L1 (langsung final). L1 & L2 diisi = dua tahap berjalan. Keduanya kosong = ikut aturan hak akses lama. Toggle "Bypass saat Event NUP" = selama ada Event NUP yang sedang berlangsung, pengajuan jenis itu LANGSUNG tereksekusi tanpa approval.'),
        ];
        foreach (ApprovalSetup::JENIS_LABELS as $jenis => $label) {
            $sections[] = Forms\Components\Section::make($label)->columns(3)->compact()->schema([
                Forms\Components\Select::make("{$jenis}.l1")->label('Approval Level 1')
                    ->searchable()->getSearchResultsUsing($userOptions)->getOptionLabelUsing($userLabel)
                    ->options(fn () => User::orderBy('name')->limit(30)->pluck('name', 'id')->all())
                    ->placeholder('— tidak ditunjuk —'),
                Forms\Components\Select::make("{$jenis}.l2")->label('Approval Level 2 (final)')
                    ->searchable()->getSearchResultsUsing($userOptions)->getOptionLabelUsing($userLabel)
                    ->options(fn () => User::orderBy('name')->limit(30)->pluck('name', 'id')->all())
                    ->placeholder('— tidak ditunjuk —')
                    ->helperText('Kosongkan bila cukup persetujuan Level 1.'),
                Forms\Components\Toggle::make("{$jenis}.bypass")->label('Bypass saat Event NUP')
                    ->inline(false)
                    ->helperText('Tanpa approval selama event NUP buka.'),
            ]);
        }

        return $form->statePath('data')->schema($sections);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bypassOn')->label('Bypass: Hidupkan Semua')->icon('heroicon-o-bolt')->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Hidupkan bypass untuk SEMUA jenis?')
                ->modalDescription('Selama ada Event NUP yang sedang berlangsung, SEMUA jenis pengajuan akan langsung tereksekusi tanpa approval. Penunjukan user L1/L2 tidak berubah.')
                ->action(fn () => $this->bypassSemua(true)),
            Action::make('bypassOff')->label('Bypass: Matikan Semua')->icon('heroicon-o-no-symbol')->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Matikan bypass untuk semua jenis?')
                ->modalDescription('Semua jenis kembali ke alur approval normal. Penunjukan user L1/L2 tidak berubah.')
                ->action(fn () => $this->bypassSemua(false)),
        ];
    }

    /** Nyalakan/matikan toggle "Bypass saat Event NUP" untuk SEMUA jenis sekaligus. */
    public function bypassSemua(bool $nyala): void
    {
        foreach (array_keys(ApprovalSetup::JENIS_LABELS) as $jenis) {
            if ($nyala) {
                $row = ApprovalSetup::withTrashed()->updateOrCreate(
                    ['jenis' => $jenis],
                    ['bypass_saat_event' => true],
                );
                if ($row->trashed()) {
                    $row->restore();
                }

                continue;
            }

            $row = ApprovalSetup::query()->where('jenis', $jenis)->first();
            if (! $row) {
                continue;
            }
            // Masih ada penunjukan user → pertahankan barisnya, matikan bypass saja.
            $row->hasAssignedUsers()
                ? $row->update(['bypass_saat_event' => false])
                : $row->delete();
        }

        $this->mount(); // muat ulang nilai form dari DB

        Notification::make()->success()
            ->title($nyala ? 'Bypass DINYALAKAN untuk semua jenis' : 'Bypass dimatikan untuk semua jenis')
            ->body($nyala
                ? 'Berlaku selama ada Event NUP yang sedang berlangsung — di luar itu alur approval tetap normal.'
                : 'Semua jenis kembali ke alur approval normal.')
            ->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Simpan')->submit('save')];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (array_keys(ApprovalSetup::JENIS_LABELS) as $jenis) {
            $l1 = $data[$jenis]['l1'] ?? null;
            $l2 = $data[$jenis]['l2'] ?? null;
            $bypass = (bool) ($data[$jenis]['bypass'] ?? false);
            // Semua kosong & bypass mati → hapus baris agar fallback aturan lama.
            if ($l1 === null && $l2 === null && ! $bypass) {
                ApprovalSetup::query()->where('jenis', $jenis)->delete();

                continue;
            }
            // withTrashed + restore: baris pernah dihapus (soft delete) lalu diisi
            // lagi → jangan tabrak unique(tenant,jenis).
            $row = ApprovalSetup::withTrashed()->updateOrCreate(
                ['jenis' => $jenis],
                ['level1_user_id' => $l1, 'level2_user_id' => $l2, 'bypass_saat_event' => $bypass],
            );
            if ($row->trashed()) {
                $row->restore();
            }
        }

        Notification::make()->success()->title('Setup Approval disimpan')
            ->body('Penunjukan approver berlaku untuk pengajuan yang disubmit setelah ini.')->send();
    }
}
