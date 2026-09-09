<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Shared\Assistant\DataSearchTool;
use App\Modules\FO\Shared\Assistant\Exports\CustomerSearchExport;
use App\Modules\FO\Shared\Assistant\SearchResult;
use App\Modules\FO\Shared\Integrations\Ai\AiAssistantGatewayInterface;
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
    use MematuhiIzinHalaman;

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
            'html' => $this->md('Halo! Saya AI Asisten Anda'),
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

        $this->dispatch('chat-updated'); // picu auto-scroll ke bawah
    }

    /** Kirim cepat dari chip saran. */
    public function kirimCepat(string $text): void
    {
        $this->prompt = $text;
        $this->kirim();
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

        for ($i = 0; $i < 4; $i++) {
            try {
                $res = $gateway->chat($this->api, $tools, $system);
            } catch (\Throwable $e) {
                report($e);
                // AI-only: bila layanan AI gagal (mis. batas kuota), beri pesan jelas —
                // TIDAK turun ke mode dasar/kata kunci.
                $this->chat[] = ['role' => 'assistant', 'html' => $this->md('Maaf, layanan AI sedang tidak tersedia sesaat (kemungkinan batas kuota). Silakan coba lagi beberapa saat lagi. 🙏')];

                return;
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
        Kamu adalah asisten AI yang ramah, cerdas, dan enak diajak ngobrol untuk staf
        Front Office di J-ERP (ERP developer properti). Berbincanglah senatural mungkin
        seperti asisten AI pada umumnya — boleh menyapa, menjelaskan, memberi saran,
        membantu menyusun kalimat, dan menjawab pertanyaan umum seputar pekerjaan mereka.
        Bersikap hangat dan gunakan bahasa yang mengalir, bukan jawaban kaku.

        Kemampuan khusus: kamu bisa MENCARI & MENAMPILKAN data Front Office lewat tool
        `cari_data` (customer, kontrak, tagihan, pembayaran/TTS, reservasi, unit, sales/agent,
        kwitansi, faktur, bilyet giro, refund, transfer anonim, gmc, komisi, blt, reward,
        surat SP, NUP, pengalihan hak, pindah unit, memo). Panggil tool ini setiap user ingin
        mencari/melihat/menampilkan/mendaftar data — jangan pernah mengarang data, selalu ambil
        dari tool. Untuk obrolan biasa atau pertanyaan umum, jawab langsung tanpa tool.

        Setelah menampilkan hasil data, sebut jumlahnya dengan natural dan ingatkan user bahwa
        hasilnya bisa diunduh lewat tombol "Download Excel" di bawah tabel.

        Penting:
        - Kamu hanya bisa MEMBACA & menyajikan data (read-only) — tidak bisa membuat, mengubah,
          menghapus, atau menyetujui apa pun. Sampaikan dengan ramah jika diminta hal itu.
        - Detail baris tidak dikirim kepadamu demi privasi; itu tampil langsung ke user sebagai tabel.
          Jadi jangan berpura-pura tahu isi barisnya — cukup rujuk ke tabel & tombol Excel.
        - Jika tool mengembalikan "akses_ditolak", sampaikan dengan sopan bahwa user belum punya
          hak akses ke data tersebut.

        Jawab dalam Bahasa Indonesia yang natural. Hari ini tanggal {$today}
        (pakai untuk memahami "bulan ini", "tahun ini", dsb).
        TXT;
    }

    protected function md(string $text): string
    {
        return Str::markdown($text);
    }
}
