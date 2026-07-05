<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Shared\Assistant\DataSearchTool;
use App\Modules\FO\Shared\Assistant\Exports\CustomerSearchExport;
use App\Modules\FO\Shared\Assistant\SearchResult;
use App\Modules\FO\Shared\Integrations\Ai\AiAssistantGatewayInterface;
use App\Modules\FO\Shared\Integrations\Ai\NullAiAssistantGateway;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Asisten AI FO — tanya-jawab & pencarian data customer. Bila user minta,
 * hasil pencarian bisa diunduh sebagai Excel.
 *
 * Alur (agent loop): pesan user → model (Claude / fallback) → bila model memanggil
 * tool cari_customer, jalankan query READ-ONLY → kirim JUMLAH-nya balik ke model →
 * model merangkum. Baris detail (PII) ditahan lokal untuk tabel & Excel.
 */
class AsistenAi extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $title = 'Asisten AI';

    protected static ?string $navigationLabel = 'Asisten AI';

    protected static ?string $slug = 'asisten-ai';

    protected static string $view = 'filament.fo.pages.asisten-ai';

    /** Bubble tampilan: [['role'=>'user'|'assistant','html'=>string], ...]. */
    public array $chat = [];

    /** Riwayat gaya Anthropic (untuk multi-giliran + tool). */
    public array $api = [];

    public string $prompt = '';

    /** @var array<int,string>|null */
    public ?array $lastCols = null;

    /** @var array<int,array<string,mixed>>|null */
    public ?array $lastRows = null;

    /** Panel preview (artifact) terbuka? Muncul hanya saat ada dokumen/hasil. */
    public bool $showPreview = false;

    public bool $aiReal = false;

    public function mount(): void
    {
        $this->aiReal = app(AiAssistantGatewayInterface::class)->isRealAi();
        $this->chat[] = [
            'role' => 'assistant',
            'html' => $this->md('Halo! Saya asisten data FO. Coba tanyakan, misalnya: '
                .'*"cari customer booking Juni 2026"* atau *"daftar customer proyek RAYA yang belum bayar"*. '
                .'Kalau butuh Excel-nya, tinggal minta atau klik tombol **Download Excel** di bawah tabel hasil.'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('kembali')
                ->label('Dashboard')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url('/dashboard'),
            Action::make('bersihkan')
                ->label('Bersihkan')
                ->icon('heroicon-o-trash')
                ->color('gray')
                ->action(function () {
                    $this->chat = [];
                    $this->api = [];
                    $this->lastCols = null;
                    $this->lastRows = null;
                    $this->showPreview = false;
                    $this->mount();
                }),
        ];
    }

    public function kirim(): void
    {
        $text = trim($this->prompt);
        if ($text === '') {
            return;
        }
        $this->prompt = '';
        $this->chat[] = ['role' => 'user', 'html' => nl2br(e($text))];
        $this->api[] = ['role' => 'user', 'content' => $text];

        try {
            $this->jalankanAgen();
        } catch (\Throwable $ex) {
            report($ex);
            $this->chat[] = ['role' => 'assistant', 'html' => '<span class="text-danger-600">Maaf, terjadi kendala saat memproses. Coba lagi.</span>'];
        }
    }

    public function bukaPreview(): void
    {
        $this->showPreview = true;
    }

    public function tutupPreview(): void
    {
        $this->showPreview = false;
    }

    /** Unduh hasil pencarian terakhir sebagai Excel. */
    public function unduhExcel()
    {
        if (! $this->lastRows) {
            return null;
        }

        return Excel::download(
            new CustomerSearchExport($this->lastCols ?? [], $this->lastRows),
            'hasil-pencarian-'.now()->format('Ymd-His').'.xlsx',
        );
    }

    /** Loop agent: panggil model, jalankan tool, ulangi sampai model berhenti. */
    protected function jalankanAgen(): void
    {
        $gateway = app(AiAssistantGatewayInterface::class);
        $tools = [app(DataSearchTool::class)->definition()];
        $system = $this->systemPrompt();
        $fallbackNotified = false;

        for ($i = 0; $i < 4; $i++) {
            try {
                $res = $gateway->chat($this->api, $tools, $system);
            } catch (\Throwable $e) {
                report($e);
                // Provider AI gagal (mis. kuota Gemini habis) → beralih ke mode dasar
                // agar asisten tetap bisa mencari data, bukan mati total.
                $gateway = app(NullAiAssistantGateway::class);
                if (! $fallbackNotified) {
                    $this->chat[] = ['role' => 'assistant', 'html' => $this->md('_Layanan AI utama sedang tidak tersedia — beralih ke **mode dasar** (pencarian kata kunci)._')];
                    $fallbackNotified = true;
                }
                $res = $gateway->chat($this->api, $tools, $system);
            }

            // Rekam giliran asisten ke riwayat API (blok mentah bila ada, else teks).
            if ($res->content !== []) {
                $this->api[] = ['role' => 'assistant', 'content' => $res->content];
            } elseif ($res->text !== '') {
                $this->api[] = ['role' => 'assistant', 'content' => $res->text];
            }

            // Tampilkan teks yang keluar giliran ini (jawaban / kalimat antara).
            if ($res->text !== '') {
                $this->chat[] = ['role' => 'assistant', 'html' => $this->md($res->text)];
            }

            if (! $res->wantsTool()) {
                return; // selesai
            }

            // Jalankan tiap tool → kumpulkan tool_result untuk giliran berikutnya.
            $toolResults = [];
            foreach ($res->toolCalls as $call) {
                $toolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $call['id'],
                    'content' => $this->jalankanTool($call['name'], $call['input']),
                ];
            }
            $this->api[] = ['role' => 'user', 'content' => $toolResults];
        }
    }

    /** @param array<string,mixed> $input */
    protected function jalankanTool(string $name, array $input): string
    {
        if ($name === DataSearchTool::NAME) {
            // Gerbang READ-ONLY per hak akses ada di dalam DataSearchTool::run().
            $outcome = app(DataSearchTool::class)->run($input, auth()->user());

            if ($outcome instanceof SearchResult) {
                $this->lastCols = $outcome->kolom;
                $this->lastRows = $outcome->baris;
                $this->showPreview = true; // dokumen tersedia → buka panel preview

                return $outcome->toToolResultJson();
            }

            // Error (akses_ditolak / entitas tak dikenal) — jangan ubah preview.
            return (string) json_encode($outcome, JSON_UNESCAPED_UNICODE);
        }

        return (string) json_encode(['error' => 'Tool tidak dikenal: '.$name]);
    }

    protected function systemPrompt(): string
    {
        $today = Carbon::today()->format('d F Y');

        return <<<TXT
        Anda adalah Asisten Data untuk staf Front Office developer properti (J-ERP).
        Tugas: membantu mencari data customer memakai tool `cari_customer`.

        Aturan:
        - Anda HANYA membaca & menyajikan data (read-only). Anda TIDAK bisa membuat, mengubah, menghapus,
          atau menyetujui apa pun. Bila diminta hal itu, jelaskan Anda hanya dapat menampilkan data.
        - SELALU gunakan tool `cari_data` (pilih entitas: customer, kontrak, pembayaran, tagihan) untuk
          pertanyaan pencarian/daftar data. JANGAN mengarang data.
        - Bila tool mengembalikan error "akses_ditolak", sampaikan dengan sopan bahwa pengguna tidak memiliki
          hak akses untuk data tersebut — jangan tampilkan data apa pun.
        - Jawab ringkas dalam Bahasa Indonesia.
        - Setelah tool memberi hasil, sebutkan JUMLAH data yang ditemukan dan ingatkan user bisa menekan
          tombol "Download Excel" di bawah tabel untuk mengunduh.
        - Detail baris tidak dikirim ke Anda demi privasi; itu ditampilkan langsung ke user sebagai tabel.
        - Hari ini tanggal {$today} (pakai untuk memahami periode seperti "bulan ini", "tahun ini").
        TXT;
    }

    protected function md(string $text): string
    {
        return Str::markdown($text);
    }
}
