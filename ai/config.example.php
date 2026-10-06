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
    // Customer assistant: Gemini 3.5 Flash-Lite, paid, through OpenRouter (see
    // the 'openrouter' block) - most accurate on Arabic/Darija and fastest in
    // the 06/10 comparison of 4 models on real sessions.
    'provider' => 'openrouter', // 'openrouter' | 'gemini' | 'anthropic' | 'groq'

    // Optional: separate provider for dashboard/include/import_classification.php
    // (stock.php import auto-categorisation) - independent of the provider
    // above, which stays the customer-facing assistant's. Falls back to
    // 'provider' if omitted.
    'import_classification_provider' => 'gemini',

    'rate_limit' => [
        'max_per_session_per_day' => 25,
        'max_per_session_per_5min' => 8,
    ],
    'max_message_length' => 1000,
    'history_turns' => 10,

    // Store facts the assistant may give, beyond the name/phone/Facebook it
    // already reads from the `setting` table (Dashboard > Parametre). Plain
    // text, one fact per line; leave empty until the store has confirmed
    // them - the assistant then answers "I don't have that detail" and gives
    // the phone number, never a guess.
    // 'store_info' => "Address: ...\nOpening hours: ...\nWholesale: ...",
    'store_info' => '',

    // Developer-only page dashboard/ai-conversations.php: hash of a password
    // separate from the shared dashboard login. Empty = page closed. Set with:
    // php -r 'echo password_hash("...", PASSWORD_DEFAULT);'
    'dev_password_hash' => '',

    'gemini' => [
        'api_key' => 'PUT_YOUR_GEMINI_API_KEY_HERE',
        // Exact version, never a "-latest" alias: Google moves aliases to
        // newer models (different price/behaviour) without notice.
        'model' => 'gemini-3.5-flash-lite',
    ],

    'anthropic' => [
        'api_key' => 'PUT_YOUR_ANTHROPIC_API_KEY_HERE',
        'model' => 'claude-haiku-4-5-20251001',
    ],

    // One key for every vendor's models; 'model' uses OpenRouter ids
    // ("google/gemini-3.5-flash-lite", "openai/gpt-5.6-luna",
    // "anthropic/claude-haiku-4.5"). Used to compare models with
    // `php ai/eval_chat.php openrouter:<model id>`.
    'openrouter' => [
        'api_key' => 'PUT_YOUR_OPENROUTER_API_KEY_HERE',
        'model' => 'google/gemini-3.5-flash-lite',
    ],

    'groq' => [
        'api_key' => 'PUT_YOUR_GROQ_API_KEY_HERE',
        'model' => 'openai/gpt-oss-120b',
    ],
];
