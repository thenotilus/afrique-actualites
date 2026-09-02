<?php

namespace App\Synthesis\Pipeline;

use App\Article\Entity\Article;
use App\Synthesis\Enum\Theme;

interface ThemeClassifierInterface
{
    public function classify(Article $article): Theme;
}
