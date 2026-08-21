<?php

namespace App\Classification\Pipeline;

use App\Shared\ValueObject\Language;
use Symfony\Component\Yaml\Yaml;

/**
 * Charge une liste de mots vides par langue depuis un fichier YAML (§10.1.4) et filtre les
 * tokens en conséquence. Les fichiers sources vivent dans Resources/stopwords/{langue}.yaml,
 * modifiables sans toucher au code.
 */
final class StopWordFilter implements StopWordFilterInterface
{
    /** @var array<string, array<string, true>> */
    private array $stopWordsByLanguage = [];

    public function __construct(private readonly string $resourcesDirectory)
    {
    }

    public function filter(array $tokens, Language $language): array
    {
        $stopWords = $this->getStopWords($language);

        return array_values(array_filter(
            $tokens,
            static fn (string $token): bool => !isset($stopWords[$token]),
        ));
    }

    /** @return array<string, true> */
    private function getStopWords(Language $language): array
    {
        if (isset($this->stopWordsByLanguage[$language->value])) {
            return $this->stopWordsByLanguage[$language->value];
        }

        $path = sprintf('%s/%s.yaml', rtrim($this->resourcesDirectory, '/'), $language->value);
        $words = is_file($path) ? (Yaml::parseFile($path) ?? []) : [];

        $indexed = array_fill_keys($words, true);
        $this->stopWordsByLanguage[$language->value] = $indexed;

        return $indexed;
    }
}
