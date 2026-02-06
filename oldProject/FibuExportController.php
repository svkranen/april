<?php
// REMINDER - DO NOT REMOVE:
// - delete namespace
// - delete fibu_register
// - only keep class declaration
// - Version 2

    require __DIR__.'/../vendor/autoload.php';
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\IOFactory;

    class FibuExportController 
    {
        protected $contentType = 'Amagnolexoffice';
    
        protected $default_view = 'default';

        public $counter = NULL;
		
        public function fibu_saveMatching() {
            $oldcontent = "[]";
            if ($_POST["system"]=="onprem") {
                if (file_exists("matching.json")) {
                    $oldcontent = file_get_contents("matching.json");
                }
            } else {
                $db = Factory::getDBO();
                $db->setQuery("SELECT userid from #__apianbindungen_user where token=".$db->quote($_POST["user"]));
                $userid = $db->loadResult();
                if (file_exists("components/com_apianbindungen/fibuExport/{$userid}/matchings")) {
                    $matchingfilepath = "components/com_apianbindungen/fibuExport/{$userid}/matchings/".$userid."_".$_POST["system"]."_matching.json";
                    if (file_exists($matchingfilepath)) {
                        $oldcontent = file_get_contents($matchingfilepath);
                    }
                } else {
                    $matchingfilepath = "components/com_apianbindungen/fibuExport/matchings/".$userid."_".$_POST["system"]."_matching.json";
                    if (file_exists($matchingfilepath)) {
                        $oldcontent = file_get_contents($matchingfilepath);
                    }
                }
                
            }
            $profiles = json_decode($oldcontent,true);
            // var_dump($profiles);die;
            $data = [];
            foreach($_POST as $k=>$v) {
                if (substr($k, 0, strlen("name")) === "name") {
                    $id = substr($k,strlen("name"));
                    if ($v=="[::Stempel::]" && isset($_POST["tag".$id])) {
                        $data["Stempel"] = $_POST["tag".$id];
                        continue;
                    }
		            if ($_POST["group".$id]=="0") {//emptyselection
			
                    } else if ($_POST["group".$id]=="1") {//fixvalue
                        $data[$v] = $_POST["fix".$id];
                        $data[$v."group"] = $_POST["group".$id];
                    } else {
                        $data[$v] = $_POST["tag".$id];
                        $data[$v."group"] = $_POST["group".$id];
                    }
                    $maxLenKey = "maxlen".$id;
                    if (isset($_POST[$maxLenKey]) && $_POST[$maxLenKey] !== "") {
                        $maxLen = intval($_POST[$maxLenKey]);
                        if ($maxLen > 0) {
                            $data[$v."maxlen"] = $maxLen;
                        }
                    }

                    if ($_POST["group".$id]!="0" && $_POST["function".$id]=="1") {
                        $functiondef = $_POST["functiondef".$id];
                        $regex_set = ["/^(\[IF\](\[(STARTSWITH|ENDSWITH|L|LE|G|GE|E):[^\]]+\])\[(.+)\])+\[(.+)\]$/",
                            "/^(\[FORMAT\])(\[DATE\])\[(.+)\]$/",
                            "/^(\[FORMAT\])(\[NOW\])\[(.+)\]$/",
                            "/^(\[FORMAT\])(\[NUMBER\])(\[[\.,]\])(\[\d+\])$/",
                            "/^(\[FORMAT\])(\[TEXT\])(\[GETFIRST\])(\[\d+\])$/",
                            "/^(\[FORMAT\])(\[TEXT\])(\[PREFIX\])(\[\w+\])$/",
                            "/^(\[FORMAT\])(\[TEXT\])(\[GETFROMTO\])(\[\d+\])(\[\d+\])$/",
                            "/^(\[FORMAT\])(\[TEXT\])(\[REMOVEBLANK\])$/",
                            "/^(\[FORMAT\])(\[ASTEXT\])$/",
                            "/^\[CALCULATE\]$/",
                            "/^\[CALCULATE\]\[FORCED\]$/",
							"/^\[CALCULATE\]\[FORCED\]\[TEXT\]$/",		  
                            "/^\[CALCULATE\]\[TEXT\]$/",
                            "/^\[CALCULATE\]\[NUMBER\]$/",
                            "/^\[CALCULATE\]\[FORCED\]\[FORMAT\]\[DATE\]\[(.+)\]$/",
                            "/^\[CALCULATE\]\[FORCED\]\[FORMAT\]\[NUMBER\]\[(.+)\]\[(.+)\]$/",
							"/^\[CALCULATE\]\[FORCED\]\[FORMAT\]\[TEXT\]\[GETFROMTO\]\[(.+)\]\[(.+)\]$/",																																							  
			                "/^\[SQL\]\[(.+)\]$/",
                            "/^\[DOCTYPE\]$/",
                            "/^\[COUNTER\]$/",
                            "/^\[TAXES\](\[[\.,]\])(\[\d+%\]\[\d+(,\d+)*\])+$/",

                        ];
                        $valid = false;
                        foreach ($regex_set as $regex) {
                            if (preg_match($regex,$functiondef)) {
                                // echo $regex;die;
                                $valid = true;
                                break;
                            }
                        }
                        if ($valid) {
                            $data[$v."func"] = $functiondef;
                        } else {
                            $response = (object)[];
                            $response->status = "error";
                            $response->message = "Fehler bei Funktion für $v.";
                            echo json_encode($response);
                            die;
                        }
                    }
                }

            }
            if ($_POST["profileselect"]=="0") {
                if ($_POST["profilename"]=="") {
                    $response = (object)[];
                    $response->status = "ok";
                    $response->message = "Bitte Profil auswählen oder einen neuen Profilnamen angeben.";
                    echo json_encode($response);
                    die;
                }
            }
            // var_dump($_POST);die;
            $data["sys"] = $_POST["system"];
            $data["url"] = $_POST["aurl"];
            if ($_POST["profileselect"]=="0") {
                $profilename = $_POST["profilename"];
            } else {
                $profilename = $_POST["profileselect"];
            }
            if ($_POST["system"]=="onprem") {
                //$data["auser"] = $_POST["auser"];
                //$data["apw"] = $_POST["apw"];
                $profiles[$profilename] = $data;
                file_put_contents("matching.json",json_encode($profiles));
            } else {
                $db = Factory::getDBO();
                $db->setQuery("SELECT userid from #__apianbindungen_user where token=".$db->quote($_POST["user"]));
                $userid = $db->loadResult();
                // var_dump($profiles);die;
                $profiles[$profilename] = $data;
                if (!file_exists("components/com_apianbindungen/fibuExport/".$userid)) {
                    mkdir("components/com_apianbindungen/fibuExport/".$userid);
                }
                if (!file_exists("components/com_apianbindungen/fibuExport/{$userid}/matchings")) {
                    mkdir("components/com_apianbindungen/fibuExport/{$userid}/matchings");
                }
                file_put_contents("components/com_apianbindungen/fibuExport/{$userid}/matchings/".$userid."_".$_POST["system"]."_matching.json",json_encode($profiles));
            }
            $response = (object)[];
            $response->status = "ok";
            $response->message = "Gespeichert.";
            if ($_POST["profileselect"]=="0") {
                $response->profile = $data;
                $response->profilename = $profilename;
                $response->status = "newProfile";
            }
            echo json_encode($response);
            die;
        }

        public function fibu_deleteProfile() {
            $oldcontent = "[]";
            if ($_POST["system"]=="onprem") {
                if (file_exists("matching.json")) {
                    $oldcontent = file_get_contents("matching.json");
                }
            } else {
                if (file_exists("components/com_apianbindungen/fibuExport/{$userid}/matchings")) {
                    $db = Factory::getDBO();
                    $db->setQuery("SELECT userid from #__apianbindungen_user where token=".$db->quote($_POST["user"]));
                    $userid = $db->loadResult();
                    $matchingfilepath = "components/com_apianbindungen/fibuExport/{$userid}/matchings/".$userid."_".$_POST["system"]."_matching.json";
                    if (file_exists($matchingfilepath)) {
                        $oldcontent = file_get_contents($matchingfilepath);
                    }
                } else {
                    $db = Factory::getDBO();
                    $db->setQuery("SELECT userid from #__apianbindungen_user where token=".$db->quote($_POST["user"]));
                    $userid = $db->loadResult();
                    $matchingfilepath = "components/com_apianbindungen/fibuExport/matchings/".$userid."_".$_POST["system"]."_matching.json";
                    if (file_exists($matchingfilepath)) {
                        $oldcontent = file_get_contents($matchingfilepath);
                    }
                }
                
            }
            $profiles = json_decode($oldcontent,true);
            if (isset($profiles[$_POST["profile"]])) {
                if ($_POST["system"]=="onprem") {
                    unset($profiles[$_POST["profile"]]);
                    file_put_contents("matching.json",json_encode($profiles));
                } else {
                    // $db = Factory::getDBO();
                    // $db->setQuery("SELECT userid from #__apianbindungen_user where token=".$db->quote($_POST["user"]));
                    // $userid = $db->loadResult();
                    // var_dump($profiles);die;
                    unset($profiles[$_POST["profile"]]);
                    file_put_contents("components/com_apianbindungen/fibuExport/{$userid}/matchings/".$userid."_".$_POST["system"]."_matching.json",json_encode($profiles));
                }
            }
            $response = (object)[];
            $response->message = "Gelöscht.";
            $response->profilename = $profilename;
            $response->status = "deletedProfile";
            echo json_encode($response);
            die;
        }

        public function fibu_get() {
            $db = Factory::getDBO();
            $db->setQuery("SELECT userid from #__apianbindungen_user where token=".$db->quote($_GET["a"]));
            $userid = $db->loadResult();
            // var_dump($userid);die;
            if (file_exists("components/com_apianbindungen/fibuExport/{$userid}/templates")) {
                $directory = "components/com_apianbindungen/fibuExport/{$userid}/templates";
            } else {
                $directory = "components/com_apianbindungen/fibuExport/templates";
                
            }
            $files = array_diff(scandir($directory),[".",".."]);
            foreach ($files as $file) {
                if (substr($file, 0, strlen($_GET["sys"])) === $_GET["sys"]) {
                    $content = file_get_contents($directory."/".$file);
                    $regex = '/\[:((?!repeatstart|repeatend|splitstart|splitend)[^:]+):\]/';
                    $matches = [];
                    if (preg_match_all($regex, $content, $matches)) {
                    } else {
                        echo "no match";die;
                    }
                    $regex2 = "/\[\:\:[^:\[\]]+\:\:\]/";
                    if (preg_match_all($regex2, $content, $matches2)) {

                    }
                    echo json_encode(array_merge($matches[0],$matches2[0]));
                    die;
                }
            }
            die;
        }

        public function fibu_export() {
			Helper::log(json_encode($_POST));
            // echo 0;die;
            if (isset($_GET["test"])) {
                // $testinput = json_decode('');
                

                // if (isset($_POST["token"])) {
                //     $testinput->atoken = $_POST["token"];
                // }
                // $_POST = (array) $testinput;
                // var_dump($_POST);
                // die;
            }
            
            ini_set("max_execution_time","900");
            $params = ["secret","atoken","system","export","magnetid"];
            
            // Add template parameter if provided
            if (isset($_POST["template"])) {
                $template_param = $_POST["template"];
            } else {
                $template_param = null;
            }
            
            if (!in_array($_POST["export"],["local","ftp","amagno","sql"])) {
                Helper::log("export method not allowed");
                echo 'Ungültiger Wert für export';
                die;
            }
            if ($_POST["export"]=="ftp") {
                if (isset($_POST["ftp_server"]) && isset($_POST["ftp_user"]) && isset($_POST["ftp_password"]) && isset($_POST["ftp_folder"]) ) {
                } else {
                    Helper::log("missing ftp details");
                    echo "FTP Logindaten sind unvollständig";
                    die;
                }
            }
            if ($_POST["export"]=="local") {
                if (!isset($_POST["folder"])) {
                    echo "Parameter folder fehlt";
                    die;
                }
            }
			if ($_POST["export"]=="amagno") {
                if (!isset($_POST["vaultid"])) {
                    echo "Parameter vaultid fehlt";
                    die;
                }
            }
            if ($_POST["export"]=="sql") {
                if (!isset($_POST["dbhost"])) {
                    echo "Parameter dbhost fehlt";
                    die;
                }
                if (!isset($_POST["dbname"])) {
                    echo "Parameter dbname fehlt";
                    die;
                }
                if (!isset($_POST["dbuser"])) {
                    echo "Parameter dbuser fehlt";
                    die;
                }
                if (!isset($_POST["dbpassword"])) {
                    echo "Parameter dbpassword fehlt";
                    die;
                }
            }
            Helper::stampCheck($params);
            Helper::issetParams($params);
            $system = $_POST["system"];
            $export = $_POST["export"];
            if ($system=="onprem") {

            } else {
                $uri = Uri::getInstance();
                $urlpath = explode("/",$uri->getPath());
                Helper::log("Rate limiting..");
                Helper::log($urlpath[2]);
                $userid = RateLimiter::limit($urlpath[2]);
                Helper::log("..passed");
            }
            //read necessary files
            $magnetid = $_POST["magnetid"];
            $matching = [];
            $templatefile = "";
            $templatefile_name = "";
            if ($system == "onprem") {
                if (file_exists("matching.json")) {
                    $matchingfile = file_get_contents("matching.json");
                    
                    if (isset($_POST["profile"])) {
						$tempjson = json_decode($matchingfile,true);						  
                        if (isset($tempjson[$_POST["profile"]])) {
                            $matching = $tempjson[$_POST["profile"]];
                        } else {
                            $matching = reset($tempjson);
                        }
                    } else {
                        $matching = reset($tempjson);
                    }
                    // $matching = json_decode($matchingfile,true);
                    if ($template_param !== null) {
                        // Use specific template file if provided
                        if (file_exists($template_param)) {
                            $templatefile_name = $template_param;
                            $templatefile = file_get_contents($templatefile_name);
                        } else {
                            Helper::log("Template file not found: " . $template_param);
                            echo "Template file not found: " . $template_param;
                            die;
                        }
                    } else {
                        // Use default behavior - find file starting with system name
                        $files = array_diff(scandir(getcwd()),[".",".."]);
                        foreach ($files as $file) {
                            if (substr($file, 0, strlen($system)) === $system) {
                                $templatefile_name = $file;
                                $templatefile = file_get_contents($templatefile_name);
                                break;
                            }
                        }
                    }
                } else {
                    Helper::log("couldn't find matching file");
                    die;
                }
            } else {
                // if (isset($_GET["test"])) {echo "components/com_apianbindungen/fibuExport/matchings/".$userid."_".$system."_matching.json";die;}
                if (file_exists("components/com_apianbindungen/fibuExport/".$userid)) {
                    if (file_exists("components/com_apianbindungen/fibuExport/{$userid}/matchings/{$userid}_{$system}_matching.json")) {
                        $matchingfile = file_get_contents("components/com_apianbindungen/fibuExport/{$userid}/matchings/{$userid}_{$system}_matching.json");
                        
                        $tempjson = json_decode($matchingfile,true);
                        $matching;
                        if (isset($_POST["profile"])) {
                            if (isset($tempjson[$_POST["profile"]])) {
                                $matching = $tempjson[$_POST["profile"]];
                            } else {
                                $matching = reset($tempjson);
                            }
                        } else {
                            $matching = reset($tempjson);
                        }
                        // var_dump();die;
                        $directory = "components/com_apianbindungen/fibuExport/{$userid}/templates";
                        $files = array_diff(scandir($directory),[".",".."]);
                        foreach ($files as $file) {
                            if (substr($file, 0, strlen($system)) === $system) {
                                $templatefile = file_get_contents($directory."/".$file);
                                $templatefile_name = $file;
                                break;
                            }
                        }                 
                    } else {
                        Helper::log("couldn't find matching file");
                        die;
                    }
                } else {
                    if (file_exists("components/com_apianbindungen/fibuExport/matchings/".$userid."_".$system."_matching.json")) {
                        $matchingfile = file_get_contents("components/com_apianbindungen/fibuExport/matchings/".$userid."_".$system."_matching.json");
                        
                        $tempjson = json_decode($matchingfile,true);
                        $matching;
                        if (isset($_POST["profile"])) {
                            if (isset($tempjson[$_POST["profile"]])) {
                                $matching = $tempjson[$_POST["profile"]];
                            } else {
                                $matching = reset($tempjson);
                            }
                        } else {
                            $matching = reset($tempjson);
                        }
                        // var_dump();die;
                        $directory = "components/com_apianbindungen/fibuExport/templates";
                        $files = array_diff(scandir($directory),[".",".."]);
                        foreach ($files as $file) {
                            if (substr($file, 0, strlen($system)) === $system) {
                                $templatefile = file_get_contents($directory."/".$file);
                                $templatefile_name = $file;
                                break;
                            }
                        }                 
                    } else {
                        Helper::log("couldn't find matching file");
                        die;
                    }
                }
                
            }
            //get documents from magnet
            $host = $matching["url"];
            $atoken = "";
/*
            if ($system=="onprem") {
                $header = array();
                $header[] = "Content-Type: text/json";
                $header[] = "Accept: application/json";
                $data = '{"userName": "'.$matching["auser"].'","password": "'.$matching["apw"].'"}';
                $response = Helper::curl2($host."/api/v2/token", "POST", $data, $header);
                if ($response[0]!=200) {
                    Helper::log("amagno login failed");
                    die;
                }
                $atoken = json_decode($response[1]);
            } else {
                $atoken = $_POST["atoken"];
            }
*/
            $atoken = $_POST["atoken"];
            $header = array();
            $header[] = "Authorization: Bearer ".$atoken;
            $header[] = "Accept: application/json";
			Helper::log($host."/api/v2/magnets/".$magnetid."/documents?count=200");
            $response = Helper::curl2($host."/api/v2/magnets/".$magnetid."/documents?count=200","get",null,$header);
            if (isset($_GET["test"])) {
                if (isset($_POST["id"])) {
                    $response = Helper::curl2($host."/api/v2/documents/".$_POST["id"],"get",null,$header);
                } else {
                    $response = Helper::curl2($host."/api/v2/magnets/".$magnetid."/documents?count=200","get",null,$header);
                }
                
            }
            $documentlist = [];

            if ($response[0]==200) {
                $documentlist = json_decode($response[1]);
            } else {
                Helper::log("couldnt get documents from magnet:".json_encode($response));
                die;
            }
            if (isset($_GET["test"]) && isset($_POST["id"])) {
                $documentlist = [$documentlist];
            }

            $templatefile = preg_replace_callback('/\[\:\:([^:\[\]]+)\:\:\]/', function($match) use (&$references, &$matching) {
                return ''; // Replace the match with an empty string
            }, $templatefile);
            //check tags
            $datamatrix = [];
            

            
            
            foreach ($documentlist as $document) {
                $response = Helper::curl2($host."/api/v2/documents/".$document->id."/tags", "GET", null, $header);
                $taglist;
                if ($response[0]==200) {
                    $taglist = json_decode($response[1]);
                } else {
                    Helper::log("failed to get all tags.".json_encode($response));
                    continue;
                }
                // var_dump($matching);die;
                $docnumbertag = (object)[];
                $docnumbertag->id = "4b31e4d5-28cd-4c77-8b30-1b3a5861415e";
                $docnumbertag->tagDefinitionId = "4b31e4d5-28cd-4c77-8b30-1b3a5861415e";
                $docnumbertag->value = intval($document->documentNumber)/10000;
                $taglist->numbers[] = $docnumbertag;
                $datamatrix[$document->id] = [];
                // $datamatrix[$document->id]["doctype"] = $document->documentTypeId;
                foreach($taglist as $k=>$group) {
                    foreach ($group as $tag) {
                        if (in_array($tag->tagDefinitionId,$matching)) {
                            if ($k == "selections") {
                                if (count($tag->selectedNodeIds)<=0) {
                                    continue;
                                }
                                $node = $tag->selectedNodeIds[0];
                                $response = Helper::curl2($host."/api/v2/selection-definition-nodes/".$node, "GET", null, $header);
                                $selected_node = json_decode($response[1]);
                                $selected_node->type = $k;
                                $selected_node->tagDefinitionId = $tag->tagDefinitionId;
                                $selected_node->tagGroupDefinitionId = $tag->tagGroupDefinitionId;
                                $selected_node->tagGroupId = $tag->tagGroupId;
                                if (isset($datamatrix[$document->id][$tag->tagDefinitionId])) {
                                    array_push($datamatrix[$document->id][$tag->tagDefinitionId],$selected_node);
                                } else {
                                    $datamatrix[$document->id][$tag->tagDefinitionId] = [$selected_node];
                                }
                            } else {
                                $tag->type = $k;
                                if (isset($datamatrix[$document->id][$tag->tagDefinitionId])) {
                                    array_push($datamatrix[$document->id][$tag->tagDefinitionId],$tag);
                                } else {
                                    $datamatrix[$document->id][$tag->tagDefinitionId] = [$tag];
                                }
                            }
                            
                        }
                    }
                }
                $doctype = (object)[];
                $doctype->id="doctype_id";
                $doctype->tagDefinitionId="doctype_tagdefinitionid";
                $doctype->value=$document->documentTypeId;
                $datamatrix[$document->id]["doctype"] = [$doctype];
            }
            $datamatrix = array_values($datamatrix);
            $gdi_mode = false;
            if (str_contains($templatefile,"[:splitstart:]") && str_contains($templatefile,"[:splitend:]")) {
                $gdi_mode = true;
            }
            $split_period = false;
            if (str_contains($templatefile,"{split_periode}")) {
                $templatefile = str_replace("{split_periode}","",$templatefile);
                $split_period = true;
            }
            $accurate_split = false;
            if (str_contains($templatefile,"{accurate_max_split}")) {
                $templatefile = str_replace("{accurate_max_split}","",$templatefile);
                $accurate_split = true;
            }
            // var_dump($accurate_split);die;
            // var_dump($split_period);die;
            
            $tempmatrix = [];
            $gdi_mode_id = 0;
            $max_split_count = 1;
            $lines;
            // var_dump($datamatrix);die;
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
                // var_dump($singles);die;
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
                        // var_dump($rows);
                        foreach ($rows as $k=>$row) {
                            if ($k==0) {
                                $rows[$k]["first_split"] = true;
                            }
                            $rows[$k]["gdi_mode"] = $gdi_mode_id;
                            // $rows[$k]["is_split"] = true;
                        }
                        if ($max_split_count<count($rows)) {
                            $max_split_count = count($rows);
                        }
                        if ($accurate_split) {
                            $lines[] = count($rows);
                            // $rows["rowcount"] = count($rows);
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
                        // $rows["rowcount"] = count($rows);
                    }
                }

            }
            $datamatrix = $tempmatrix;
            // var_dump($datamatrix);die;
            //splitting matrix if needed otherwise put in one
            $splitmatrix = [];
            // var_dump(($split_period && isset($matching['[::WCX_Split_Periode::]'])));die;
            if ($split_period && isset($matching['[::WCX_Split_Periode::]'])) {
                // $filtertag = $matching['[::WCX_Split_Periode::]'];
                // var_dump($filtertag);die;
                foreach ($datamatrix as $row) {
                    $key = null;
                    $tag = $row[$matching['[::WCX_Split_Periode::]']];
                    if ($tag==NULL) {
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
                        $response = Helper::curl2($host."/api/v2/selection-definition-nodes/".$node, "GET", null, $header);
                        if ($response[0]==200) {
                            $temp = json_decode($response[1]);
                            if (isset($matching[$marker."func"])) {
                                $key = $this->applyFunction($matching["[::WCX_Split_Periode::]func"],$temp->value,$matching,$row);
                            } else {
                                $key = $temp->value;
                            }
                        } else {
                            $key = "";
                        }
                    } else {
                        $key = "";
                    }
                    // var_dump($key);die;
                    if (!isset($splitmatrix[$key])) {
                        $splitmatrix[$key] = [];   
                    }
                    $splitmatrix[$key][] = $row;
                }
                // var_dump($splitmatrix);die;
            } else {
                $splitmatrix["nosplit"] = $datamatrix;
            }
            // var_dump($splitmatrix);die;
            foreach ($splitmatrix as $period=>$datamatrix) {
                // Find the positions of repeat start and end
                $splits_marker = [];
                if ($gdi_mode && !$accurate_split) {
                    $positions = array();
                    $startPosition = 0;
                    while (($startPosition = strpos($templatefile, "[:splitstart:]", $startPosition)) !== false) {
                        $endPosition = strpos($templatefile, "[:splitend:]", $startPosition);
                        if ($endPosition !== false) {
                            $positions[] = array($startPosition, $endPosition);
                            $startPosition = $endPosition + strlen("[:splitend:]");
                        } else {
                            break; // No matching end marker found, exit loop
                        }
                    }
                    // Repeat each part
                    $newString = $templatefile;
                    foreach ($positions as $position) {
                        $startMarker = $position[0];
                        $endMarker = $position[1];

                        // Extract the substring between markers (excluding markers)
                        $substring = substr($templatefile, $startMarker + strlen("[:splitstart:]"), $endMarker - $startMarker - strlen("[:splitstart:]"));
                        $regex = '/\[:((?!splitstart|splitend)[^:]+):\]/';
                        $matches = [];
                        if (preg_match_all($regex, $substring, $matches)) {
                            $splits_marker = array_merge($splits_marker,$matches[0]);
                        }
                        
                        // Repeat the substring
                        $repeatedSubstring = "";
                        // var_dump($max_split_count);die;

                        for ($i = 0; $i < $max_split_count; $i++) {
                            $repeatedSubstring .= $substring;
                        }

                        // Replace the original marker block with the repeated substring (excluding markers)
                        $newString = str_replace(substr($templatefile, $startMarker, $endMarker - $startMarker + strlen("[:splitend:]")), $repeatedSubstring, $newString);
                    }    
                } else {
                    $newString = $templatefile;
                }
                $positions = array();
                $startPosition = 0;
                while (($startPosition = strpos($newString, "[:repeatstart:]", $startPosition)) !== false) {
                    $endPosition = strpos($newString, "[:repeatend:]", $startPosition);
                    if ($endPosition !== false) {
                        $positions[] = array($startPosition, $endPosition);
                        $startPosition = $endPosition + strlen("[:repeatend:]");
                    } else {
                        break; // No matching end marker found, exit loop
                    }
                }
                // Repeat each part
                // $newString = $templatefile;
                $repeatedMarkers = [];
                foreach ($positions as $position) {
                    $startMarker = $position[0];
                    $endMarker = $position[1];

                    // Extract the substring between markers (excluding markers)
                    $substring = substr($newString, $startMarker + strlen("[:repeatstart:]"), $endMarker - $startMarker - strlen("[:repeatstart:]"));
                    $regex = '/\[:((?!repeatstart|repeatend)[^:]+):\]/';
                    $matches = [];
                    if (preg_match_all($regex, $substring, $matches)) {
                        $repeatedMarkers = array_merge($repeatedMarkers,$matches[0]);
                    } 
                    // Determine the number of repetitions needed
                    $repetitions;
                    if ($gdi_mode) {
                        $repetitions = count($documentlist);
                    } else {
                        $repetitions = count($datamatrix);
                    }
                    
                    // Repeat the substring
                    $repeatedSubstring = "";
                    for ($i = 0; $i < $repetitions; $i++) {
                        $repeatedSubstring .= $substring;
                    }

                    // Replace the original marker block with the repeated substring (excluding markers)
                    $newString = str_replace(substr($newString, $startMarker, $endMarker - $startMarker + strlen("[:repeatend:]")), $repeatedSubstring, $newString);
                }               

                $mode = 1; //default
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
                // if ($found_filename[1]==NULL) {
				if (empty($found_filename)) {
                    $preset_filename = "";
                } else {
                    $preset_filename = $found_filename[1];
                    $newString = preg_replace($pattern, "", $newString);
                }
                // var_dump($datamatrix);die;
                if ($accurate_split) {
                    $positions = [];
                    while (($startPosition = strpos($newString, "[:splitstart:]", $startPosition)) !== false) {
                        $endPosition = strpos($newString, "[:splitend:]", $startPosition);
                        if ($endPosition !== false) {
                            $positions[] = array($startPosition, $endPosition);
                            $startPosition = $endPosition + strlen("[:splitend:]");
                        } else {
                            break; // No matching end marker found, exit loop
                        }
                    }
                    $total_offset = 0;
                    // var_dump($lines);die;
                    foreach ($positions as $booking_counter=>$position) {
                        if ($booking_counter!=0) {
                            $startMarker = strpos($newString, "[:splitstart:]", $startPosition);
                            $endMarker = strpos($newString, "[:splitend:]", $startPosition);
                            // var_dump($newStartPosition);
                            // var_dump($newEndPosition);
                            // $offset = strlen($repeatedSubstring)-strlen($substring)-strlen("[:splitstart:][:splitend:]")-$total_offset;
                            // $total_offset += $offset;
                        } else {
                            $startMarker = $position[0];
                            $endMarker = $position[1];
                            // $offset = 0;
                        }
                        

                        // Extract the substring between markers (excluding markers)
                        $substring = substr($newString, $startMarker + strlen("[:splitstart:]"), $endMarker - $startMarker - strlen("[:splitstart:]"));
                        $regex = '/\[:((?!splitstart|splitend)[^:]+):\]/';
                        $matches = [];
                        if (preg_match_all($regex, $substring, $matches)) {
                            $splits_marker = array_merge($splits_marker,$matches[0]);
                        }
                        // var_dump($splits_marker);die;
                        // Repeat the substring
                        $repeatedSubstring = "";
                        // var_dump($lines);die;
                        // var_dump($lines[$booking_counter]);

                        for ($i = 0; $i < $lines[$booking_counter]; $i++) {
                            $repeatedSubstring .= $substring;
                        }
                        // var_dump($repeatedSubstring);die;
                        $before = substr($newString, 0, $startMarker);
                        $after = substr($newString, $endMarker + strlen("[:splitend:]"));

                        $newString = $before . $repeatedSubstring . $after;
                        if ($booking_counter==2) {
                            // echo $before;die;
                        }
                        
                        // Replace the original marker block with the repeated substring (excluding markers)
                        // $newString = str_replace(substr($newString, $startMarker, $endMarker - $startMarker + strlen("[:splitend:]")), $repeatedSubstring, $newString);
                        $booking_counter++;
                        
                    }
                }
                //start loop here
                // echo $newString; die;
                
                // var_dump($repeatedMarkers);die;
                foreach($repeatedMarkers as $marker) {
                    // var_dump($marker);die;
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
					
	
					
                    $newString = preg_replace_callback($regex, function($match) use (&$datamatrix, &$matching, &$marker, &$counter, &$host, &$header, &$mode, &$gdi_mode, &$splits_marker, &$max_split_count, &$split_block, &$accurate_counter, &$accurate_split, &$lines, &$line_tracker){
                        if (!isset($matching[$marker])) { 
                            return "";   
                        }
                        $temp = $matching[$marker];
                        $prefix = "";
                        if ($mode==2) {
                            $prefix = explode("|",substr($marker,2,strlen($marker)-4))[0];
                        }
                        
                        // var_dump( ($accurate_split) );die;
                        if ($gdi_mode && !$accurate_split) {
                            // var_dump($marker);
                            if (array_search($marker,$splits_marker)===false) {
                                $counter++;
                                while (isset($datamatrix[$counter]["gdi_mode"]) && !isset($datamatrix[$counter]["first_split"])) {
                                    $counter++;
                                }
                            } else {
                                if ($split_block==-1) { //beginning
                                    $counter++;
                                    $split_block++;
                                } else {
                                    if (isset($datamatrix[$counter]["gdi_mode"])) { //is a split
                                        $split_block++;
                                        if ($counter-1>=0 && isset($datamatrix[$counter-1]["gdi_mode"]) && $datamatrix[$counter-1]["gdi_mode"]==$datamatrix[$counter]["gdi_mode"]) {
                                            // echo "last is from same split\n";
                                            $counter++;
                                        } else {
                                            if ($split_block%$max_split_count==0) {
                                                $split_block=0;
                                                $counter++;
                                            } else {
                                                return "";
                                            }
                                        }
                                    } else { //not a split
                                        // echo "not split\n";
                                        $split_block++;
                                        // if ($split_block)
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
                        // var_dump($datamatrix);die;
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

                        if (isset($datamatrix[$index][$temp])) {
                            $tag = $datamatrix[$index][$temp];
                            // var_dump($tag);
                            if ($tag->type=="singleLineStrings") {
                                if (isset($matching[$marker."func"])) {
                                    return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[$index]);
                                } else {
                                    return $prefix.$tag->value;
                                }
                            } else if ($tag->type=="numbers") {
                                if (isset($matching[$marker."func"])) {
                                    return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[$index]);
                                } else {
                                    return $prefix.strval(floatval($tag->value)/10000);
                                }
							} else if ($tag->type=="counters") {
                                if (isset($matching[$marker."func"])) {
                                    return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[$index]);
                                } else {
                                    return $prefix.strval(floatval($tag->value)/10000);
                                }		   
                            } else if ($tag->type=="dates") {
                                if (isset($matching[$marker."func"])) {
                                    return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[$index]);
                                } else {
                                    $dateTime = new \DateTime($tag->value);
									$german_time= new \DateTimeZone('Europe/Berlin');
									$dateTime->setTimezone($german_time);					  
                                    $date = $dateTime->format("d.m.Y");
                                    return $prefix.$date;
                                }
                            } else if ($tag->type=="selections") {
                                if (isset($matching[$marker."func"])) {
                                    return $prefix.$this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[$index]);
                                } else {
                                    return $prefix.$tag->value;
                                }
                            } else {
                                return "";
                            }
                        } else {
                            // $counter++;
                            // echo 12314;die;
                            if (isset($matching[$marker."group"]) && $matching[$marker."group"]=="1") {
                                if (isset($matching[$marker."func"])) {
                                    return $prefix.$this->applyFunction($matching[$marker."func"],$temp, $matching, $datamatrix[$index]);
                                } else {
                                    return $prefix.$temp;
                                } 
                                return $prefix.$temp;
                            } else {
                                return "";
                            }
                        }
                    },$newString);
                }
                $regex = '/\[:((?!repeatstart|repeatend)[^:]+):\]/';
                $newString = preg_replace_callback($regex, function($match) use (&$datamatrix, &$matching, &$host, &$header, &$mode){
                    // var_dump($match);die;
                    if (!isset($matching[$match[0]])) return "";
                    $temp = $matching[$match[0]];
                    $marker = $match[0];
                    // var_dump($marker);
                    if (isset($datamatrix[0][$temp])) {
                        $tag = $datamatrix[0][$temp];
                        if ($tag->type=="singleLineStrings") {
                            if (isset($matching[$marker."func"])) {
                                return $this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[0]);
                            } else {
                                return $tag->value;
                            }
                        } else if ($tag->type=="numbers") {
                            if (isset($matching[$marker."func"])) {
                                return $this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[0]);
                            } else {
                                return strval(floatval($tag->value)/10000);
                            }
                        } else if ($tag->type=="dates") {
                            if (isset($matching[$marker."func"])) {
                                return $this->applyFunction($matching[$marker."func"],$tag->value, $matching, $datamatrix[0]);
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
								$response = Helper::curl2($host."/api/v2/selection-definition-nodes/".$node, "GET", null, $header);
								if ($response[0]==200) {
									$temp = json_decode($response[1]);
									if (isset($matching[$marker."func"])) {
										return $this->applyFunction($matching[$marker."func"],$temp->value,$matching,$datamatrix[0]);
									} else {
										return $temp->value;
									}
								} else {
									return "";
								}
							} else {
								$value = isset($tag->value) ? $tag->value : "";
								if (isset($matching[$marker."func"])) {
									return $this->applyFunction($matching[$marker."func"], $value, $matching, $datamatrix[0]);
								} else {
									return $value;
								}
							}
                        } else {
                            return "";
                        }
                    } else {
                        if ($matching[$marker."group"]=="1") {
                            if (isset($matching[$marker."func"])) {
                                return $this->applyFunction($matching[$marker."func"],$temp,$matching,$datamatrix[0]);
                            } else {
                                return $temp;
                            } 
                        } else {
                            return "";
                        } 
                    }
                },$newString);
                // preg_match_all('/\s*$/',$newString,$testmatch);
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

                if (isset($_GET["test"])) { //bookmark
                    // echo getcwd();
                    // echo $period."\n";
                    echo $newString;
                    // echo "\n";
                    // echo "<br>";
                    // var_dump($count($splitmatrix));
                    die;
                    // continue;
                }
                sleep(2);

                if ($export=="amagno") {
                    $ch = curl_init();
                    $vaultid = $_POST["vaultid"];
                    $filename = $this->generateRandomString(8)."_".date("dmYHis")."_".$templatefile_name;
					if (isset($_POST["profil"])) {
						$filename = $this->generateRandomString(8)."_".$_POST["profil"]."_".date("dmYHis")."_".$templatefile_name;
					}
                    file_put_contents($filename,$newString);
                    if ($excel) {
                        $reader = IOFactory::createReader('Csv');
                        $reader->setDelimiter('|');
                        $reader->setEnclosure('');
                        $reader->setSheetIndex(0);
                        $spreadsheet = $reader->load($filename);
                        $writer = new Xlsx($spreadsheet);
                        unlink($filename);
                        $filename = substr($filename,0,-4).".xlsx";
                        $writer->save($filename);
                    }
                    
                    curl_setopt($ch, CURLOPT_URL, $host."/api/v2/vaults/".$vaultid."/checked-out-documents");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_HEADER, true);
                    if ($preset_filename=="") {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, '{
                            "metadata": {
                            "createDate": "'.date("Y-m-d\\TH:i:s.000\\Z").'",
                            "changeDate": "'.date("Y-m-d\\TH:i:s.000\\Z").'",
                            "name": "'.substr($filename,9).'",
                            "size": '.filesize($filename).'
                            },
                            "generateNonExistingNameIfNameExists": true
                        }');
                    } else {
                        curl_setopt($ch, CURLOPT_POSTFIELDS, '{
                            "metadata": {
                            "createDate": "'.date("Y-m-d\\TH:i:s.000\\Z").'",
                            "changeDate": "'.date("Y-m-d\\TH:i:s.000\\Z").'",
                            "name": "'.$preset_filename.'",
                            "size": '.filesize($filename).'
                            },
                            "generateNonExistingNameIfNameExists": true
                        }');
                    }
                    
                    $header = array();
                    $header[] = "Content-Type: text/json";
                    $header[] = "Accept: application/json";
                    $header[] = "Authorization: Bearer ".$atoken;
                    curl_setopt($ch, CURLOPT_HTTPHEADER,$header);
                    $response = curl_exec($ch);
                    $httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
                    Helper::log($httpcode);
                    Helper::log($response);
                    $new_document_id;
                    if ($httpcode==201 || $httpcode==200) {
                        $temp1 = explode("documents/",$response);
                        $match = [];
                        preg_match("/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/",$response,$match);
                        $new_document_id = $match[0];
                    } else {
                        Helper::log("fibu export, create file on amagno:".$httpcode.$response);
                    }
                    curl_close($ch);
        
        
                    //upload file
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $host."/api/v2/documents/".$new_document_id."/file");
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    $args = array();
                    if ($preset_filename=="") {
                        $args['file'] = curl_file_create($filename, "multipart/form-data",substr($filename,9));
                    } else {
                        $args['file'] = curl_file_create($filename, "multipart/form-data",$preset_filename);
                    }
                    
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $args);
                    $headr = array();
                    $headr[] = "Authorization: Bearer ".$atoken;
                    $headr[] = "Accept: ";
                    $headr[] = "Content-Type: multipart/form-data";
                    $headr[] = "Amagno-File-Create-Date: ".date("Y-m-d\\TH:i:s.000\\Z");
                    $headr[] = "Amagno-File-Change-Date: ".date("Y-m-d\\TH:i:s.000\\Z");
                    curl_setopt($ch, CURLOPT_HTTPHEADER,$headr);
                    curl_setopt($ch, CURLINFO_HEADER_OUT, true);
                    $response = curl_exec($ch);

                    $httpcode = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
                    Helper::log($httpcode);
                    Helper::log($response);
                    curl_close($ch);
                    sleep(2);
                    unlink($filename);
                } else if ($system=="onprem" && $export=="local") {
                    if (!file_exists($_POST["folder"])) {
                        Helper::log("folder doesnt exist");
                        echo 0;
                        die;
                    }
                    if (isset($_POST["folder"])) {
                        
                        $filename = "";
                        if (substr($_POST["folder"], -1) === "/") {
                            $filename = $_POST["folder"].date("dmYHis")."_".$templatefile_name;
                        } else {
                            $filename = $_POST["folder"]."/".date("dmYHis")."_".$templatefile_name;
                        }
                        file_put_contents($filename,$newString);
                        if ($excel) {
                            $reader = IOFactory::createReader('Csv');
							$reader->setDelimiter('|');
							$reader->setEnclosure('');
							$reader->setSheetIndex(0);
							$spreadsheet = $reader->load($filename);
                            $writer = new Xlsx($spreadsheet);
                            unlink($filename);
                            $filename = substr($filename,0,-4).".xlsx";
                            $writer->save($filename);
                        }
                    }
                } else if ($export=="ftp") {
                    $ftp_server = $_POST["ftp_server"];
                    $ftp_username = $_POST["ftp_user"];
                    $ftp_password = $_POST["ftp_password"];
                    $ftp_folder = $_POST["ftp_folder"];
                    if (substr($_POST["folder"], -1) === "/") {
                    } else {
                        $ftp_folder .= "/";
                    }
                    $filename = date("dmYHis")."_".$templatefile_name;
                    file_put_contents($filename,$newString);
                    if ($excel) {
                        $reader = IOFactory::createReader('Csv');
                        $reader->setDelimiter('|');
                        $reader->setEnclosure('');
                        $reader->setSheetIndex(0);
                        $spreadsheet = $reader->load($filename);
                        $writer = new Xlsx($spreadsheet);
                        unlink($filename);
                        $filename = substr($filename,0,-4).".xlsx";
                        $writer->save($filename);
                    }
                    $conn_id = ftp_connect($ftp_server);
                    if ($conn_id) {
                        $login_result = ftp_login($conn_id, $ftp_username, $ftp_password);
                        if ($login_result) {
                            $upload_result = ftp_put($conn_id, $folder.$filename, $filename, FTP_ASCII);
                            if ($upload_result) {
                            } else {
                                Helper::log("Failed to upload file.");
                            }
                        } else {
                            Helper::log("Failed to login to FTP server.");
                        }
                        ftp_close($conn_id);
                    } else {
                        Helper::log("Failed to connect to FTP server.");
                    }
                    unlink($filename);
                } else if ($export=="sql") {
                    try {
                            
                        $server = $_POST["dbhost"];
                        $database = $_POST["dbname"];
                        $username = $_POST["dbuser"];
                        $password = $_POST["dbpassword"];
                        // $conn = new PDO("odbc:Driver={ODBC Driver 17 for SQL Server};server=".$server.";trusted_connetion=Yes;database=".$database, $username, $password);
                        $conn = new PDO("dblib:host=$server;dbname=$database", $username, $password);

                        // $pdo = new \PDO("dblib:host=$server;dbname=$database;charset=utf8", $username, $password);
                        // set the PDO error mode to exception
                        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    } catch (PDOException $e) {
                        // echo "Connection failed: " . $e->getMessage();
                        echo 0;
                        die;
                    }
                    $statements = explode(';', $newString);
                    foreach ($statements as $statement) {
                        $statement = trim($statement);
                        $statement = str_replace(",,",",0,",$statement);
                        $statement = str_replace(",,",",0,",$statement);
                        $statement = str_replace(",)",",0)",$statement);                       
                        // echo $statement;die;
                        if (!empty($statement)) {
                            try {
                                $conn->exec($statement);
                            } catch (\PDOException $e) {
                                // echo "error Executed: $statement\n";
                                echo 0;
                                die;
                            }
                        }
                        // die;
                    }
                } else {
                    Helper::log("dont know where to export.");
                    echo 0;
                    die;
                }
                // die;
            //loop end
            }

            if (isset($matching["Stempel"])) {
                $header = array();
                $header[] = "Content-Type: text/json";
                $header[] = "Accept: application/json";
                $header[] = "Authorization: Bearer ".$atoken;
                foreach ($documentlist as $document) {
					
					$response = Helper::stampWithRetry($host, $atoken, $document->id, $_POST["stampid"]);
                    // $response = Helper::curl2($host."/api/v2/documents/".$document->id."/stamp", "PUT", "{'stampId':'".$matching["Stempel"]."'}", $header);
                }
            }
            if (isset($_POST["stampid"])) {
                $header = array();
                $header[] = "Content-Type: text/json";
                $header[] = "Accept: application/json";
                $header[] = "Authorization: Bearer ".$atoken;
                foreach ($documentlist as $document) {
					Helper::log("stamping ".$document->id);
					$response = Helper::stampWithRetry($host, $atoken, $document->id, $_POST["stampid"]);
                    // $response = Helper::curl2($host."/api/v2/documents/".$document->id."/stamp", "PUT", "{'stampId':'".$_POST["stampid"]."'}", $header);
					Helper::log(json_encode($response));
                }
            }
            if (isset($_GET["test"])) {
                echo 0;
                die;
            }
            //Helper::success($userid,$urlpath[2]);
            echo 1;
            die;
           

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
                            break;
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
                            // var_dump($function_arr);die;
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
                                } else {
                                }
                                continue 2;
                            case "ENDSWITH":
                                if (substr($content,-strlen($op[1]))===$op[1]) {
                                    return $function_arr[$i+1];
                                } else {
                                }
                                continue 2;
                            case "L":
                                if (floatval($content)<floatval($op[1])) {
                                    return $function_arr[$i+1];
                                } else {
                                }
                                continue 2;
                            case "LE":
                                if (floatval($content)<=floatval($op[1])) {
                                    return $function_arr[$i+1];
                                } else {
                                }
                                continue 2;
                            case "G":
                                if (floatval($content)>floatval($op[1])) {
                                    return $function_arr[$i+1];
                                } else {
                                }
                                continue 2;
                            case "GE":
                                if (floatval($content)>=floatval($op[1])) {
                                    return $function_arr[$i+1];
                                } else {
                                }
                                continue 2;
                            case "E":  
                                if ($content==$op[1]) {
                                    return $function_arr[$i+1];
                                } else {
                                } 
                                continue 2;
                        }
                    }
                    return $function_arr[count($function_arr)-1];                
                case "CALCULATE":
                    // echo $content;die;
                    $forced = false;
                    if (isset($function_arr[1]) && $function_arr[1]=="FORCED") {
                        $forced = true;
                    }
					if (isset($function_arr[2]) && $function_arr[2]=="FORMAT") {
                        $format = true;
                    }
                    $temp_matches = [];
                    preg_match_all('/\[([^\[\]]+)\]/', $content, $temp_matches);
                    $calculation = [];
                    // var_dump($temp_matches);die;
                    foreach($temp_matches[0] as $operand) {
                        
                        if (preg_match('/\[\:\:([^:\[\]]+)\:\:\]/',$operand)) {
                            if (isset($data[$matching[$operand]])) {
                                $temptag = $data[$matching[$operand]];
                                if (isset($matching[$operand."func"])) {
                                    $calculation[] = '"'.$this->applyFunction($matching[$operand."func"],$temptag->value,$matching,$datamatrix[0]).'"';
                                } else {
                                    if ($temptag->type=="numbers") {
                                        $calculation[] = floatval($temptag->value)/10000;
                                    } else {
                                        if (is_numeric($temptag->value)) {
                                            $calculation[] = floatval($temptag->value);
                                        } else {
                                            $calculation[] = '"'.addslashes($temptag->value).'"';
                                        }
                                        
                                    }
                                }
                            } else if ($forced) {
                                $calculation[] = '""';
                            } else {
                                // var_dump($operand);die;
                                return "";
                            }
                        } else if (preg_match('/^\[:\w+:\]$/',$operand,$foo)) {
                            $calculation[] = '"'.addslashes(substr($foo[0],2,strlen($foo[0])-4)).'"';
			            } else if (preg_match('/^\[:\p{L}.+:\]$/',$operand,$foo)) {
                            $calculation[] = '"'.addslashes(substr($foo[0],2,strlen($foo[0])-4)).'"';
                        } else if (is_numeric(substr($operand,1,strlen($operand)-2))) {
                            $calculation[] = floatval(substr($operand,1,strlen($operand)-2));
                        } else if ($operand=="[_DOCTYPE]") {
                            $doctypeid = $data["doctype_tagdefinitionid"]->value;
                            // var_dump($doctypeid);die;
                            // die;
                            $calculation[] = "'".$data["doctype_tagdefinitionid"]->value."'";
                        } else if ($operand=="[DIV]") {
                            $calculation[] = "/";
                        } else if ($operand=="[MUL]") {
                            $calculation[] = "*";
                        } else if ($operand=="[PLU]") {
                            $calculation[] = "+";
                        } else if ($operand=="[MIN]") {
                            $calculation[] = "-";
                        } else if ($operand=="[LP]") {
                            $calculation[] = "{";
                        } else if ($operand=="[RP]") {
                            $calculation[] = "}";
                        } else if ($operand=="[E]") {
                            $calculation[] = "==";
                        } else if ($operand=="[NE]") {
                            $calculation[] = "!=";
                        } else if ($operand=="[OR]") {
                            $calculation[] = "||";
                        } else if ($operand=="[AND]") {
                            $calculation[] = "&&";
                        } else if ($operand=="[NOT]") {
                            $calculation[] = "!";
                        } else if ($operand=="[LE]") {
                            $calculation[] = "<=";
                        } else if ($operand=="[GE]") {
                            $calculation[] = ">=";
                        } else if ($operand=="[L]") {
                            $calculation[] = "<";
                        } else if ($operand=="[G]") {
                            $calculation[] = ">";
                        } else if ($operand=="[IF]") {
                            $calculation[] = "if";
                        } else if ($operand=="[LB]") {
                            $calculation[] = "(";
                        } else if ($operand=="[RB]") {
                            $calculation[] = ")";
                        } else if ($operand=="[ELSE]") {
                            $calculation[] = "else";
                        } else if ($operand=="[RET]") {
                            $calculation[] = "return";
                        } else if ($operand=="[EMPTYSTRING]") {
                            $calculation[] = '""';
                        } else if ($operand=="[EMPTY]") {
                            $calculation[] = '';
                        } else if ($operand=="[ENDC]") {
                            $calculation[] = ';';
                        } else if ($operand=="[CONCAT]") {
                            $calculation[] = '.';
                        } else if ($operand=="[ISEMPTY]") {
                            $calculation[] = 'empty';
                        } else if ($operand=="[QUOTE]") {
                            $calculation[] = '"';
                        } else if ($operand=="[NULL]") {
                            $calculation[] = 'NULL';
                        } else if (preg_match('/^\[:[^:\'\"]+?:\]$/',$operand,$foo)) {
                            $calculation[] = '"'.addslashes(substr($foo[0],2,strlen($foo[0])-4)).'"';
                        } else {
                            $calculation[] = $operand;
                        }                        
                    }
                    $to_calculate = implode(" ",$calculation);
                    // if (isset($_GET["test"])) {
                        // echo $to_calculate;
                        // return $to_calculate;
                        // die;
                    // }
