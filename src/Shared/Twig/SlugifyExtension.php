<?php

namespace App\Shared\Twig;

use App\Shared\Utils\Slugifier;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class SlugifyExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('slugify', Slugifier::slugify(...)),
        ];
    }
}
