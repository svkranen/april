<?php

namespace App\Intelligence\Domain;

final readonly class ProcessGraphNode
{
    public const TYPE_START = 'start';
    public const TYPE_END = 'end';
    public const TYPE_TASK = 'task';
    public const TYPE_EXCLUSIVE_GATEWAY = 'exclusive_gateway';
    public const TYPE_PARALLEL_GROUP = 'parallel_group';
    public const TYPE_PARALLEL_START = 'parallel_start';
    public const TYPE_PARALLEL_JOIN = 'parallel_join';

    public function __construct(
        public string $id,
        public string $label,
        public string $type,
        public bool $required = false
    ) {
    }
}
