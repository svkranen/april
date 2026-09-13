<?php

namespace App\Intelligence\Infrastructure\Twig;

use App\Intelligence\Application\KpiDurationFormatter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class KpiDurationExtension extends AbstractExtension
{
    public function __construct(
        private readonly KpiDurationFormatter $formatter,
        private readonly TranslatorInterface $translator
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('kpi_duration', $this->format(...))];
    }

    public function format(?float $seconds): string
    {
        if ($seconds === null) {
            return $this->translator->trans('kpi.unavailable');
        }

        $formatted = $this->formatter->format($seconds);
        return $formatted === '< 1 s' ? $this->translator->trans('kpi.less_second') : $formatted;
    }
}
