<?php

namespace App\Intelligence\Application;

final readonly class UnexpectedProcessResult
{
    public const CODE = 'UNEXPECTED_PROCESS';
    public const STATUS = JourneyTemplateCheckService::STATUS_DEVIATION;
    public const SEVERITY = 'CRITICAL';
    public const MESSAGE = 'Kritische Abweichung: Unerwarteter Prozess außerhalb des Templates';

    public function __construct(
        public string $processKey,
        public ?int $timelineIndex,
        public ?\DateTimeImmutable $occurredAt,
        public ?int $documentVersion,
        public string $code = self::CODE,
        public string $status = self::STATUS,
        public string $severity = self::SEVERITY,
        public string $message = self::MESSAGE
    ) {
    }
}
