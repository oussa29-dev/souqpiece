<?php
require_once __DIR__ . '/LlmProvider.php';
require_once __DIR__ . '/GeminiProvider.php';
require_once __DIR__ . '/AnthropicProvider.php';
require_once __DIR__ . '/GroqProvider.php';

// The only place that knows which concrete provider class corresponds to
// the 'provider' key in ai/config.php. Adding a new provider means adding
// a case here + a new adapter file - nothing else in ai/ changes.
function ai_make_provider(array $config): LlmProvider
{
    switch ($config['provider']) {
        case 'gemini':
            return new GeminiProvider($config['gemini']['api_key'], $config['gemini']['model'] ?? 'gemini-flash-latest');
        case 'anthropic':
            return new AnthropicProvider($config['anthropic']['api_key'], $config['anthropic']['model'] ?? 'claude-haiku-4-5-20251001');
        case 'groq':
            return new GroqProvider($config['groq']['api_key'], $config['groq']['model'] ?? 'openai/gpt-oss-120b');
        default:
            throw new RuntimeException("Unknown LLM provider: {$config['provider']}");
    }
}
