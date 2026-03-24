<?php

namespace App\SignatureCheck;

final class SignatureCheckResult
{
    /**
     * @param array<int, string> $requiredNames
     * @param array<int, string> $confirmedNames
     * @param array<int, string> $missingNames
     * @param array<int, string> $unexpectedNames
     */
    public function __construct(
        public readonly array $requiredNames,
        public readonly array $confirmedNames,
        public readonly array $missingNames,
        public readonly array $unexpectedNames
    ) {
    }

    public function isComplete(): bool
    {
        return $this->missingNames === [] && $this->unexpectedNames === [];
    }

    public function message(string $requiredLabel = 'Zu pruefen durch', string $confirmedLabel = 'Geprueft durch'): string
    {
        if ($this->isComplete()) {
            return sprintf(
                'Unterschriften vollstaendig: %d Eintraege in "%s" und "%s".',
                count($this->requiredNames),
                $requiredLabel,
                $confirmedLabel
            );
        }

        $parts = [
            sprintf(
                'Unterschriften unvollstaendig: %d erwartet, %d bestaetigt.',
                count($this->requiredNames),
                count($this->confirmedNames)
            ),
        ];

        if ($this->missingNames !== []) {
            $parts[] = 'Fehlend: '.implode(', ', $this->missingNames).'.';
        }

        if ($this->unexpectedNames !== []) {
            $parts[] = 'Unerwartet: '.implode(', ', $this->unexpectedNames).'.';
        }

        return implode(' ', $parts);
    }
}
