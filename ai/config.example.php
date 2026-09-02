<?php
// Copy this file to ai/config.php (already gitignored) and fill in real values.
// ai/config.php is never committed - it holds the LLM provider API key(s).

return [
    // Kill switch for the customer-facing assistant only (widget.php and
    // chat.php refuse to run when false). Does NOT affect
    // dashboard/include/import_classification.php - see
    // 'import_classification_enabled' below for that.
    'enabled' => true,

    // Optional kill switch for the stock.php import auto-categorisation,
    // independent of 'enabled' above. Omit to leave it on by default even
    // while the customer assistant stays disabled.
    // 'import_classification_enabled' => true,

    // Switch provider here only - nothing in ai/tools.php, ai/tool_schemas.php,
    // ai/prompt.php or ai/chat.php ever needs to change.
    'provider' => 'groq', // 'gemini' | 'anthropic' | 'groq'

    // Optional: separate provider for dashboard/include/import_classification.php
    // (stock.php import auto-categorisation) - independent of the provider
    // above, which stays the customer-facing assistant's. Falls back to
    // 'provider' if omitted.
    'import_classification_provider' => 'gemini',

    'rate_limit' => [
        'max_per_session_per_day' => 40,
        'max_per_session_per_5min' => 8,
    ],
    'max_message_length' => 1000,
    'history_turns' => 10,

    'gemini' => [
        'api_key' => 'PUT_YOUR_GEMINI_API_KEY_HERE',
        'model' => 'gemini-flash-latest',
    ],

    'anthropic' => [
        'api_key' => 'PUT_YOUR_ANTHROPIC_API_KEY_HERE',
        'model' => 'claude-haiku-4-5-20251001',
    ],

    'groq' => [
        'api_key' => 'PUT_YOUR_GROQ_API_KEY_HERE',
        'model' => 'openai/gpt-oss-120b',
    ],
];
