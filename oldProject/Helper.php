<?php
    class Helper
    {
        public static function curl2($url, $method, $data = null, $header = null) {
            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_FOLLOWLOCATION => true,
                // CURLOPT_CUSTOMREQUEST => $method,
                CURLINFO_HEADER_OUT => true
            ]);
            if (strtoupper($method)=="POST") {
                curl_setopt($curl, CURLOPT_POST, 1);
            } else {
                curl_setopt($curl, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            }
            if ($header!=null) {
                curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
            }
            if ($data!=null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
            }
            $response = curl_exec($curl);
			if ($response === false) {
				Helper::log(curl_error($curl));
				Helper::log(curl_errno($curl));
			}

            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            return [$httpCode,$response];
        }


        public static function log($message) {
            // $input = Factory::getApplication()->input;
            // $ip = $input->server->get('REMOTE_ADDR', '', '');
            file_put_contents("debug.txt", date("d.m.Y H:i:s")."|$message\n",FILE_APPEND);
        }

        public static function stampCheck($parameter_list) {
            $errors = [];
            $action = isset($_POST["action"]) ? $_POST["action"] : "";
            $atoken = isset($_POST["atoken"]) ? $_POST["atoken"] : null;

            if ($atoken === null || $atoken === "") {
                if ($action === "start") {
                    echo "Parameter atoken fehlt.";
                    die;
                }

                if (isset($_POST["id"]) && $_POST["id"]==="") {
                    foreach ($parameter_list as $param) {
                        if (!isset($_POST[$param]) || $_POST[$param]==="") {
                            $errors[] = "Parameter ".$param." fehlt.";
                        }
                    }
                    if (count($errors)==0) {
                        echo "Stempel in Ordnung";
                    } else {
                        echo implode(" ",$errors);
                    }
                    die;
                }
            }
        }

        public static function issetParams($parameter_list) {
            foreach ($parameter_list as $key) {
                if (!isset($_POST[$key])) {
                    echo 0;
                    Helper::log("missing: ".$key);
                    die;
                }
                if ($_POST[$key]=="") {
                    echo 0;
                    Helper::log("missing: ".$key);
                    die;
                }
            }
        }
		
		public static function success($a,$b) {
			
		}
		
		// BOF: Ergaenzung SvK - Retry für SuccessStamp
        public static function stamp($host, $atoken, $documentId, $stampId)
{
			$header = array();
			$header[] = "Content-Type: application/json";
			$header[] = "Accept: application/json";
			$header[] = "Authorization: Bearer ".$atoken;

			$payload = json_encode(['stampId' => $stampId]);
			if ($payload === false) {
				Helper::log("stamp payload encoding failed for stampId ".$stampId);
				$payload = "{\"stampId\":\"".str_replace('"','\\"',(string)$stampId)."\"}";
			}

			return self::curl2(
				$host."/api/v2/documents/".$documentId."/stamp",
				"PUT",
				$payload,
				$header
			);
		}

		public static function stampWithRetry(
			$host,
			$atoken,
			$documentId,
			$stampId,
			$maxRetries = 3,
			$sleepSeconds = 2
		) {
			$attempt  = 0;
			$response = null;

			while ($attempt < $maxRetries) {
				$attempt++;

				$response = self::stamp($host, $atoken, $documentId, $stampId);
				$statusCode = (is_array($response) && isset($response[0])) ? $response[0] : null;

				self::log(
					"Helper::stampWithRetry attempt ".$attempt." for document ".$documentId.
					" (stampId=".$stampId.") status: ".($statusCode ?? "n/a")
				);

				if ($statusCode !== null && $statusCode >= 200 && $statusCode < 300) {
					return $response;
				}

				if ($attempt < $maxRetries) {
					sleep($sleepSeconds);
				}
			}

			self::log(
				"Helper::stampWithRetry FAILED for document ".$documentId.
				" (stampId=".$stampId.") after ".$maxRetries." attempts"
			);

			return $response;
		}
		// EOF: Ergaenzung SvK - Retry für SuccessStamp
    }
?>
