<?php

namespace App\Social\Exception;

/**
 * Levée lorsque l'API Graph de Facebook refuse ou échoue à publier un article (erreur applicative
 * portée par le corps JSON de la réponse, ou réponse HTTP en échec sans corps exploitable).
 * {@see \App\Social\Command\ShareArticlesCommand} l'attrape pour journaliser l'échec sans marquer
 * l'article comme partagé, afin qu'il reste candidat au prochain passage.
 */
final class FacebookPublishException extends \RuntimeException
{
}
