<?php

namespace App\Modules\FO\Shared\Integrations\Ai;

/**
 * Hasil satu giliran (turn) percakapan dengan model.
 *
 * - $content : blok konten mentah gaya Anthropic (text + tool_use) untuk dirangkai
 *              kembali ke riwayat pesan pada giliran berikutnya.
 * - $text    : gabungan seluruh blok teks (jawaban untuk ditampilkan).
 * - $toolCalls: daftar panggilan tool: [['id'=>, 'name'=>, 'input'=>[]], ...].
 */
final class AiResult
{
    /**
     * @param  array<int,array<string,mixed>>  $content
     * @param  array<int,array{id:string,name:string,input:array<string,mixed>}>  $toolCalls
     */
    public function __construct(
        public readonly string $stopReason,
        public readonly array $content,
        public readonly string $text,
        public readonly array $toolCalls,
    ) {}

    public function wantsTool(): bool
    {
        return $this->toolCalls !== [];
    }
}
