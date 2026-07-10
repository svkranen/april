<?php

namespace App\Intelligence\Port;

use InvalidArgumentException;
use RuntimeException;

/**
 * Persists the match configuration (match.any_process) of a journey template.
 *
 * Deliberately narrow: this port covers only the journey match, not general
 * template editing. The community implementation writes to the template YAML
 * file; connectors or an enterprise edition may store templates elsewhere.
 */
interface JourneyMatchStore
{
    /**
     * @param array<int, string> $processKeys empty = remove the explicit match
     *                                        (the resolver's legacy fallback applies again)
     *
     * @throws InvalidArgumentException on business/validation errors (unknown journey,
     *                                  wrong scope, resulting template invalid)
     * @throws RuntimeException on infrastructure failures (storage not writable)
     */
    public function saveMatch(string $journeyKey, array $processKeys): void;
}
