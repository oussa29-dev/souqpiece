<?php
require_once __DIR__ . '/LlmProvider.php';

class AnthropicProvider implements LlmProvider
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'claude-haiku-4-5-20251001')
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
        $messages = [];
        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $turn['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        // Anthropic's input_schema is standard JSON Schema - the exact
        // format ai_tool_schemas() already produces, so no translation
        // layer is needed here (unlike Gemini's uppercase-type quirk).
        $tools = array_map(fn($t) => [
            'name' => $t['name'],
            'description' => $t['description'],
            'input_schema' => $t['parameters'],
        ], $toolSchemas);

        $toolsCalled = [];

        for ($round = 0; $round < $maxToolRounds; $round++) {
            $payload = [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => $systemPrompt,
                'messages' => $messages,
            ];
            if (!empty($tools)) {
                $payload['tools'] = $tools;
            }

            $response = $this->call($payload);
            $content = $response['content'] ?? [];

            $toolUseBlocks = array_values(array_filter($content, fn($b) => ($b['type'] ?? '') === 'tool_use'));

            if (empty($toolUseBlocks)) {
                $text = implode('', array_map(fn($b) => ($b['type'] ?? '') === 'text' ? $b['text'] : '', $content));
                return ['text' => $text, 'tools_called' => $toolsCalled];
            }

            // Echo the assistant's tool-use turn back before supplying results.
            $messages[] = ['role' => 'assistant', 'content' => $content];

            $resultBlocks = [];
            foreach ($toolUseBlocks as $block) {
                $name = $block['name'];
                $args = $block['input'] ?? [];
                $result = $toolDispatcher($name, $args);
                $toolsCalled[] = ['name' => $name, 'args' => $args];
                $resultBlocks[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $block['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
            $messages[] = ['role' => 'user', 'content' => $resultBlocks];
        }

        // Tool-round budget exhausted. The interface promises a final text
        // answer, so force one - tools omitted - rather than leave the
        // customer with nothing after we already did the lookups.
        $messages[] = ['role' => 'user', 'content' => 'Give your best final answer now, in the customer\'s language, based only on what you already found above. Do not call any more tools.'];
        $response = $this->call(['model' => $this->model, 'max_tokens' => 1024, 'system' => $systemPrompt, 'messages' => $messages]);
        $content = $response['content'] ?? [];
        $text = implode('', array_map(fn($b) => ($b['type'] ?? '') === 'text' ? $b['text'] : '', $content));
        return ['text' => $text, 'tools_called' => $toolsCalled];
    }

    private function call(array $payload): array
    {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Anthropic request failed: $curlError");
        }
        $decoded = json_decode($raw, true);
        if ($httpCode >= 400) {
            $msg = $decoded['error']['message'] ?? $raw;
            throw new RuntimeException("Anthropic API error (HTTP $httpCode): $msg");
        }
        return $decoded;
    }
}
