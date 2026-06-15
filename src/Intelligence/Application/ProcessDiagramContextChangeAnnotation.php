<?php

namespace App\Intelligence\Application;

final readonly class ProcessDiagramContextChangeAnnotation
{
    /**
     * @param array<int, string> $affectedDecisions
     */
    public function __construct(
        public string $field,
        public mixed $from,
        public mixed $to,
        public array $affectedDecisions,
        public string $targetNodeId
    ) {
    }

    public function key(): string
    {
        return implode("\0", [
            $this->targetNodeId,
            $this->field,
            json_encode($this->from, JSON_THROW_ON_ERROR),
            json_encode($this->to, JSON_THROW_ON_ERROR),
            implode(',', $this->affectedDecisions),
        ]);
    }
}
