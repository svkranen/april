<?php

namespace App\Intelligence\Application;

/**
 * Compact, business-readable summary of the most important findings for one
 * document. Aggregates the on-demand Soll/Ist check and the stored visibility
 * results into a few simple categories and one overall severity.
 *
 * Built purely from data already loaded for the document detail page - no extra
 * backend calls and no persistence.
 */
final readonly class DocumentFindingsView
{
    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_DEVIATION = 'deviation';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_TECHNICAL = 'technical';
    public const SEVERITY_OK = 'ok';

    /** Highest first. */
    private const SEVERITY_RANK = [
        self::SEVERITY_CRITICAL => 0,
        self::SEVERITY_DEVIATION => 1,
        self::SEVERITY_WARNING => 2,
        self::SEVERITY_TECHNICAL => 3,
    ];

    /**
     * @param array<int, array{category: string, severity: string, message: string, stepKey: ?string, checkKey: ?string, probeKey: ?string, status: ?string, reason: ?string}> $findings
     * @param array<string, int> $countsByCategory
     * @param array<string, int> $countsBySeverity
     */
    public function __construct(
        public string $overallSeverity,
        public string $overallLabel,
        public string $overallCssClass,
        public bool $hasFindings,
        public int $total,
        public array $countsByCategory,
        public array $countsBySeverity,
        public array $findings
    ) {
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $visibilityRecords
     */
    public static function fromData(DocumentCheckResultView $check, array $visibilityRecords): self
    {
        $findings = [];

        // A) on-demand Soll/Ist check
        if (!$check->available) {
            $findings[] = self::finding('technical', self::SEVERITY_TECHNICAL, 'Soll-/Ist-Prüfung derzeit nicht berechenbar', reason: $check->error);
        } else {
            foreach ($check->deviations as $deviation) {
                $findings[] = self::finding('process', self::SEVERITY_DEVIATION, $deviation);
            }
            foreach ($check->warnings as $warning) {
                $findings[] = self::finding('context', self::SEVERITY_WARNING, $warning);
            }
            // status DEVIATION without an explicit deviation message (e.g. sign-check only).
            if ($check->deviations === [] && str_contains($check->status, 'DEVIATION')) {
                $findings[] = self::finding('process', self::SEVERITY_DEVIATION, 'Prozessabweichung erkannt (Status DEVIATION)');
            }
        }

        // B) stored visibility check results. Real access findings (violation/
        // missing expected visibility) are category "access"; non-evaluable
        // results (unknown/skipped/technical_warning) are category "technical".
        foreach ($visibilityRecords as $record) {
            [$severity, $category] = match ($record->status) {
                'violation' => [self::SEVERITY_CRITICAL, 'access'],
                'warning' => [self::SEVERITY_WARNING, 'access'],
                'technical_warning', 'unknown', 'skipped' => [self::SEVERITY_TECHNICAL, 'technical'],
                default => [null, null], // ok and anything else -> no finding
            };
            if ($severity === null) {
                continue;
            }

            $findings[] = self::finding(
                $category,
                $severity,
                self::accessMessage($record),
                stepKey: $record->stepKey,
                checkKey: $record->checkKey,
                probeKey: $record->probeKey,
                status: $record->status,
                reason: $record->reason
            );
        }

        usort(
            $findings,
            static fn (array $a, array $b): int => self::SEVERITY_RANK[$a['severity']] <=> self::SEVERITY_RANK[$b['severity']]
        );

        $countsByCategory = ['process' => 0, 'context' => 0, 'access' => 0, 'technical' => 0];
        $countsBySeverity = [
            self::SEVERITY_CRITICAL => 0,
            self::SEVERITY_DEVIATION => 0,
            self::SEVERITY_WARNING => 0,
            self::SEVERITY_TECHNICAL => 0,
        ];
        foreach ($findings as $finding) {
            $countsByCategory[$finding['category']] = ($countsByCategory[$finding['category']] ?? 0) + 1;
            $countsBySeverity[$finding['severity']] = ($countsBySeverity[$finding['severity']] ?? 0) + 1;
        }

        $overall = self::overallSeverity($countsBySeverity);

        return new self(
            $overall,
            self::label($overall),
            self::cssClass($overall),
            $findings !== [],
            count($findings),
            $countsByCategory,
            $countsBySeverity,
            $findings
        );
    }

    /**
     * @param array<string, int> $countsBySeverity
     */
    private static function overallSeverity(array $countsBySeverity): string
    {
        foreach ([self::SEVERITY_CRITICAL, self::SEVERITY_DEVIATION, self::SEVERITY_WARNING, self::SEVERITY_TECHNICAL] as $severity) {
            if (($countsBySeverity[$severity] ?? 0) > 0) {
                return $severity;
            }
        }

        return self::SEVERITY_OK;
    }

    private static function accessMessage(VisibilityCheckResultRecord $record): string
    {
        return match ($record->status) {
            'violation' => sprintf('Verbotene Sichtbarkeit in "%s" (Schritt %s)', $record->probeKey, $record->stepKey),
            'warning' => sprintf('Erwartete Sichtbarkeit fehlt in "%s" (Schritt %s)', $record->probeKey, $record->stepKey),
            default => sprintf('Technisch nicht bewertbar: "%s" (Schritt %s)', $record->probeKey, $record->stepKey),
        };
    }

    /**
     * @return array{category: string, severity: string, message: string, stepKey: ?string, checkKey: ?string, probeKey: ?string, status: ?string, reason: ?string}
     */
    private static function finding(
        string $category,
        string $severity,
        string $message,
        ?string $stepKey = null,
        ?string $checkKey = null,
        ?string $probeKey = null,
        ?string $status = null,
        ?string $reason = null
    ): array {
        return [
            'category' => $category,
            'severity' => $severity,
            'message' => $message,
            'stepKey' => $stepKey,
            'checkKey' => $checkKey,
            'probeKey' => $probeKey,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    private static function label(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_CRITICAL => 'Kritisch',
            self::SEVERITY_DEVIATION => 'Abweichung',
            self::SEVERITY_WARNING => 'Warnung',
            self::SEVERITY_TECHNICAL => 'Technisch',
            default => 'OK',
        };
    }

    private static function cssClass(string $severity): string
    {
        return match ($severity) {
            self::SEVERITY_CRITICAL, self::SEVERITY_DEVIATION => 'vs-violation',
            self::SEVERITY_WARNING => 'vs-warning',
            self::SEVERITY_TECHNICAL => 'vs-unknown',
            default => 'vs-ok',
        };
    }
}
