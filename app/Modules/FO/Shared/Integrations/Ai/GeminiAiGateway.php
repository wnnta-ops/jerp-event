<?php

namespace App\Modules\FO\Shared\Integrations\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gateway Google Gemini (Generative Language API) dengan function-calling.
 *
 * Adapter: menerima/mengembalikan pesan dalam format Anthropic (dipakai halaman
 * Asisten), diterjemahkan ke/dari format Gemini di dalam kelas ini — sehingga
 * riwayat percakapan di halaman tetap konsisten lintas provider.
 *
 * @see https://ai.google.dev/gemini-api/docs/function-calling
 */
class GeminiAiGateway implements AiAssistantGatewayInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://generativelanguage.googleapis.com',
        private readonly int $maxTokens = 1024,
    ) {}

    public function chat(array $messages, array $tools, string $system): AiResult
    {
        $payload = [
            'contents' => $this->toGeminiContents($messages),
            'generationConfig' => ['maxOutputTokens' => $this->maxTokens],
        ];
        if ($system !== '') {
            $payload['system_instruction'] = ['parts' => [['text' => $system]]];
        }
        if ($tools !== []) {
            $payload['tools'] = [['function_declarations' => array_map(fn ($t) => [
                'name' => $t['name'],
                'description' => $t['description'] ?? '',
                'parameters' => $this->toGeminiSchema($t['input_schema'] ?? ['type' => 'object']),
            ], $tools)]];
        }

        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey,
            'content-type' => 'application/json',
        ])->timeout(60)->post($this->baseUrl.'/v1beta/models/'.$this->model.':generateContent', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Gemini API gagal: '.$response->body());
        }

        $parts = (array) $response->json('candidates.0.content.parts', []);
        $text = '';
        $toolCalls = [];
        $content = [];
        $n = 0;
        foreach ($parts as $p) {
            if (isset($p['text'])) {
                $text .= (string) $p['text'];
                $content[] = ['type' => 'text', 'text' => (string) $p['text']];
            } elseif (isset($p['functionCall'])) {
                $id = 'call_'.(++$n);
                $args = (array) ($p['functionCall']['args'] ?? []);
                $name = (string) ($p['functionCall']['name'] ?? '');
                $toolCalls[] = ['id' => $id, 'name' => $name, 'input' => $args];
                $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $args];
            }
        }

        return new AiResult(
            stopReason: $toolCalls !== [] ? 'tool_use' : 'end_turn',
            content: $content,
            text: trim($text),
            toolCalls: $toolCalls,
        );
    }

    public function isRealAi(): bool
    {
        return true;
    }

    /**
     * Terjemahkan pesan format Anthropic → `contents` Gemini.
     *
     * @param  array<int,array<string,mixed>>  $messages
     * @return array<int,array<string,mixed>>
     */
    private function toGeminiContents(array $messages): array
    {
        $contents = [];
        $idToName = []; // tool_use_id → nama fungsi (untuk mencocokkan functionResponse)

        foreach ($messages as $msg) {
            $role = $msg['role'] ?? 'user';
            $c = $msg['content'] ?? '';

            if ($role === 'assistant') {
                $parts = [];
                if (is_string($c)) {
                    $parts[] = ['text' => $c];
                } else {
                    foreach ((array) $c as $b) {
                        if (($b['type'] ?? '') === 'text') {
                            $parts[] = ['text' => (string) $b['text']];
                        } elseif (($b['type'] ?? '') === 'tool_use') {
                            $idToName[$b['id'] ?? ''] = $b['name'] ?? '';
                            $parts[] = ['functionCall' => [
                                'name' => (string) ($b['name'] ?? ''),
                                'args' => (object) ((array) ($b['input'] ?? [])),
                            ]];
                        }
                    }
                }
                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }

                continue;
            }

            // role user (string biasa, atau array blok tool_result / text)
            $parts = [];
            if (is_string($c)) {
                $parts[] = ['text' => $c];
            } else {
                foreach ((array) $c as $b) {
                    if (($b['type'] ?? '') === 'tool_result') {
                        $resp = json_decode((string) ($b['content'] ?? ''), true);
                        if (! is_array($resp)) {
                            $resp = ['result' => (string) ($b['content'] ?? '')];
                        }
                        $parts[] = ['functionResponse' => [
                            'name' => (string) ($idToName[$b['tool_use_id'] ?? ''] ?? 'cari_customer'),
                            'response' => $resp,
                        ]];
                    } elseif (($b['type'] ?? '') === 'text') {
                        $parts[] = ['text' => (string) $b['text']];
                    }
                }
            }
            if ($parts !== []) {
                $contents[] = ['role' => 'user', 'parts' => $parts];
            }
        }

        return $contents;
    }

    /**
     * Terjemahkan JSON Schema (gaya Anthropic) → Schema Gemini (type UPPERCASE).
     *
     * @param  array<string,mixed>  $schema
     * @return array<string,mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        $out = ['type' => strtoupper((string) ($schema['type'] ?? 'object'))];
        if (isset($schema['description'])) {
            $out['description'] = $schema['description'];
        }
        if (isset($schema['enum'])) {
            $out['enum'] = array_values($schema['enum']);
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $out['items'] = $this->toGeminiSchema($schema['items']);
        }
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $out['properties'] = [];
            foreach ($schema['properties'] as $k => $v) {
                $out['properties'][$k] = $this->toGeminiSchema((array) $v);
            }
        }
        if (! empty($schema['required'])) {
            $out['required'] = array_values($schema['required']);
        }

        return $out;
    }
}
