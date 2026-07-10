<?php

namespace App\Intelligence\Infrastructure\Template;

use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Port\JourneyMatchStore;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Updates match.any_process of an existing journey template YAML file.
 *
 * This is deliberately NOT a template editor: only the match block is
 * replaced (or removed for an empty selection), everything else is carried
 * over unchanged. Before writing, the whole document is re-validated through
 * ProcessTemplateArrayFactory - the same factory the catalog uses - so an
 * update can never leave a template behind that the catalog would reject.
 * The write itself goes through a temporary file plus rename, so a failed
 * write never leaves a half-written template.
 *
 * Known trade-off: the file is re-dumped, so YAML comments and hand-crafted
 * formatting are lost on save.
 */
final readonly class YamlJourneyMatchWriter implements JourneyMatchStore
{
    public function __construct(
        private string $templateDirectory
    ) {
    }

    public function saveMatch(string $journeyKey, array $processKeys): void
    {
        $path = rtrim($this->templateDirectory, '/').'/'.$journeyKey.'.yaml';
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Journey template file not found: %s.yaml', $journeyKey));
        }

        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
            throw new InvalidArgumentException(sprintf('Template file "%s.yaml" does not contain a YAML mapping.', $journeyKey));
        }

        if (($data['scope'] ?? 'process') !== 'journey') {
            throw new InvalidArgumentException(sprintf(
                'Template "%s" has scope "%s"; match.any_process can only be edited on journey templates.',
                $journeyKey,
                (string) ($data['scope'] ?? 'process')
            ));
        }

        if ($processKeys === []) {
            unset($data['match']);
        } else {
            $data['match'] = ['any_process' => array_values($processKeys)];
        }

        // Same validation path as the catalog loader - throws InvalidArgumentException
        // on inconsistencies. Nothing is written when validation fails.
        ProcessTemplateArrayFactory::fromArray($data);

        $this->writeAtomically($path, Yaml::dump($data, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));
    }

    /**
     * Temp file in the target directory plus rename: readers either see the
     * old or the new template, never a partial write.
     */
    private function writeAtomically(string $path, string $contents): void
    {
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(6));

        if (file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write temporary template file: %s', $temporaryPath));
        }

        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw new RuntimeException(sprintf('Could not replace template file: %s', $path));
        }
    }
}
