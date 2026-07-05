<?php

namespace App\Modules\FO\Shared\Integrations\Ai;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gateway Claude (Anthropic Messages API) dengan dukungan tool-calling.
 *
 * @see https://docs.anthropic.com/en/api/messages
 */
class ClaudeAiGateway implements AiAssistantGatewayInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.anthropic.com',
        private readonly string $version = '2023-06-01',
        private readonly int $maxTokens = 1024,
    ) {}

    public function chat(array $messages, array $tools, string $system): AiResult
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages,
        ];
        if ($system !== '') {
            $payload['system'] = $system;
        }
        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->version,
            'content-type' => 'application/json',
        ])->timeout(60)->post($this->baseUrl.'/v1/messages', $payload);

        if ($response->failed()) {
            throw new RuntimeException('Claude API gagal: '.$response->body());
        }

        $content = (array) $response->json('content', []);
        $text = '';
        $toolCalls = [];
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= (string) ($block['text'] ?? '');
            } elseif (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return new AiResult(
            stopReason: (string) $response->json('stop_reason', 'end_turn'),
            content: $content,
            text: trim($text),
            toolCalls: $toolCalls,
        );
    }

    public function isRealAi(): bool
    {
        return true;
    }
}
