<?php

namespace App\Feed\Exception;

/**
 * Levée lorsqu'un flux ne peut pas être interprété (XML invalide, format ni RSS ni Atom
 * reconnu). L'appelant ({@see \App\Feed\FeedIngester}) l'attrape pour journaliser le flux fautif
 * et poursuivre avec les suivants, sans interrompre tout le lot.
 */
final class FeedParseException extends \RuntimeException
{
}
