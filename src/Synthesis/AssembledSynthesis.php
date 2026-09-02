<?php

namespace App\Synthesis;

/** Résultat de {@see SynthesisAssembler::assemble()} : contenu prêt à persister sur `Synthesis`. */
final readonly class AssembledSynthesis
{
    public function __construct(
        public string $title,
        public string $lead,
        public string $body,
    ) {
    }
}
