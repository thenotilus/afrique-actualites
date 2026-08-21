<?php

namespace App\Classification\Pipeline;

/**
 * Tokenisation par classes de caractères Unicode plutôt que par `str_word_count()` (§10.1.3).
 */
final class RegexTokenizer implements TokenizerInterface
{
    public function __construct(private readonly int $minWordLength = 4)
    {
    }

    public function tokenize(string $normalizedText): array
    {
        if ('' === $normalizedText) {
            return [];
        }

        $tokens = preg_split('/[\s]+/u', $normalizedText, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $tokens,
            fn (string $token): bool => mb_strlen($token) >= $this->minWordLength,
        ));
    }
}
