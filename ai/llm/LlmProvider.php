<?php
// Provider-agnostic contract. chat.php and prompt.php only ever talk to
// this interface - never to a specific vendor's SDK/wire format. Adding a
// new provider means writing one class that implements this, nothing else
// in ai/ changes.

interface LlmProvider
{
    /**
     * Run one full conversational turn, including the entire tool-call loop
     * (model asks for a tool -> we execute it -> feed result back -> repeat)
     * until the model produces a final text answer.
     *
     * @param string $systemPrompt Instructions for the assistant's behavior/limits.
     * @param array $history Prior turns: [['role' => 'user'|'assistant', 'text' => string], ...]
     * @param string $userMessage The new message from the user this turn.
     * @param array $toolSchemas Generic JSON-Schema tool definitions (see ai/tool_schemas.php):
     *   [['name' => string, 'description' => string, 'parameters' => <JSON Schema object>], ...]
     * @param callable $toolDispatcher function(string $toolName, array $args): array — executes
     *   the named tool (via ai/tools.php) and returns its result as an array.
     * @param int $maxToolRounds Safety cap on tool-call round trips per turn.
     *
     * @return array{text: string, tools_called: array} Final assistant reply text,
     *   plus a log of which tools were called with which args (for ai_conversation).
     */
    public function converse(
        string $systemPrompt,
        array $history,
        string $userMessage,
        array $toolSchemas,
        callable $toolDispatcher,
        int $maxToolRounds = 5
    ): array;
}
