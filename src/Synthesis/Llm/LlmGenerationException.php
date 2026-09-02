<?php

namespace App\Synthesis\Llm;

/** Appel LLM échoué (réseau, statut HTTP en erreur, réponse vide ou mal formée). */
final class LlmGenerationException extends \RuntimeException
{
}
