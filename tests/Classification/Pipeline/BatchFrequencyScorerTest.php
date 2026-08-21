<?php

namespace App\Tests\Classification\Pipeline;

use App\Classification\Pipeline\BatchFrequencyScorer;
use PHPUnit\Framework\TestCase;

final class BatchFrequencyScorerTest extends TestCase
{
    public function testScoreComputesDocumentFrequencyAndCumulatedWeight(): void
    {
        $scorer = new BatchFrequencyScorer();

        $scores = $scorer->score([
            1 => ['election' => 2, 'senegal' => 1],
            2 => ['election' => 1],
            3 => ['nigeria' => 2],
        ]);

        self::assertSame(2, $scores['election']->documentFrequency);
        self::assertSame(3, $scores['election']->totalWeight);
        self::assertSame(1, $scores['senegal']->documentFrequency);
        self::assertSame(1, $scores['senegal']->totalWeight);
        self::assertSame(1, $scores['nigeria']->documentFrequency);
        self::assertSame(2, $scores['nigeria']->totalWeight);
    }

    public function testScoreOfEmptyBatchIsEmpty(): void
    {
        self::assertSame([], (new BatchFrequencyScorer())->score([]));
    }
}