Helper::log($to_calculate);
                    $result = eval($to_calculate);


                    // echo $result;die;
                    // var_dump((isset($format) && $format));die;
					if ($forced && isset($function_arr[2]) && $function_arr[2]=="TEXT") {
						return $result;
					}
					if (isset($format) && $format) {
						if (isset($function_arr[3]) && $function_arr[3]=="DATE") {
							$dateTime = new \DateTime($result);
							$german_time= new \DateTimeZone('Europe/Berlin');
							$dateTime->setTimezone($german_time);							
                            $date = $dateTime->format($function_arr[4]);
                            // echo $date;die;
                            return $date;
						} else if (isset($function_arr[3]) && $function_arr[3]=="NUMBER") {
                            if ($result=="") {
                                return "";
                            } else {
                                // echo 999;die;
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
                    // if (isset($function_arr[1]) && $function_arr[1]=="NUMBER") {
                    //     return number_format($result,2,",","");
                    // }
                    if (is_numeric($result)) {
                        return number_format($result,2,",","");
                    } else {
                        return $result;
                    }
                case "SQL":
		            $value = $content;
                    $searchfor = $function_arr[1];
                    include("sql.php");
                    return $result_sql;
                case "DOCTYPE":
                    $doctype = $data["doctype_tagdefinitionid"];
                    preg_match_all('/\[([^\[\]]+)\]/', $content, $list);
                    foreach ($list[1] as $entry) {
                        $exploded = explode(":",$entry);
                        if ($doctype->value==$exploded[0]) {
                            return $exploded[1];
                        }
                    }
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
                            } else {
                                return $content;
                            }
                        } else {
                            return $content;
                        }
                    } else {
                        return $content;
                    }
                case "COUNTER":
                    if ($this->counter === NULL) {
                        $this->counter = intval($content) - 1;
                    }
                    $this->counter += 1;
                    return $this->counter;
                default:
                    return $content;
                    
            }
            return "apply";
        }
        private function generateRandomString($length = 10) {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
            $charactersLength = strlen($characters);
            $randomString = '';
            for ($i = 0; $i < $length; $i++) {
                $randomString .= $characters[random_int(0, $charactersLength - 1)];
            }
            return $randomString;
        }
    }
?>
