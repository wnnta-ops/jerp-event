<?php

namespace App\Modules\FO\Shared\Integrations\Ai;

use Carbon\Carbon;

/**
 * Fallback tanpa API key: penerjemah kata kunci sederhana (bukan AI sungguhan).
 *
 * Meniru perilaku model — ketika pertanyaan tampak seperti pencarian data, ia
 * mengembalikan blok `tool_use` untuk `cari_customer` (persis seperti Claude),
 * sehingga alur eksekusi tool di halaman identik. Setelah tool dijalankan
 * (pesan terakhir berisi tool_result), ia merangkum jumlah hasil.
 *
 * Tujuannya agar modul tetap fungsional sebelum ANTHROPIC_API_KEY diisi.
 */
class NullAiAssistantGateway implements AiAssistantGatewayInterface
{
    private const BULAN = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4, 'mei' => 5, 'juni' => 6,
        'juli' => 7, 'agustus' => 8, 'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
    ];

    public function chat(array $messages, array $tools, string $system): AiResult
    {
        $last = end($messages) ?: [];

        // Giliran lanjutan: pesan terakhir membawa tool_result → rangkum jumlah hasil.
        if ($this->hasToolResult($last)) {
            return $this->summarize($last);
        }

        $q = strtolower($this->lastUserText($messages));

        // Bukan pertanyaan data → beri arahan.
        if (! $this->looksLikeSearch($q)) {
            return new AiResult('end_turn', [], $this->helpText(), []);
        }

        $input = $this->parse($q);
        $id = 'toolu_null_'.count($messages);

        return new AiResult(
            stopReason: 'tool_use',
            content: [['type' => 'tool_use', 'id' => $id, 'name' => 'cari_data', 'input' => $input]],
            text: '',
            toolCalls: [['id' => $id, 'name' => 'cari_data', 'input' => $input]],
        );
    }

    public function isRealAi(): bool
    {
        return false;
    }

    /** @param array<string,mixed> $msg */
    private function hasToolResult(array $msg): bool
    {
        foreach ((array) ($msg['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? null) === 'tool_result') {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $msg */
    private function summarize(array $msg): AiResult
    {
        $count = 0;
        foreach ((array) ($msg['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? null) === 'tool_result') {
                $data = json_decode((string) ($b['content'] ?? ''), true);
                $count = (int) ($data['jumlah'] ?? 0);
            }
        }

        $text = $count > 0
            ? "Ditemukan {$count} data yang cocok. Klik tombol **Download Excel** di bawah tabel untuk mengunduh."
            : 'Tidak ada data yang cocok dengan kriteria tersebut. Coba ubah periode/kata kuncinya.';

        return new AiResult('end_turn', [], $text, []);
    }

    /** @param array<int,array<string,mixed>> $messages */
    private function lastUserText(array $messages): string
    {
        for ($i = count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? null) === 'user' && is_string($messages[$i]['content'] ?? null)) {
                return (string) $messages[$i]['content'];
            }
        }

        return '';
    }

    private function looksLikeSearch(string $q): bool
    {
        foreach (['cari', 'customer', 'pelanggan', 'data', 'daftar', 'list', 'booking', 'tampilkan', 'excel',
            'kontrak', 'spr', 'tagihan', 'tunggakan', 'nunggak', 'pembayaran', 'tts', 'bayar',
            'reservasi', 'unit', 'kavling', 'sales', 'agen', 'kwitansi', 'bkm', 'faktur', 'giro', 'bilyet',
            'refund', 'transfer', 'anonim', 'gmc', 'gimmick', 'komisi', 'blt', 'reward', 'surat', 'sp',
            'nup', 'event', 'pengalihan', 'pindah', 'memo'] as $kw) {
            if (str_contains($q, $kw)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,mixed> */
    private function parse(string $q): array
    {
        $input = ['entitas' => $this->detectEntitas($q)];

        // Periode: "juni 2026" / "bulan juni 2026" → rentang 1 bulan.
        foreach (self::BULAN as $nama => $bln) {
            if (str_contains($q, $nama) && preg_match('/'.$nama.'\s*(\d{4})/', $q, $m)) {
                $tgl = Carbon::create((int) $m[1], $bln, 1);
                $input['dari'] = $tgl->format('Y-m-d');
                $input['sampai'] = $tgl->copy()->endOfMonth()->format('Y-m-d');
                break;
            }
        }
        // Hanya tahun: "tahun 2026" / "2026" (bila belum ada periode bulan).
        if (! isset($input['dari']) && preg_match('/\b(20\d{2})\b/', $q, $m)) {
            $input['dari'] = $m[1].'-01-01';
            $input['sampai'] = $m[1].'-12-31';
        }

        // Status tunggal (makna tergantung entitas).
        if (preg_match('/belum\s*bayar|nunggak|tunggakan|menunggak/', $q)) {
            $input['status'] = 'NUNGGAK';
        } elseif (str_contains($q, 'lunas')) {
            $input['status'] = 'LUNAS';
        } elseif (str_contains($q, 'batal')) {
            $input['status'] = 'BATAL';
        } elseif (str_contains($q, 'booking') || str_contains($q, 'booked')) {
            $input['status'] = 'BOOKING';
        }

        // Proyek: kata setelah "proyek"/"project".
        if (preg_match('/proyek\s+([a-z0-9\-]+)/i', $q, $m)) {
            $input['proyek'] = $m[1];
        }

        return $input;
    }

    /** Tebak entitas dari kata kunci (pola paling spesifik menang; default customer). */
    private function detectEntitas(string $q): string
    {
        $map = [
            'pindah\s*unit' => 'pindah_unit',
            'pengalihan' => 'pengalihan_hak',
            'anonim' => 'transfer_anonim',
            'bilyet|giro' => 'bilyet_giro',
            'faktur' => 'faktur',
            'kwitansi|bkm' => 'bkm',
            'reservasi' => 'reservasi',
            'gmc|gimmick' => 'gmc',
            'komisi' => 'komisi',
            '\bblt\b' => 'blt',
            'reward' => 'reward',
            'surat\s*sp|peringatan' => 'collection',
            'event' => 'nup_event',
            '\bnup\b' => 'nup',
            'refund|cashback' => 'refund',
            '\bmemo\b' => 'memo',
            'kavling|\bunit\b' => 'unit',
            'sales|\bagen' => 'agent',
            'tunggakan|tagihan|angsuran|cicilan|jatuh\s*tempo' => 'tagihan',
            'kontrak|\bspr\b' => 'kontrak',
            '\btts\b|pembayaran' => 'pembayaran',
        ];
        foreach ($map as $pat => $ent) {
            if (preg_match('/'.$pat.'/', $q)) {
                return $ent;
            }
        }

        return 'customer';
    }

    private function helpText(): string
    {
        return "Mode dasar (tanpa API key) aktif. Saya bisa membantu mencari **data customer**. "
            ."Contoh: *\"cari customer booking Juni 2026\"* atau *\"daftar customer proyek RAYA yang belum bayar\"*. "
            .'Isi `ANTHROPIC_API_KEY` untuk mengaktifkan mode AI penuh (tanya-jawab bebas).';
    }
}
