<?php
require_once __DIR__ . '/LlmProvider.php';

class GeminiProvider implements LlmProvider
{
    private string $apiKey;
    private string $model;
    private string $endpoint;

    public function __construct(string $apiKey, string $model = 'gemini-2.0-flash')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    public function converse(
        string $systemPrompt,
        array $history,
        string $userMessage,
        array $toolSchemas,
        callable $toolDispatcher,
        int $maxToolRounds = 5
    ): array {
        $contents = [];
        foreach ($history as $turn) {
            $contents[] = [
                'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => $turn['text']]],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        $tools = empty($toolSchemas) ? [] : [[
            'function_declarations' => array_map([$this, 'toGeminiSchema'], $toolSchemas),
        ]];

        $toolsCalled = [];

        for ($round = 0; $round < $maxToolRounds; $round++) {
            $payload = [
                'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                'contents' => $contents,
            ];
            if (!empty($tools)) {
                $payload['tools'] = $tools;
            }

            $response = $this->call($payload);
            $candidate = $response['candidates'][0] ?? null;
            if (!$candidate) {
                $reason = $response['promptFeedback']['blockReason'] ?? 'unknown';
                return ['text' => '', 'tools_called' => $toolsCalled, 'error' => "no candidate (reason: $reason)"];
            }

            $parts = $candidate['content']['parts'] ?? [];
            $functionCalls = array_values(array_filter($parts, fn($p) => isset($p['functionCall'])));

            if (empty($functionCalls)) {
                $text = implode('', array_map(fn($p) => $p['text'] ?? '', $parts));
                return ['text' => $text, 'tools_called' => $toolsCalled];
            }

            // Model's turn must be echoed back before our response - but
            // rebuilt rather than re-serializing the raw response verbatim.
            // PHP's json_encode([]) produces "[]" not "{}", so a tool call
            // with no arguments (e.g. list_categories()) round-trips as an
            // empty array and Gemini's proto parser rejects it ("args...
            // Proto field is not repeating, cannot start list"). Forcing
            // empty args/results to stdClass avoids that ambiguity.
            $modelParts = [];
            foreach ($parts as $p) {
                $newPart = null;
                if (isset($p['functionCall'])) {
                    $newPart = ['functionCall' => [
                        'name' => $p['functionCall']['name'],
                        'args' => $this->emptyAsObject($p['functionCall']['args'] ?? []),
                    ]];
                } elseif (isset($p['text'])) {
                    $newPart = ['text' => $p['text']];
                }
                if ($newPart === null) {
                    continue;
                }
                // Required for multi-turn tool calls on this model generation
                // (extended-thinking models) - dropping it errors with
                // "Function call is missing a thought_signature".
                if (isset($p['thoughtSignature'])) {
                    $newPart['thoughtSignature'] = $p['thoughtSignature'];
                }
                $modelParts[] = $newPart;
            }
            $contents[] = ['role' => 'model', 'parts' => $modelParts];

            $responseParts = [];
            foreach ($functionCalls as $fc) {
                $name = $fc['functionCall']['name'];
                $args = $fc['functionCall']['args'] ?? [];
                $result = $toolDispatcher($name, $args);
                $toolsCalled[] = ['name' => $name, 'args' => $args];
                $responseParts[] = [
                    'functionResponse' => [
                        'name' => $name,
                        'response' => ['result' => $this->emptyAsObject($result)],
                    ],
                ];
            }
            // This API version rejects role 'function' for tool results
            // (confirmed live: "Role 'function' is not supported... use
            // ... USER ... MODEL") despite that being the documented value
            // in older Gemini docs - it wants results fed back as 'user',
            // same convention as OpenAI/Anthropic's tool-result turns.
            $contents[] = ['role' => 'user', 'parts' => $responseParts];
        }

        // Tool-round budget exhausted. The interface promises a final text
        // answer, so force one - tools omitted - rather than leave the
        // customer with nothing after we already did the lookups.
        $contents[] = ['role' => 'user', 'parts' => [['text' => 'Give your best final answer now, in the customer\'s language, based only on what you already found above. Do not call any more tools.']]];
        $response = $this->call([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => $contents,
        ]);
        $parts = $response['candidates'][0]['content']['parts'] ?? [];
        $text = implode('', array_map(fn($p) => $p['text'] ?? '', $parts));
        return ['text' => $text, 'tools_called' => $toolsCalled];
    }

    // json_encode([]) => "[]", but Gemini's schema wants "{}" for an empty
    // struct (args with no parameters, or an empty tool result). PHP can't
    // distinguish "empty list" from "empty object" on its own.
    private function emptyAsObject($value)
    {
        return (is_array($value) && empty($value)) ? new stdClass() : $value;
    }

    private function toGeminiSchema(array $tool): array
    {
        return [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'parameters' => $this->toGeminiTypes($tool['parameters']),
        ];
    }

    // Gemini's function-calling schema uses UPPERCASE type names (OBJECT,
    // STRING, INTEGER, ...) instead of standard lowercase JSON Schema.
    // This is the one place that difference is handled - the schemas
    // themselves (ai/tool_schemas.php) stay standard JSON Schema so an
    // OpenAI/Anthropic adapter can consume them unmodified.
    private function toGeminiTypes(array $schema): array
    {
        $out = $schema;
        if (isset($out['type'])) {
            $out['type'] = strtoupper($out['type']);
        }
        if (isset($out['properties']) && is_array($out['properties'])) {
            foreach ($out['properties'] as $key => $prop) {
                $out['properties'][$key] = $this->toGeminiTypes($prop);
            }
        }
        return $out;
    }

    private function call(array $payload, int $retriesLeft = 2): array
    {
        $ch = curl_init($this->endpoint . '?key=' . urlencode($this->apiKey));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Gemini request failed: $curlError");
        }
        $decoded = json_decode($raw, true);

        // 503 "high demand" and 429 free-tier quota are both transient,
        // not broken requests. Gemini's 429 states the real cooldown
        // ("Please retry in 16.6s") which can be tens of seconds on the
        // free tier - honor it instead of a token fixed wait.
        if (($httpCode === 503 || $httpCode === 429) && $retriesLeft > 0) {
            $msg = $decoded['error']['message'] ?? '';
            $wait = 2.0;
            if (preg_match('/retry in ([\d.]+)s/', $msg, $m)) {
                $wait = (float)$m[1] + 0.5;
            }
            usleep((int)($wait * 1_000_000));
            return $this->call($payload, $retriesLeft - 1);
        }

        if ($httpCode >= 400) {
            $msg = $decoded['error']['message'] ?? $raw;
            throw new RuntimeException("Gemini API error (HTTP $httpCode): $msg");
        }
        return $decoded;
    }
}
