<?php

namespace App\Service\Processing;

use App\Dto\RenderedBlock;
use RuntimeException;

class TemplateRenderer
{
    /**
     * @param array<int, array<string, array<int, object>>> $matrix
     * @param array<int, array<string, mixed>> $documentList
     * @param callable $selectionResolver function(string $nodeId): array
     * @return RenderedBlock[]
     */
    public function render(array $matrix, array $matching, string $template, callable $selectionResolver, array $documentList = [], array &$debug = []): array
    {
        if ($template === '') {
            throw new RuntimeException('Template leer.');
        }

        $datamatrix = $matrix;
        $gdi_mode = false;
        if (str_contains($template,"[:splitstart:]") && str_contains($template,"[:splitend:]")) {
            $gdi_mode = true;
        }
        $split_period = false;
        if (str_contains($template,"{split_periode}")) {
            $template = str_replace("{split_periode}","",$template);
            $split_period = true;
        }
        $accurate_split = false;
        if (str_contains($template,"{accurate_max_split}")) {
            $template = str_replace("{accurate_max_split}","",$template);
            $accurate_split = true;
        }

        $tempmatrix = [];
        $gdi_mode_id = 0;
        $max_split_count = 1;
        $lines = [];

        foreach ($datamatrix as $collection) {
            $singles = [];
            $groups = [];
            foreach ($collection as $foo) {
                if (count($foo)==1) {
                    $singles[] = $foo[0];
                } else if (count($foo)>1) {
                    foreach ($foo as $tag) {
                        if (isset($groups[$tag->tagGroupDefinitionId])) {
                            $groups[$tag->tagGroupDefinitionId][] = $tag;
                        } else {
                            $groups[$tag->tagGroupDefinitionId] = [$tag];
                        }
                    }
                }
            }
            if (count($groups)>0) {
                $temp = [];
                foreach ($groups as $k=>$taggroupdefintion) {
                    $temp[$k] = [];
                    foreach ($taggroupdefintion as $tag) {
                        if (isset($temp[$k][$tag->tagGroupId])) {
                            $temp[$k][$tag->tagGroupId][] = $tag;
                        } else {
                            $temp[$k][$tag->tagGroupId] = [$tag];
                        }
                    }
                }
                $temp = array_values($temp);
                for ($i = 0; $i < count($temp); $i++) {
                    $temp[$i] = array_values($temp[$i]);
                }
                $rows = [];
                for ($i = 0; $i < count($temp[0]); $i++) {
                    $rows[$i] = [];
                }
                for ($i = 0; $i < count($temp); $i++) {
                    foreach ($temp as $foo) {
                        foreach ($foo as $k=>$foo2) {
                            foreach ($foo2 as $tag) {
                                $tag->is_split = true;
                                $rows[$k][$tag->tagDefinitionId] = $tag;
                            }
                        }

                    }
                }
                foreach ($rows as $k=>$row) {
                    foreach ($singles as $tag) {
                        $rows[$k][$tag->tagDefinitionId] = $tag;
                    }
                }
                if ($gdi_mode) {
                    foreach ($rows as $k=>$row) {
                        if ($k==0) {
                            $rows[$k]["first_split"] = true;
                        }
                        $rows[$k]["gdi_mode"] = $gdi_mode_id;
                    }
                    if ($max_split_count<count($rows)) {
                        $max_split_count = count($rows);
                    }
                    if ($accurate_split) {
                        $lines[] = count($rows);
                    }
                    $gdi_mode_id++;
                }
                $tempmatrix = array_merge($tempmatrix,$rows);
            } else if (count($singles)>0) {
                $temparray = [];
                foreach ($singles as $tag) {
                    $temparray[$tag->tagDefinitionId] = $tag;
                }
                $tempmatrix[] = $temparray;
                if ($accurate_split) {
                    $lines[] = 1;
                }
            }

        }
        $datamatrix = $tempmatrix;

        $splitmatrix = [];
        if ($split_period && isset($matching['[::WCX_Split_Periode::]'])) {
            foreach ($datamatrix as $row) {
                $key = null;
                $tag = $row[$matching['[::WCX_Split_Periode::]']] ?? null;
                if ($tag==null) {
                    if (isset($matching['[::WCX_Split_Periode::]']) && isset($matching["[::WCX_Split_Periode::]func"])) {
                        $key = $this->applyFunction($matching["[::WCX_Split_Periode::]func"],$matching['[::WCX_Split_Periode::]'], $matching, $row);
                    } else {
                        $key = "";
                    }
                } else if ($tag->type=="singleLineStrings") {
                    if (isset($matching["[::WCX_Split_Periode::]func"])) {
                        $key = $this->applyFunction($matching["[::WCX_Split_Periode::]func"],$tag->value, $matching, $row);
                    } else {
                        $key = $tag->value;
                    }
                } else if ($tag->type=="numbers") {
                    if (isset($matching["[::WCX_Split_Periode::]func"])) {
                        $key = $this->applyFunction($matching["[::WCX_Split_Periode::]func"],$tag->value, $matching, $row);
                    } else {
                        $key = strval(floatval($tag->value)/10000);
                    }
                } else if ($tag->type=="dates") {
                    if (isset($matching["[::WCX_Split_Periode::]func"])) {
                        $key = $this->applyFunction($matching["[::WCX_Split_Periode::]func"],$tag->value, $matching, $row);
                    } else {
                        $dateTime = new \DateTime($tag->value);
                        $german_time= new \DateTimeZone('Europe/Berlin');
                        $dateTime->setTimezone($german_time);
                        $date = $dateTime->format("d.m.Y");
                        $key = $date;
                    }
                } else if ($tag->type=="selections") {
                    $node = $tag->selectedNodeIds[0];
                    $response = $selectionResolver($node);
                    if (!empty($response)) {
                        $temp = json_decode(json_encode($response));
                        if (isset($matching["[::WCX_Split_Periode::]func"])) {
                            $key = $this->applyFunction($matching["[::WCX_Split_Periode::]func"],$temp->value,$matching,$row);
                        } else {
                            $key = $temp->value ?? "";
                        }
                    } else {
                        $key = "";
                    }
                } else {
                    $key = "";
                }
                if (!isset($splitmatrix[$key])) {
                    $splitmatrix[$key] = [];
                }
                $splitmatrix[$key][] = $row;
            }
        } else {
            $splitmatrix["nosplit"] = $datamatrix;
        }

        $output = [];
        foreach ($splitmatrix as $period=>$rows) {
            $newString = $template;
            $splits_marker = [];
            if ($gdi_mode && !$accurate_split) {
                $positions = array();
                $startPosition = 0;
                while (($startPosition = strpos($newString, "[:splitstart:]", $startPosition)) !== false) {
                    $endPosition = strpos($newString, "[:splitend:]", $startPosition);
                    if ($endPosition !== false) {
                        $positions[] = array($startPosition, $endPosition);
                        $startPosition = $endPosition + strlen("[:splitend:]");
                    } else {
                        break;
                    }
                }
                foreach ($positions as $position) {
                    $startMarker = $position[0];
                    $endMarker = $position[1];
                    $substring = substr($newString, $startMarker + strlen("[:splitstart:]"), $endMarker - $startMarker - strlen("[:splitstart:]"));
                    $regex = '/\[:((?!splitstart|splitend)[^:]+):\]/';
                    $matches = [];
                    if (preg_match_all($regex, $substring, $matches)) {
                        $splits_marker = array_merge($splits_marker,$matches[0]);
                    }
                    $repeatedSubstring = "";
                    for ($i = 0; $i < $max_split_count; $i++) {
                        $repeatedSubstring .= $substring;
                    }
                    $newString = str_replace(substr($newString, $startMarker, $endMarker - $startMarker + strlen("[:splitend:]")), $repeatedSubstring, $newString);
                }
            }

            $positions = array();
            $startPosition = 0;
            while (($startPosition = strpos($newString, "[:repeatstart:]", $startPosition)) !== false) {
                $endPosition = strpos($newString, "[:repeatend:]", $startPosition);
                if ($endPosition !== false) {
                    $positions[] = array($startPosition, $endPosition);
                    $startPosition = $endPosition + strlen("[:repeatend:]");
                } else {
                    break;
                }
            }
            $repeatedMarkers = [];
            foreach ($positions as $position) {
                $startMarker = $position[0];
                $endMarker = $position[1];
                $substring = substr($newString, $startMarker + strlen("[:repeatstart:]"), $endMarker - $startMarker - strlen("[:repeatstart:]"));
                $regex = '/\[:((?!repeatstart|repeatend)[^:]+):\]/';
                $matches = [];
                if (preg_match_all($regex, $substring, $matches)) {
                    $repeatedMarkers = array_merge($repeatedMarkers,$matches[0]);
                }
                $repetitions = $gdi_mode ? count($documentList) : count($rows);
                $repeatedSubstring = "";
                for ($i = 0; $i < $repetitions; $i++) {
                    $repeatedSubstring .= $substring;
                }
                $newString = str_replace(substr($newString, $startMarker, $endMarker - $startMarker + strlen("[:repeatend:]")), $repeatedSubstring, $newString);
            }

            $mode = 1;
            $encode_to_ansi = false;
            if (str_contains($newString,"{pattern_mode_2}")) {
                $newString = str_replace("{pattern_mode_2}","",$newString);
                $mode = 2;
            } else if (str_contains($newString,"{pattern_mode_3}")) {
                $newString = str_replace("{pattern_mode_3}","",$newString);
                $mode = 3;
            }
            if (str_contains($newString,"{encoding:ANSI}")) {
                $newString = str_replace("{encoding:ANSI}","",$newString);
                $encode_to_ansi = true;
            }
            $preset_filename = "";
            $pattern = '/\{filename:([^}]*)\}/';
            preg_match($pattern,$newString,$found_filename);
            if (!empty($found_filename)) {
                $preset_filename = $found_filename[1];
                $newString = preg_replace($pattern, "", $newString);
            }

            if ($accurate_split) {
                $positions = [];
                $startPosition = 0;
                while (($startPosition = strpos($newString, "[:splitstart:]", $startPosition)) !== false) {
                    $endPosition = strpos($newString, "[:splitend:]", $startPosition);
                    if ($endPosition !== false) {
                        $positions[] = array($startPosition, $endPosition);
                        $startPosition = $endPosition + strlen("[:splitend:]");
                    } else {
                        break;
                    }
                }
                foreach ($positions as $booking_counter=>$position) {
                    $startMarker = $position[0];
                    $endMarker = $position[1];
                    $substring = substr($newString, $startMarker + strlen("[:splitstart:]"), $endMarker - $startMarker - strlen("[:splitstart:]"));
                    $regex = '/\[:((?!splitstart|splitend)[^:]+):\]/';
                    $matches = [];
                    if (preg_match_all($regex, $substring, $matches)) {
                        $splits_marker = array_merge($splits_marker,$matches[0]);
                    }
                    $repeatedSubstring = "";
                    for ($i = 0; $i < $lines[$booking_counter]; $i++) {
                        $repeatedSubstring .= $substring;
                    }
                    $before = substr($newString, 0, $startMarker);
                    $after = substr($newString, $endMarker + strlen("[:splitend:]"));
                    $newString = $before . $repeatedSubstring . $after;
                }
            }

            foreach($repeatedMarkers as $marker) {
                $accurate_counter = 0;
                $line_tracker = 0;
                $counter = 0;

                if ($mode==2 || $mode==3) {
                    $regex = "/\[:".substr($marker,2,strlen($marker)-4).":\]/";
                    $regex = str_replace("|","\|",$regex);

                } else {
                    $regex = "/\[:".substr($marker,2,strlen($marker)-4).":\]/";
                }
                $split_block = -1;

                $newString = preg_replace_callback($regex, function($match) use (&$rows, &$matching, &$marker, &$counter, &$mode, &$gdi_mode, &$splits_marker, &$max_split_count, &$split_block, &$accurate_counter, &$accurate_split, &$lines, &$line_tracker){
                    if (!isset($matching[$marker])) {
                        return "";
                    }
                    $temp = $matching[$marker];
                    $prefix = "";
                    if ($mode==2) {
                        $prefix = explode("|",substr($marker,2,strlen($marker)-4))[0];
                    }

                    if ($gdi_mode && !$accurate_split) {
                        if (array_search($marker,$splits_marker)===false) {
                            $counter++;
                            while (isset($rows[$counter]["gdi_mode"]) && !isset($rows[$counter]["first_split"])) {
                                $counter++;
                            }
                        } else {
                            if ($split_block==-1) {
                                $counter++;
                                $split_block++;
                            } else {
                                if (isset($rows[$counter]["gdi_mode"])) {
                                    $split_block++;
                                    if ($counter-1>=0 && isset($rows[$counter-1]["gdi_mode"]) && $rows[$counter-1]["gdi_mode"]==$rows[$counter]["gdi_mode"]) {
                                        $counter++;
                                    } else {
                                        if ($split_block%$max_split_count==0) {
                                            $split_block=0;
                                            $counter++;
                                        } else {
                                            return "";
                                        }
                                    }
                                } else {
                                    $split_block++;
                                    if ($split_block>=$max_split_count) {
                                        $split_block=0;
                                        $counter++;
                                    } else {
                                        return "";
                                    }
                                }

                            }
                        }
                    } else {
                        $counter++;
                    }

                    if ($accurate_split) {
                        $index = $accurate_counter;
                        if (in_array($marker,$splits_marker)) {
                            $accurate_counter++;
                        } else {
                            $accurate_counter += $lines[$line_tracker];
                            $line_tracker++;
                        }
                    } else {
                        $index = $counter-1;
                    }

                    if (isset($rows[$index][$temp])) {
                        $tag = $rows[$index][$temp];
                        if ($tag->type=="singleLineStrings") {
                            if (isset($matching[$marker."func"])) {
                                return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $rows[$index]);
                            } else {
                                return $prefix.$tag->value;
                            }
                        } else if ($tag->type=="numbers") {
                            if (isset($matching[$marker."func"])) {
                                return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $rows[$index]);
                            } else {
                                return $prefix.strval(floatval($tag->value)/10000);
                            }
                        } else if ($tag->type=="dates") {
                            if (isset($matching[$marker."func"])) {
                                return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $rows[$index]);
                            } else {
                                $dateTime = new \DateTime($tag->value);
                                $german_time= new \DateTimeZone('Europe/Berlin');
                                $dateTime->setTimezone($german_time);
                                $date = $dateTime->format("d.m.Y");
                                return $prefix.$date;
                            }
                        } else if ($tag->type=="selections") {
                            if (isset($matching[$marker."func"])) {
                                return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $rows[$index]);
                            } else {
                                return $prefix.($tag->value ?? '');
                            }
                        } else {
                            return "";
                        }
                    } else {
                        if (isset($matching[$marker."group"]) && $matching[$marker."group"]=="1") {
                            if (isset($matching[$marker."func"])) {
                                return $prefix.$this->applyFunction($matching[$marker."func"],$temp, $matching, $rows[$index]);
                            } else {
                                return $prefix.$temp;
                            }
                        } else {
                            return "";
                        }
                    }
                },$newString);
            }

            $regex = '/\[:((?!repeatstart|repeatend)[^:]+):\]/';
            $newString = preg_replace_callback($regex, function($match) use (&$rows, &$matching, $selectionResolver){
                if (!isset($matching[$match[0]])) return "";
                $temp = $matching[$match[0]];
                $marker = $match[0];
                if (isset($rows[0][$temp])) {
                    $tag = $rows[0][$temp];
                    if ($tag->type=="singleLineStrings") {
                        if (isset($matching[$marker."func"])) {
                            return $this->applyFunction($matching[$marker."func"],$tag->value, $matching, $rows[0]);
                        } else {
                            return $tag->value;
                        }
                    } else if ($tag->type=="numbers") {
                        if (isset($matching[$marker."func"])) {
                            return $this->applyFunction($matching[$marker."func"],$tag->value, $matching, $rows[0]);
                        } else {
                            return strval(floatval($tag->value)/10000);
                        }
                    } else if ($tag->type=="dates") {
                        if (isset($matching[$marker."func"])) {
                            return $this->applyFunction($matching[$marker."func"],$tag->value, $matching, $rows[0]);
                        } else {
                            $dateTime = new \DateTime($tag->value);
                            $german_time= new \DateTimeZone('Europe/Berlin');
                            $dateTime->setTimezone($german_time);
                            $date = $dateTime->format("d.m.Y");
                            return $date;
                        }
                    } else if ($tag->type=="selections") {
                        if (isset($tag->selectedNodeIds) && is_array($tag->selectedNodeIds) && count($tag->selectedNodeIds) > 0) {
                            $node = $tag->selectedNodeIds[0];
                            $response = $selectionResolver($node);
                            if (!empty($response)) {
                                $temp = json_decode(json_encode($response));
                                if (isset($matching[$marker."func"])) {
                                    return $this->applyFunction($matching[$marker."func"],$temp->value ?? '',$matching,$rows[0]);
                                } else {
                                    return $temp->value ?? '';
                                }
                            } else {
                                return "";
                            }
                        } else {
                            $value = isset($tag->value) ? $tag->value : "";
                            if (isset($matching[$marker."func"])) {
                                return $this->applyFunction($matching[$marker."func"], $value, $matching, $rows[0]);
                            } else {
                                return $value;
                            }
                        }
                    } else {
                        return "";
                    }
                } else {
                    if (($matching[$marker."group"] ?? null)=="1") {
                        if (isset($matching[$marker."func"])) {
                            return $this->applyFunction($matching[$marker."func"],$temp,$matching,$rows[0]);
                        } else {
                            return $temp;
                        }
                    } else {
                        return "";
                    }
                }
            },$newString);

            if ($mode==2) {
                $newString = preg_replace('/[^\S\r\n]{2,}/', ' ',$newString);
            }
            $newString = preg_replace('/\s*$/', "", $newString);
            if ($encode_to_ansi) {
                $newString = iconv("UTF-8", "Windows-1252", $newString);
            }

            $excel = false;
            if (str_contains($newString,'{format_excel}')) {
                $newString = str_replace('{format_excel}',"",$newString);
                $excel = true;
            }

            $output[] = new RenderedBlock(
                $newString,
                $preset_filename !== '' ? $preset_filename : null,
                $excel
            );
        }

        return $output;
    }

    private function applyFunction($function,$content,$matching, $data) {
        $matches = [];
        preg_match_all("/\[([^\]]*)\]/", $function, $matches);
        $function_arr = $matches[1];
        switch ($function_arr[0]) {
            case "FORMAT":
                switch ($function_arr[1]) {
                    case "NUMBER":
                        $number = $content/10000;
                        return number_format($number,intval($function_arr[3]),$function_arr[2],"");
                    case "DATE":
                        $dateTime = new \DateTime($content);
                        $german_time= new \DateTimeZone('Europe/Berlin');
                        $dateTime->setTimezone($german_time);
                        $date = $dateTime->format($function_arr[2]);
                        return $date;
                    case "TEXT":
                        $content = (string)$content;
                        switch ($function_arr[2]) {
                            case "GETFIRST":
                                return substr($content,0,intval($function_arr[3]));
                            case "GETFROMTO":
                                return substr($content,intval($function_arr[3]),intval($function_arr[4]));
                            case "PREFIX":
                                return $function_arr[3].$content;
                            case "REMOVEBLANK":
                                return str_replace(' ','',$content);
                        }
                    case "NOW":
                        $datetime = new \DateTime();
                        $german_time= new \DateTimeZone('Europe/Berlin');
                        $datetime->setTimezone($german_time);
                        if (isset($function_arr[2])) {
                            $date = $datetime->format($function_arr[2]);
                        } else {
                            $date = $datetime->format("YmdHisu");
                        }
                        return $date;
                    case "ASTEXT":
                        return (string) $content;
                }
                break;
            case "IF":
                $content = (string)$content;
                for ($i = 1; $i < count($function_arr)-1;$i=$i+2) {
                    $op = explode(":",$function_arr[$i]);
                    switch ($op[0]) {
                        case "STARTSWITH":
                            if (substr($content,0,strlen($op[1]))===$op[1]) {
                                return $function_arr[$i+1];
                            }
                            continue 2;
                        case "ENDSWITH":
                            if (substr($content,-strlen($op[1]))===$op[1]) {
                                return $function_arr[$i+1];
                            }
                            continue 2;
                        case "L":
                            if (floatval($content)<floatval($op[1])) {
                                return $function_arr[$i+1];
                            }
                            continue 2;
                        case "LE":
                            if (floatval($content)<=floatval($op[1])) {
                                return $function_arr[$i+1];
                            }
                            continue 2;
                        case "G":
                            if (floatval($content)>floatval($op[1])) {
                                return $function_arr[$i+1];
                            }
                            continue 2;
                        case "GE":
                            if (floatval($content)>=floatval($op[1])) {
                                return $function_arr[$i+1];
                            }
                            continue 2;
                        case "E":
                            if ($content==$op[1]) {
                                return $function_arr[$i+1];
                            }
                            continue 2;
                    }
                }
                return $function_arr[count($function_arr)-1];
            case "CALCULATE":
                $forced = false;
                if (isset($function_arr[1]) && $function_arr[1]=="FORCED") {
                    $forced = true;
                }
                if (isset($function_arr[2]) && $function_arr[2]=="FORMAT") {
                    $format = true;
                }
                $textMode = in_array('TEXT', $function_arr, true);
                $temp_matches = [];
                preg_match_all('/\[([^\[\]]+)\]/', $content, $temp_matches);
                $calculation = [];
                foreach($temp_matches[0] as $operand) {
                    if (preg_match('/\[\:\:([^:\[\]]+)\:\:\]/',$operand)) {
                        if (!isset($matching[$operand])) {
                            if ($forced) {
                                $calculation[] = $this->wrapLiteral('', $textMode);
                                continue;
                            }

                            return "";
                        }

                        $key = $matching[$operand];
                        if (isset($data[$key])) {
                            $temptag = $data[$key];
                            if (isset($matching[$operand."func"])) {
                                $calculation[] = '"'.$this->applyFunction($matching[$operand."func"],$temptag->value,$matching,$data).'"';
                            } else {
                                if ($temptag->type=="numbers") {
                                    $calculation[] = floatval($temptag->value)/10000;
                                } else {
                                    if (is_numeric($temptag->value)) {
                                        $calculation[] = floatval($temptag->value);
                                    } else {
                                        $calculation[] = $this->wrapLiteral((string) $temptag->value, $textMode);
                                    }
                                }
                            }
                        } else if ($forced) {
                            $calculation[] = $this->wrapLiteral('', $textMode);
                        } else {
                            return "";
                        }
                    } else if (preg_match('/^\[:\w+:\]$/',$operand,$foo)) {
                        $calculation[] = $this->wrapLiteral(substr($foo[0],2,strlen($foo[0])-4), $textMode);
                    } else if (preg_match('/^\[:\p{L}.+:\]$/',$operand,$foo)) {
                        $calculation[] = $this->wrapLiteral(substr($foo[0],2,strlen($foo[0])-4), $textMode);
                    } else if (is_numeric(substr($operand,1,strlen($operand)-2))) {
                        $calculation[] = floatval(substr($operand,1,strlen($operand)-2));
                    } else if ($operand === '[_DOCTYPE]') {
                        if (isset($data['doctype_tagdefinitionid']->value)) {
                            $calculation[] = "'" . addslashes((string) $data['doctype_tagdefinitionid']->value) . "'";
                        } else {
                            $calculation[] = "''";
                        }
                    } else if ($operand === '[DIV]') {
                        $calculation[] = '/';
                    } else if ($operand === '[MUL]') {
                        $calculation[] = '*';
                    } else if ($operand === '[PLU]') {
                        $calculation[] = '+';
                    } else if ($operand === '[MIN]') {
                        $calculation[] = '-';
                    } else if ($operand === '[LP]') {
                        $calculation[] = '{';
                    } else if ($operand === '[RP]') {
                        $calculation[] = '}';
                    } else if ($operand === '[E]') {
                        $calculation[] = '==';
                    } else if ($operand === '[NE]') {
                        $calculation[] = '!=';
                    } else if ($operand === '[OR]') {
                        $calculation[] = '||';
                    } else if ($operand === '[AND]') {
                        $calculation[] = '&&';
                    } else if ($operand === '[NOT]') {
                        $calculation[] = '!';
                    } else if ($operand === '[LE]') {
                        $calculation[] = '<=';
                    } else if ($operand === '[GE]') {
                        $calculation[] = '>=';
                    } else if ($operand === '[L]') {
                        $calculation[] = '<';
                    } else if ($operand === '[G]') {
                        $calculation[] = '>';
                    } else if ($operand === '[IF]') {
                        $calculation[] = 'if';
                    } else if ($operand === '[LB]') {
                        $calculation[] = '(';
                    } else if ($operand === '[RB]') {
                        $calculation[] = ')';
                    } else if ($operand === '[ELSE]') {
                        $calculation[] = 'else';
                    } else if ($operand === '[RET]') {
                        $calculation[] = 'return';
                    } else if ($operand === '[EMPTYSTRING]') {
                        $calculation[] = $this->wrapLiteral('', $textMode);
                    } else if ($operand === '[EMPTY]') {
                        $calculation[] = $this->wrapLiteral('', $textMode);
                    } else if ($operand === '[ENDC]') {
                        $calculation[] = ';';
                    } else if ($operand === '[CONCAT]') {
                        $calculation[] = '.';
                    } else if ($operand === '[ISEMPTY]') {
                        $calculation[] = 'empty';
                    } else if ($operand === '[QUOTE]') {
                        $calculation[] = '"';
                    } else if ($operand === '[NULL]') {
                        $calculation[] = 'NULL';
                    } else if (preg_match('/^\[:[^:\'"]+?:\]$/', $operand, $foo)) {
                        $calculation[] = $this->wrapLiteral(substr($foo[0], 2, strlen($foo[0]) - 4), $textMode);
                    } else {
                        $calculation[] = $operand;
                    }
                }
                $to_calculate = implode(" ",$calculation);
                try {
                    $result = eval($to_calculate);
                } catch (\Throwable $e) {
                    throw new RuntimeException(sprintf('Fehler beim Auswerten des Templates "%s": %s', $to_calculate, $e->getMessage()), 0, $e);
                }
                if (isset($format) && $format) {
                    if (isset($function_arr[3]) && $function_arr[3]=="DATE") {
                        $dateTime = new \DateTime($result);
                        $german_time= new \DateTimeZone('Europe/Berlin');
                        $dateTime->setTimezone($german_time);
                        $date = $dateTime->format($function_arr[4]);
                        return $date;
                    } else if (isset($function_arr[3]) && $function_arr[3]=="NUMBER") {
                        if ($result=="") {
                            return "";
                        } else {
                            return number_format($result,intval($function_arr[5]),$function_arr[4],"");
                        }
                    } else if (isset($function_arr[3]) && $function_arr[3]=="TEXT" && $function_arr[4]=="GETFROMTO") {
                        if ($result=="") {
                            return "";
                        } else {
                            return substr($result,intval($function_arr[5]),intval($function_arr[6]));
                        }

                    }
                }
                if (isset($function_arr[1]) && $function_arr[1]=="TEXT") {
                    return $result;
                }
                if (is_numeric($result)) {
                    return number_format($result,2,",","");
                } else {
                    return $result;
                }
            case "SQL":
                return $content;
            case "DOCTYPE":
                $doctype = $data["doctype_tagdefinitionid"];
                preg_match_all('/\[([^\[\]]+)\]/', $content, $list);
                foreach ($list[1] as $entry) {
                    $exploded = explode(":",$entry);
                    if ($doctype->value==$exploded[0]) {
                        return $exploded[1];
                    }
                }
                return $content;
            case "TAXES":
                $map = [];
                for ($i=2;$i<count($function_arr);$i=$i+2) {
                    $list = explode(",",$function_arr[$i+1]);
                    foreach ($list as $taxcode) {
                        $map[$taxcode] = floatval($function_arr[$i])/100+1;
                    }
                }
                if (isset($matching['[::WCX_TAXES::]'])) {
                    if (isset($data[$matching['[::WCX_TAXES::]']])) {
                        $temptag = $data[$matching['[::WCX_TAXES::]']];
                        if (isset($map[$temptag->value])) {
                            $tax = $map[$temptag->value];
                            return number_format(floatval($content/10000) / $tax, 2,$function_arr[1]);
                        }
                    }
                }
                return $content;
            case "COUNTER":
                static $counter = null;
                if ($counter === null) {
                    $counter = intval($content) - 1;
                }
                $counter += 1;
                return $counter;
            default:
                return $content;

        }
    }

    private function wrapLiteral(string $value, bool $textMode): string
    {
        $quoted = '"' . addslashes($value) . '"';

        if ($textMode) {
            return $quoted;
        }

        return sprintf('\\%s::toNumeric(%s)', self::class, $quoted);
    }

    private static function toNumeric(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $normalized = str_replace(',', '.', $value);
            if (is_numeric($normalized)) {
                return (float) $normalized;
            }
        }

        return 0.0;
    }
}
