<?php

namespace App\Modules\FO\Shared\Integrations\Ai;

interface AiAssistantGatewayInterface
{
    /**
     * Kirim satu giliran percakapan (beserta definisi tool) ke model dan kembalikan
     * jawaban / permintaan pemanggilan tool.
     *
     * @param  array<int,array<string,mixed>>  $messages  Riwayat gaya Anthropic (role + content).
     * @param  array<int,array<string,mixed>>  $tools     Definisi tool (name, description, input_schema).
     * @param  string  $system  Instruksi sistem.
     */
    public function chat(array $messages, array $tools, string $system): AiResult;

    /** True bila terhubung ke model AI sungguhan (bukan fallback kata kunci). */
    public function isRealAi(): bool;
}
