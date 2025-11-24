<?php

namespace Lumiio\CascadeDocs\Support;

use Shawnveltman\LaravelOpenai\Enums\ThinkingEffort;

trait ResolvesThinkingEffort
{
    protected function resolveThinkingEffort(ThinkingEffort|string|null $effort = null): ThinkingEffort
    {
        if ($effort instanceof ThinkingEffort) {
            return $effort;
        }

        $configured = $effort ?? config('cascadedocs.ai.thinking_effort', ThinkingEffort::HIGH->value);

        if ($configured instanceof ThinkingEffort) {
            return $configured;
        }

        if (is_string($configured)) {
            $normalized = strtolower($configured);

            return ThinkingEffort::tryFrom($normalized) ?? ThinkingEffort::HIGH;
        }

        return ThinkingEffort::HIGH;
    }
}
