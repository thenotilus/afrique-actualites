<?php

namespace App\Synthesis;

/** Bilan d'une exécution de {@see SynthesisGenerator::generateForWeek()}. */
final readonly class SynthesisGenerationSummary
{
    public function __construct(
        public int $created = 0,
        public int $skipped = 0,
        public int $failed = 0,
    ) {
    }

    public function add(self $other): self
    {
        return new self(
            $this->created + $other->created,
            $this->skipped + $other->skipped,
            $this->failed + $other->failed,
        );
    }
}
