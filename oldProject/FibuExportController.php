<?php

class FibuExportController
{
    public function fibu_saveMatching(): void
    {
        $oldcontent = '[]';
        $system = $_POST['system'] ?? 'onprem';
        $missing = [];
        foreach (['system', 'profileselect', 'profilename', 'aurl'] as $key) {
            if (!isset($_POST[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            Helper::log('fibu_saveMatching missing: '.implode(',', $missing));
            Helper::log('fibu_saveMatching POST keys: '.implode(',', array_keys($_POST)));
            Helper::log('fibu_saveMatching raw input: '.file_get_contents('php://input'));
        }

        if ($system === 'onprem') {
            if (file_exists('matching.json')) {
                $oldcontent = file_get_contents('matching.json');
            }
        } else {
            $db = Factory::getDBO();
            $db->setQuery('SELECT userid from #__apianbindungen_user where token='.$db->quote($_POST['user'] ?? ''));
            $userid = $db->loadResult();
            if (file_exists("components/com_apianbindungen/fibuExport/{$userid}/matchings")) {
                $matchingfilepath = "components/com_apianbindungen/fibuExport/{$userid}/matchings/{$userid}_{$system}_matching.json";
                if (file_exists($matchingfilepath)) {
                    $oldcontent = file_get_contents($matchingfilepath);
                }
            } else {
                $matchingfilepath = "components/com_apianbindungen/fibuExport/matchings/{$userid}_{$system}_matching.json";
                if (file_exists($matchingfilepath)) {
                    $oldcontent = file_get_contents($matchingfilepath);
                }
            }
        }

        $profiles = json_decode($oldcontent, true);
        $data = [];
        foreach ($_POST as $k => $v) {
            if (substr($k, 0, strlen('name')) !== 'name') {
                continue;
            }

            $id = substr($k, strlen('name'));
            if ($v == '[::Stempel::]' && isset($_POST['tag'.$id])) {
                $data['Stempel'] = $_POST['tag'.$id];
                continue;
            }

            if ($_POST['group'.$id] == '0') {
                // empty selection
            } elseif ($_POST['group'.$id] == '1') {
                $data[$v] = $_POST['fix'.$id];
                $data[$v.'group'] = $_POST['group'.$id];
            } else {
                $data[$v] = $_POST['tag'.$id];
                $data[$v.'group'] = $_POST['group'.$id];
            }

            $maxLenKey = 'maxlen'.$id;
            if (isset($_POST[$maxLenKey]) && $_POST[$maxLenKey] !== '') {
                $maxLen = intval($_POST[$maxLenKey]);
                if ($maxLen > 0) {
                    $data[$v.'maxlen'] = $maxLen;
                }
            }

            if ($_POST['group'.$id] != '0' && $_POST['function'.$id] == '1') {
                $functiondef = $_POST['functiondef'.$id];
                $regexSet = [
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

                $valid = false;
                foreach ($regexSet as $regex) {
                    if (preg_match($regex, $functiondef)) {
                        $valid = true;
                        break;
                    }
                }

                if ($valid) {
                    $data[$v.'func'] = $functiondef;
                } else {
                    $response = (object) [];
                    $response->status = 'error';
                    $response->message = "Fehler bei Funktion fuer $v.";
                    echo json_encode($response);
                    die;
                }
            }
        }

        $profileSelect = $_POST['profileselect'] ?? '';
        $profileName = $_POST['profilename'] ?? '';
        $aurl = $_POST['aurl'] ?? '';
        if ($profileSelect === '0' && $profileName === '') {
            $response = (object) [];
            $response->status = 'ok';
            $response->message = 'Bitte Profil auswaehlen oder einen neuen Profilnamen angeben.';
            echo json_encode($response);
            die;
        }

        $data['sys'] = $system;
        $data['url'] = $aurl;
        $profilename = $profileSelect === '0' ? $profileName : $profileSelect;

        if ($system === 'onprem') {
            $profiles[$profilename] = $data;
            file_put_contents('matching.json', json_encode($profiles));
        } else {
            $db = Factory::getDBO();
            $db->setQuery('SELECT userid from #__apianbindungen_user where token='.$db->quote($_POST['user']));
            $userid = $db->loadResult();
            $profiles[$profilename] = $data;
            if (!file_exists('components/com_apianbindungen/fibuExport/'.$userid)) {
                mkdir('components/com_apianbindungen/fibuExport/'.$userid);
            }
            if (!file_exists("components/com_apianbindungen/fibuExport/{$userid}/matchings")) {
                mkdir("components/com_apianbindungen/fibuExport/{$userid}/matchings");
            }
            file_put_contents("components/com_apianbindungen/fibuExport/{$userid}/matchings/{$userid}_{$_POST['system']}_matching.json", json_encode($profiles));
        }

        $response = (object) [];
        $response->status = 'ok';
        $response->message = 'Gespeichert.';
        if ($_POST['profileselect'] == '0') {
            $response->profile = $data;
            $response->profilename = $profilename;
            $response->status = 'newProfile';
        }
        echo json_encode($response);
        die;
    }
}
