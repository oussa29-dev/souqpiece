<?php
require_once __DIR__ . '/LlmProvider.php';

// Groq exposes an OpenAI-compatible chat-completions API, so this adapter
// also serves as the template for a future OpenAIProvider - only the base
// URL and auth header would differ.
class GroqProvider implements LlmProvider
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'openai/gpt-oss-120b')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function converse(
        string $systemPrompt,
        array $history,
        string $userMessage,
        array $toolSchemas,
        callable $toolDispatcher,
        int $maxToolRounds = 5
    ): array {
        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $turn['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // OpenAI-style function schema is standard JSON Schema, same as
        // Anthropic's - only Gemini needed a type-case translation.
        $tools = array_map(fn($t) => [
            'type' => 'function',
            'function' => [
                'name' => $t['name'],
                'description' => $t['description'],
                'parameters' => $t['parameters'],
            ],
        ], $toolSchemas);

        $toolsCalled = [];

        for ($round = 0; $round < $maxToolRounds; $round++) {
            $payload = [
                'model' => $this->model,
                'messages' => $messages,
            ];
            if (!empty($tools)) {
                $payload['tools'] = $tools;
                $payload['tool_choice'] = 'auto';
            }

            $response = $this->call($payload);
            $message = $response['choices'][0]['message'] ?? null;
            if (!$message) {
                return ['text' => '', 'tools_called' => $toolsCalled, 'error' => 'no choices in response'];
            }

            $toolCalls = $message['tool_calls'] ?? [];
            if (empty($toolCalls)) {
                return ['text' => $message['content'] ?? '', 'tools_called' => $toolsCalled];
            }

            // Echo the assistant's tool-call turn back before supplying results.
            $messages[] = $message;

            foreach ($toolCalls as $call) {
                $name = $call['function']['name'];
                $args = json_decode($call['function']['arguments'] ?? '{}', true) ?? [];
                $result = $toolDispatcher($name, $args);
                $toolsCalled[] = ['name' => $name, 'args' => $args];
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        // Tool-round budget exhausted. The interface promises a final text
        // answer, so force one - tools omitted - rather than leave the
        // customer with nothing after we already did the lookups.
        $messages[] = ['role' => 'user', 'content' => 'Give your best final answer now, in the customer\'s language, based only on what you already found above. Do not call any more tools.'];
        $response = $this->call(['model' => $this->model, 'messages' => $messages]);
        $text = $response['choices'][0]['message']['content'] ?? '';
        return ['text' => $text, 'tools_called' => $toolsCalled];
    }

    // Free-tier TPM limits are tight relative to our tool-schema + system
    // prompt size, so 429s are routine during dev testing, not exceptional.
    // Groq's error message states the wait time explicitly; parse it when
    // present, otherwise fall back to a fixed short backoff.
    private function call(array $payload, int $retriesLeft = 2): array
    {
        $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Groq request failed: $curlError");
        }
        $decoded = json_decode($raw, true);

        if ($httpCode === 429 && $retriesLeft > 0) {
            $msg = $decoded['error']['message'] ?? '';
            $wait = 3.0;
            if (preg_match('/try again in ([\d.]+)s/', $msg, $m)) {
                $wait = (float)$m[1] + 0.5;
            }
            usleep((int)($wait * 1_000_000));
            return $this->call($payload, $retriesLeft - 1);
        }

        // Observed gpt-oss-120b serving quirk: occasionally leaks internal
        // "<|channel|>commentary" formatting tokens into a tool call name
        // when chaining multiple tool calls, which Groq's own validator then
        // rejects before we ever see the malformed name. This looks like
        // non-deterministic sampling noise (same request shape succeeds most
        // of the time), so a bounded retry is reasonable here - a genuinely
        // broken request (bad schema, etc) would fail identically on retry
        // and still surface after retries are exhausted.
        if ($httpCode === 400 && $retriesLeft > 0 && str_contains($decoded['error']['message'] ?? '', 'tool call validation failed')) {
            usleep(500_000);
            return $this->call($payload, $retriesLeft - 1);
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error']['message'] ?? $raw;
            throw new RuntimeException("Groq API error (HTTP $httpCode): $msg");
        }
        return $decoded;
    }
}
