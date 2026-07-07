<?php

namespace App\Service\Settings;

class LegacyMatchingSaveService
{
    /**
     * @var list<string>
     */
    private const FUNCTION_REGEX_SET = [
        '/^(\[IF\](\[(STARTSWITH|ENDSWITH|L|LE|G|GE|E):[^\]]+\])\[(.+)\])+\[(.+)\]$/',
        '/^(\[FORMAT\])(\[DATE\])\[(.+)\]$/',
        '/^(\[FORMAT\])(\[NOW\])\[(.+)\]$/',
        '/^(\[FORMAT\])(\[NUMBER\])(\[[\.,]\])(\[\d+\])$/',
        '/^(\[FORMAT\])(\[TEXT\])(\[GETFIRST\])(\[\d+\])$/',
        '/^(\[FORMAT\])(\[TEXT\])(\[PREFIX\])(\[\w+\])$/',
        '/^(\[FORMAT\])(\[TEXT\])(\[GETFROMTO\])(\[\d+\])(\[\d+\])$/',
        '/^(\[FORMAT\])(\[TEXT\])(\[REMOVEBLANK\])$/',
        '/^(\[FORMAT\])(\[ASTEXT\])$/',
        '/^\[CALCULATE\]$/',
        '/^\[CALCULATE\]\[FORCED\]$/',
        '/^\[CALCULATE\]\[FORCED\]\[TEXT\]$/',
        '/^\[CALCULATE\]\[TEXT\]$/',
        '/^\[CALCULATE\]\[NUMBER\]$/',
        '/^\[CALCULATE\]\[FORCED\]\[FORMAT\]\[DATE\]\[(.+)\]$/',
        '/^\[CALCULATE\]\[FORCED\]\[FORMAT\]\[NUMBER\]\[(.+)\]\[(.+)\]$/',
        '/^\[CALCULATE\]\[FORCED\]\[FORMAT\]\[TEXT\]\[GETFROMTO\]\[(.+)\]\[(.+)\]$/',
        '/^\[SQL\]\[(.+)\]$/',
        '/^\[DOCTYPE\]$/',
        '/^\[COUNTER\]$/',
        '/^\[TAXES\](\[[\.,]\])(\[\d+%\]\[\d+(,\d+)*\])+$/',
    ];

    public function __construct(
        private readonly string $matchingFile
    ) {
    }

    /**
     * @param array<string, mixed> $post
     */
    public function save(array $post): string
    {
        $profiles = $this->loadProfiles();
        $data = [];

        foreach ($post as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, 'name')) {
                continue;
            }

            $id = substr($key, strlen('name'));
            $fieldName = (string) $value;
            if ($fieldName === '[::Stempel::]' && isset($post['tag'.$id])) {
                $data['Stempel'] = $post['tag'.$id];
                continue;
            }

            $group = (string) ($post['group'.$id] ?? '0');
            if ($group === '1') {
                $data[$fieldName] = $post['fix'.$id] ?? '';
                $data[$fieldName.'group'] = $group;
            } elseif ($group !== '0') {
                $data[$fieldName] = $post['tag'.$id] ?? '';
                $data[$fieldName.'group'] = $group;
            }

            $maxLen = (string) ($post['maxlen'.$id] ?? '');
            if ($maxLen !== '') {
                $maxLenValue = (int) $maxLen;
                if ($maxLenValue > 0) {
                    $data[$fieldName.'maxlen'] = $maxLenValue;
                }
            }

            if ($group !== '0' && (string) ($post['function'.$id] ?? '') === '1') {
                $functionDefinition = (string) ($post['functiondef'.$id] ?? '');
                if (!$this->isValidFunctionDefinition($functionDefinition)) {
                    return json_encode([
                        'status' => 'error',
                        'message' => sprintf('Fehler bei Funktion fuer %s.', $fieldName),
                    ], JSON_THROW_ON_ERROR);
                }

                $data[$fieldName.'func'] = $functionDefinition;
            }
        }

        $profileSelect = (string) ($post['profileselect'] ?? '');
        $profileName = (string) ($post['profilename'] ?? '');
        if ($profileSelect === '0' && $profileName === '') {
            return json_encode([
                'status' => 'ok',
                'message' => 'Bitte Profil auswaehlen oder einen neuen Profilnamen angeben.',
            ], JSON_THROW_ON_ERROR);
        }

        $data['sys'] = (string) ($post['system'] ?? 'onprem');
        $data['url'] = (string) ($post['aurl'] ?? '');
        $resolvedProfileName = $profileSelect === '0' ? $profileName : $profileSelect;
        $profiles[$resolvedProfileName] = $data;

        file_put_contents($this->matchingFile, json_encode($profiles, JSON_THROW_ON_ERROR));

        $response = [
            'status' => 'ok',
            'message' => 'Gespeichert.',
        ];
        if ($profileSelect === '0') {
            $response['profile'] = $data;
            $response['profilename'] = $resolvedProfileName;
            $response['status'] = 'newProfile';
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadProfiles(): array
    {
        if (!is_file($this->matchingFile)) {
            return [];
        }

        $profiles = json_decode((string) file_get_contents($this->matchingFile), true);

        return is_array($profiles) ? $profiles : [];
    }

    private function isValidFunctionDefinition(string $functionDefinition): bool
    {
        foreach (self::FUNCTION_REGEX_SET as $regex) {
            if (preg_match($regex, $functionDefinition) === 1) {
                return true;
            }
        }

        return false;
    }
}
