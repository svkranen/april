<?php

namespace App\View;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ViewModeTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly ViewModeProvider $viewModeProvider
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('view_mode', $this->viewModeProvider->getMode(...)),
            new TwigFunction('is_expert_view', $this->viewModeProvider->isExpert(...)),
        ];
    }
}
