<?php

namespace App\Service\Settings;

class TemplatePlaceholderParser
{
    /**
     * @return list<string>
     */
    public function parse(string $template): array
    {
        preg_match_all('/\[\:\:[^\:\[\]]+\:\:\]|\[\:(?!(?:repeatstart|repeatend|splitstart|splitend)\:)[^\:]+\:\]/', $template, $matches, PREG_OFFSET_CAPTURE);

        $placeholders = [];
        foreach ($matches[0] ?? [] as $match) {
            $placeholder = $match[0];
            if (isset($placeholders[$placeholder])) {
                continue;
            }

            $placeholders[$placeholder] = $placeholder;
        }

        return array_values($placeholders);
    }
}
