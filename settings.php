<!-- UIkit CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.15.10/dist/css/uikit.min.css" />
<!-- UIkit JS -->
<script src="https://cdn.jsdelivr.net/npm/uikit@3.15.10/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3.15.10/dist/js/uikit-icons.min.js"></script>
<script>
    let atoken = "";
    let profiles = {};
    let currentProfile = "";
    <?php if (defined("_JEXEC")):?>
        <?php 
            $db = Joomla\CMS\Factory::getDBO();
            $db->setQuery("SELECT * FROM #__apianbindungen_user WHERE userid=".$db->quote($user->id));
            $api_user = $db->loadObject();
            // var_dump($api_user);
            if ($api_user->customurl_active==1) {
                echo 'let aurl = "'.$api_user->customurl.'";';
            } else {
                echo 'let aurl = "https://amagno.me";';
            }
        ?>
    <?php else:?>
        let aurl = "https://amagno.me";
    <?php endif;?>
    
    let taglist = [];
    <?php if (!defined('_JEXEC') && file_exists("matching.json")):?>
    profiles = JSON.parse('<?=file_get_contents("matching.json")?>');
    let matching = JSON.parse('<?=file_get_contents("matching.json")?>');
    <?php endif;?>
    <?php if (defined("_JEXEC")):?>
        <?php
        $db->setQuery("SELECT * FROM #__fibu_export where userid=".$db->quote($user->id));
        $fibu = $db->loadObject();
        if ($fibu!=NULL) {
            if (time()>=strtotime($fibu->token_end)) {
                $decrypted = explode(":",openssl_decrypt($fibu->amagno,"AES-256-CBC","oRWyJKv5xyXFnw0fiJ4cW8fR9mIEgYy4jpZa1Mi7"));
                $header = array();
                $header[] = "Content-Type: text/json";
                $header[] = "Accept: application/json";
                $data = '{"userName": "'.$decrypted[0].'","password": "'.$decrypted[1].'"}';
                // echo $data;
                $curl = curl_init();
                curl_setopt_array($curl, [
                    CURLOPT_URL => "https://amagno.me/api/v2/token",
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => "",
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLINFO_HEADER_OUT => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_POST => 1,
                    CURLOPT_HTTPHEADER => $header,
                    CURLOPT_POSTFIELDS => $data
                ]);
                $response = curl_exec($curl);
                $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                if ($httpcode==200) {
                    $atoken = json_decode($response);
                    echo 'atoken = "'.$atoken.'";';
                    // date("Y-m-d H:i:s")
                    $db->setQuery("UPDATE #__fibu_export SET token=".$db->quote($atoken).",token_end=".$db->quote(date("Y-m-d H:i:s",time()+1500))." WHERE userid=".$db->quote($user->id));
                    $db->execute();
                }
                curl_close($curl);
            } else {
                echo 'atoken = "'.$fibu->token.'";';
            }
        }
        $matchingfiles = array_diff(scandir("api/components/com_apianbindungen/fibuExport/matchings"),[".",".."]);
        // var_dump($matchingfiles);
        foreach($matchingfiles as $file) {
            if (substr($file,0,strlen((string)$user->id))===(string)$user->id) {
                echo 'profiles = JSON.parse(\''.file_get_contents("api/components/com_apianbindungen/fibuExport/matchings/".$file).'\');';
                echo 'let matching = JSON.parse(\''.file_get_contents("api/components/com_apianbindungen/fibuExport/matchings/".$file).'\');';
                break;
            }
        }
        ?>
        
        document.addEventListener("DOMContentLoaded", (event) => {
            let vaultselect_fibu = document.getElementById("vaultselect_fibu");
            let req = new XMLHttpRequest();
	        req.addEventListener("load", (event2)=>{
                if (event2.target.status==200) {
                    let vaultlist = JSON.parse(event2.target.responseText);
                    let option = document.createElement("option");
                    option.setAttribute("value","0");
                    option.textContent = "Bitte Ablage auswählen";
                    vaultselect_fibu.appendChild(option);
                    vaultlist.forEach((vault)=> {
                        let option = document.createElement("option");
                        option.setAttribute("value",vault.id);
                        option.textContent = vault.name;
                        vaultselect_fibu.appendChild(option);
                    });
                }
            });
            req.open("GET", aurl+"/api/v2/vaults");
            req.setRequestHeader('Authorization', 'Bearer '+atoken);
            req.send();
            setInterval(() => {
                relog();
            }, 1500000);
            let profileselect = document.getElementById("profileselect");
            profileselect.addEventListener("change", (event3)=>{
                if (event3.target.value=="0") {
                    // currentProfile = "";
                    matching =  [];
                } else {
                    // currentProfile = event3.target.value;
                    matching = profiles[event3.target.value];
                }
                // let newEvent = new Event("change");
                // document.getElementById("systemselect").dispatchEvent(newEvent);
				let trigger = new Event("change");
                if (document.getElementById("systemselect").value=="onprem") {
                    document.getElementById("inputfile").dispatchEvent(trigger);
                    console.log("trigger inputfile");
                } else {
                    document.getElementById("systemselect").dispatchEvent(trigger);
                    // console.log("trigger systemselect");
                }
            })
            for (profile in profiles) {
                let tempoption = document.createElement("option");
                tempoption.value = profile;
                tempoption.textContent = profile;
                profileselect.appendChild(tempoption);
            }
        });
        function relog() {

        }
        
    <?php else:?>
        function login() {
        let auser = document.getElementById("auser").value;
        let apw = document.getElementById("apw").value;
        aurl = document.getElementById("aurl").value;
        if (auser=="") {
            alert("Bitte Benutzername eingeben");
            return;
        }
        if (apw=="") {
            alert("Bitte Passwort eingeben");
            return;
        }
        if (aurl=="") {
            aurl = "https://amagno.me";
        }
		let req = new XMLHttpRequest();
		req.addEventListener("load", (event)=>{
			atoken = JSON.parse(event.target.responseText);
            let vaultselect_fibu = document.getElementById("vaultselect_fibu");
            let req = new XMLHttpRequest();
	        req.addEventListener("load", (event2)=>{
                if (event2.target.status==200) {
                    document.getElementById("amagnologindiv").classList.add("uk-hidden");
                    document.getElementById("settingsdiv").classList.remove("uk-hidden");
                    let vaultlist = JSON.parse(event2.target.responseText);
                    let option = document.createElement("option");
                    option.setAttribute("value","0");
                    option.textContent = "Bitte Ablage auswählen";
                    vaultselect_fibu.appendChild(option);
                    vaultlist.forEach((vault)=> {
                        let option = document.createElement("option");
                        option.setAttribute("value",vault.id);
                        option.textContent = vault.name;
                        vaultselect_fibu.appendChild(option);
                    });
                }
            });
            req.open("GET", aurl+"/api/v2/vaults");
            req.setRequestHeader('Authorization', 'Bearer '+atoken);
            req.send();
            setInterval(() => {
                relog();
            }, 1500000);
            
			let profileselect = document.getElementById("profileselect");
            profileselect.addEventListener("change", (event3)=>{
                if (event3.target.value=="0") {
                    // currentProfile = "";
                    matching =  [];
                } else {
                    // currentProfile = event3.target.value;
                    matching = profiles[event3.target.value];
                }
                // let newEvent = new Event("change");
                // document.getElementById("systemselect").dispatchEvent(newEvent);
				let trigger = new Event("change");
                if (document.getElementById("systemselect").value=="onprem") {
                    document.getElementById("inputfile").dispatchEvent(trigger);
                    console.log("trigger inputfile");
                } else {
                    document.getElementById("systemselect").dispatchEvent(trigger);
                    // console.log("trigger systemselect");
                }
            })
            for (profile in profiles) {
                let tempoption = document.createElement("option");
                tempoption.value = profile;
                tempoption.textContent = profile;
                profileselect.appendChild(tempoption);
            }
			
		});
		req.open("POST", aurl+"/api/v2/token");
		req.setRequestHeader('Content-type', 'text/json');
        if (document.getElementById("logintype").value=="windows") {
            req.send('{"authenticationType": "Windows", "userName": "'+auser+'","password": "'+apw+'"}');
        } else {
            req.send('{"userName": "'+auser+'","password": "'+apw+'"}');
        }
		
    }
    function relog() {
        let auser = document.getElementById("auser").value;
        let apw = document.getElementById("apw").value;
        aurl = document.getElementById("aurl").value;
        if (aurl=="") {
            aurl = "https://amagno.me";
        }
		let req = new XMLHttpRequest();
		req.addEventListener("load", (event)=>{
			atoken = JSON.parse(event.target.responseText);  
		});
		req.open("POST", aurl+"/api/v2/token");
		req.setRequestHeader('Content-type', 'text/json');
		if (document.getElementById("logintype").value=="windows") {
            req.send('{"authenticationType": "Windows", "userName": "'+auser+'","password": "'+apw+'"}');
        } else {
            req.send('{"userName": "'+auser+'","password": "'+apw+'"}');
        }
    }
    <?php endif;?>


    function createRows(rows) {
        let tbody = document.getElementById("tablebody");
        tbody.innerHTML="";
        let rowcount = 0;
		rows = [...new Set(rows)];

        rows.forEach((match)=>{
            
            let tr = document.createElement("tr");
            let td1 = document.createElement("td");
            td1.classList.add("savenames");
            td1.textContent = match;
            td1.id = "name"+rowcount;
            tr.appendChild(td1);
            let td2 = document.createElement("td");
            let select1 = document.createElement("select");
            select1.classList.add("savevalues");
            select1.classList.add("uk-select");
            select1.setAttribute("id","group"+rowcount);
            select1.addEventListener("change",(event)=>{
                updateTags(event);
            });
            let foo = document.createElement("option");
            foo.textContent = "Bitte Merkmalgruppe auswählen";
            foo.value = "0";
            
            let foo2 = document.createElement("option");
            foo2.textContent = "Fixwert";
            foo2.value = "1";
            if (match!="[::Stempel::]") {
                select1.appendChild(foo);
                select1.appendChild(foo2);
                for (group in taglist) {
                    let option = document.createElement("option");
                    option.value = group;
                    option.textContent = taglist[group][0];
                    select1.appendChild(option);
                }
            }
            td2.appendChild(select1);
            let selectfunction = document.createElement("select");
            selectfunction.classList.add("savevalues");
            selectfunction.classList.add("uk-select");
            selectfunction.classList.add("uk-margin-top");
            selectfunction.setAttribute("id","function"+rowcount);
            let tempoption1 = document.createElement("option");
            tempoption1.textContent="keine Zusatzfunktion";
            tempoption1.id = "0";
            
            let tempoption2 = document.createElement("option");
            tempoption2.textContent="Zusatzfunktion";
            tempoption2.value="1";
            if (match!="[::Stempel::]") {
                selectfunction.appendChild(tempoption1);
                selectfunction.appendChild(tempoption2);
            }
            td2.appendChild(selectfunction);
            tr.appendChild(td2);
            let td3 = document.createElement("td");
            let select2 = document.createElement("select");
            // console.log(match);
            if (match=="[::Stempel::]") {
                // console.log(matching);
                let vaultid = document.getElementById("vaultselect_fibu").value;
                let req2 = new XMLHttpRequest();
                let temp_row = rowcount;
                req2.addEventListener("load",(event2)=>{
                    select2.setAttribute("id","tag"+temp_row);
                    select2.classList.add("savevalues");
                    select2.classList.add("uk-select");
                    foo = document.createElement("option");
                    foo.textContent = "Bitte Stempel auswählen";
                    foo.value = "0";
                    select2.appendChild(foo);
                    if (event2.target.status==200) {
                        let stamplist = JSON.parse(event2.target.responseText);
                        stamplist.forEach((stamp)=>{
                            // console.log(stamp);
                            let stampoption = document.createElement("option");
                            stampoption.textContent = stamp.name;
                            stampoption.value = stamp.id;
                            select2.appendChild(stampoption);
                        });
                    }
                    if (typeof matching !== 'undefined') {
                        if (typeof matching.Stempel !== 'undefined') {
                            select2.value = matching.Stempel;
                        }
                    }
                });
                req2.open("GET", aurl+"/api/v2/vaults/"+vaultid+"/stamps");
                req2.setRequestHeader('Authorization', 'Bearer '+atoken);
                req2.send();
            } else {
                // let select2 = document.createElement("select");
                select2.setAttribute("id","tag"+rowcount);
                select2.classList.add("savevalues");
                select2.classList.add("uk-select");
                foo = document.createElement("option");
                foo.textContent = "Bitte Merkmal auswählen";
                foo.value = "0";
                select2.appendChild(foo);
            }
            let fixinput = document.createElement("input");
            fixinput.setAttribute("type","text");
            fixinput.classList.add("uk-input");
            fixinput.classList.add("savevalues");
            fixinput.classList.add("uk-hidden");
            fixinput.setAttribute("id","fix"+rowcount);
            fixinput.setAttribute("placeholder","Fixwert");
            let functioninput = document.createElement("input");
            functioninput.setAttribute("type","text");
            functioninput.classList.add("uk-input");
            functioninput.classList.add("uk-margin-top");
            functioninput.classList.add("savevalues");
            functioninput.setAttribute("id","functiondef"+rowcount);
            if (match!="[::Stempel::]") {
                functioninput.setAttribute("placeholder","Funktion");
            }
            td3.appendChild(select2);
            td3.appendChild(fixinput);
            td3.appendChild(functioninput);
            tr.appendChild(td3);
            tbody.appendChild(tr);

            let found = false;
            if (typeof matching != "undefined") {
                var isEmpty = true;
                for (var key in taglist) {
                    if (taglist.hasOwnProperty(key)) {
                        isEmpty = false;
                        break;
                    }
                }
                if (isEmpty) {
                    // select2.setAttribute("old",matching[match]);
                } else {
                    // console.log("not empty");
                    if (matching[match]===null||matching[match]===undefined) {
                    } else {
                        for (const group in taglist) {
                            for (const tag of taglist[group]) {
                                if (matching[match]==tag.id) {
                                    found = true;
                                    select1.value=group;
                                    // let trigger = new Event("change");
                                    // select1.dispatchEvent(trigger);
                                }
                            }
                        }
                    }
                }
                if (!found && typeof matching[match]!=="undefined") {
                    fixinput.value = matching[match];
                    select1.value="1";
                }
                if (typeof matching[match+"func"] !== "undefined") {
                    document.getElementById("function"+rowcount).value="1";
                    document.getElementById("functiondef"+rowcount).value = matching[match+"func"];
                }
            }
            let trigger = new Event("change");
            select1.dispatchEvent(trigger);
            rowcount++;
        });
    }
    function createList(event) {
        let file = event.target.files[0];           
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                let content = e.target.result;
                let regex = /\[:(?!(repeatstart|repeatend|splitstart|splitend):)[^:]+\:\]/g;
                let matches = content.match(regex);
                let regex2 = /\[\:\:[^:\[\]]+\:\:\]/g;
                let matches2 = content.match(regex2);
                console.log(matches);
                console.log(matches2);
                document.getElementById("tablebody").innerHTML="";
if (matches2==null) {createRows(matches);} else {createRows(matches2.concat(matches));}
                
                // createRows(matches);
            };
            reader.readAsText(file);
            // document.getElementById("savebutton").classList.remove("uk-hidden");
        }
    }
    function updateTags(event) {
        if (event.target.value=="0") {
            return
        }
        let row = event.target.id.substring(5);
        console.log("tag"+row);
        let tagselect = document.getElementById("tag"+row);
        if (event.target.value=="1") {
            // console.log("toggle");
            tagselect.classList.toggle("uk-hidden");
            document.getElementById("fix"+row).classList.toggle("uk-hidden");
        }   
        tagselect.innerHTML = "";
        let option = document.createElement("option");
        option.textContent = "Bitte Merkmal auswählen";
        option.value = "0";
        tagselect.appendChild(option);
        if (event.target.value=="1") {
            return;
        }
        let temparray = taglist[event.target.value];
        for (let i = 1; i < temparray.length; i++) {
            option = document.createElement("option");
            option.textContent = temparray[i].caption;
            option.value = temparray[i].id;
            tagselect.appendChild(option);
            if (typeof matching !== "undefined") {
                // console.log(event.target);
                // console.log(matching);
                let marker = document.getElementById("name"+row).textContent;
                if (typeof matching[marker] !== "undefined") {
                    if (matching[marker]==option.value) {
                        option.setAttribute("selected","");
                    }
                }
                
            }
        }
        
        
    }
    function getTags() {
        let vaultid = document.getElementById("vaultselect_fibu").value;
        // console.log(vaultid);return;
        let req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            if (event.target.status==200) {
                taglist = [];
                let temp = JSON.parse(event.target.responseText);
                let blocked_groups = ["Archivierungs-Einstellungen","Bearbeitungsinformationen","Stempel","Dateiinformationen","Metadaten","Magnetisierung","ZUGFeRD Position"];
                temp.forEach((taggroup)=>{
                    if (blocked_groups.includes(taggroup.name)) {

                    } else {
                        taglist[taggroup.id] = [taggroup.name];
                    }
                });
                let req2 = new XMLHttpRequest();
                req2.addEventListener("load",(event2)=>{
                    if (event2.target.status==200) {
                        let temp2 = JSON.parse(event2.target.responseText);
                        // console.log(temp2);
                        Object.entries(temp2).forEach(([type, value]) => {
                            // console.log(value);
                            value.forEach((tag)=>{
                                if (tag.sourceType=="UserDefined" || tag.sourceType=="Recognized" || tag.sourceType=="Default") {
                                    tag.type=type;
                                    if (taglist[tag.tagGroupDefinitionId]===undefined) {
                                        // console.log(tag);
                                    } else {
                                        taglist[tag.tagGroupDefinitionId].push(tag);
                                    }
                                } 
                            });
                        });
                    }
                });
                req2.open("GET", aurl+"/api/v2/vaults/"+vaultid+"/documents/tag-definitions");
                req2.setRequestHeader('Authorization', 'Bearer '+atoken);
                req2.send();
                let trigger = new Event("change");
                if (document.getElementById("systemselect").value=="onprem") {
                    document.getElementById("inputfile").dispatchEvent(trigger);
                    console.log("trigger inputfile");
                } else {
                    document.getElementById("systemselect").dispatchEvent(trigger);
                    // console.log("trigger systemselect");
                }
                
            }
        });
        req.open("GET", aurl+"/api/v2/vaults/"+vaultid+"/documents/tag-group-definitions");
        req.setRequestHeader('Authorization', 'Bearer '+atoken);
        req.send();
        // document.getElementById("inputfile").classList.remove("uk-hidden");
    }
    function saveMatching() {
        let container = document.getElementById("tablebody");
        let savevalues = Array.from(tablebody.getElementsByClassName("savevalues"));
        let data = [];
        savevalues.forEach((input)=>{
            data.push(input.id+"="+input.value);
        });
        let savenames = Array.from(tablebody.getElementsByClassName("savenames"));
        savenames.forEach((input)=>{
            data.push(input.id+"="+input.textContent);
        });
        let req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            // console.log(event.target.responseText);
            if (event.target.status==200) {
                responseobj = JSON.parse(event.target.responseText);
                alert(responseobj.message);
                if (responseobj.status=="newProfile") {
                    profiles[responseobj.profilename] = responseobj.profile;
                    let tempoption = document.createElement("option");
                    tempoption.value = responseobj.profilename;
                    tempoption.textContent = responseobj.profilename;
                    document.getElementById("profileselect").appendChild(tempoption);
                }
            }
        });
        let systemselect = document.getElementById("systemselect");
        data.push("system="+systemselect.value);
        if (systemselect.value=="onprem") {
            data.push("auser="+document.getElementById("auser").value);
            data.push("apw="+document.getElementById("apw").value);
        } else {
            data.push("user="+document.getElementById("wcx_secret").value);
        }
        data.push("aurl="+aurl);
        let profileselect = document.getElementById("profileselect");
        data.push("profileselect="+profileselect.value);
        let profilename = document.getElementById("newprofilename");
        data.push("profilename="+profilename.value)
        if (systemselect.value=="onprem") {
            req.open("POST", "save.php");
        } else {
            req.open("POST", "/api/fibu_saveMatching");
        }
        req.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        req.send(data.join("&"));

    }
    function selectSystem(event) {
        if (event.target.value=="0") {

        } else if (event.target.value=="onprem") {
            document.getElementById("inputfile").classList.remove("uk-hidden");
        } else {
            document.getElementById("inputfile").classList.add("uk-hidden");
            let tbody = document.getElementById("tablebody");
            tbody.innerHTML = "";
            let req = new XMLHttpRequest();
            req.addEventListener("load", (event)=>{
                if (event.target.status==200) {
                    let list = JSON.parse(event.target.responseText);
                    createRows(list);
                }
            });
            req.open("GET", "/api/fibu_get?sys="+event.target.value);
            req.send();
        }
    }
    function deleteProfile() {
        let data = [];
        data.push("profile="+document.getElementById("profileselect").value);
        data.push("user="+document.getElementById("wcx_secret").value);
        data.push("system="+document.getElementById("systemselect").value);
        let req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            if (event.target.status==200) {
                responseobj = JSON.parse(event.target.responseText);
                if (responseobj.status=="deletedProfile") {
                    alert(responseobj.message);
                    delete profiles[responseobj.profilename];
                    Array.from(document.getElementById("profileselect").selectedOptions).forEach((tempoption)=>{
                        tempoption.remove();
                    });
                    document.getElementById("profileselect").value = "0";
                    let trigger = new Event("change");
                    document.getElementById("profileselect").dispatchEvent(trigger);
                }
            }
        });
        req.open("POST", "/api/fibu_deleteProfile");
        req.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        req.send(data.join("&"));
    }
    document.addEventListener("DOMContentLoaded", (event) => {
        document.getElementById("inputfile").addEventListener("change", createList);
        document.getElementById("vaultselect_fibu").addEventListener("change",getTags);
        document.getElementById("systemselect").addEventListener("change",selectSystem);
    });
</script>
<div id="amagnologindiv">
    <?php if (defined('_JEXEC')):?>
        <?php 
            $db = Joomla\CMS\Factory::getDBO();
            $db->setQuery("SELECT * FROM #__fibu_export where userid=".$db->quote($user->id));
            $fibu = $db->loadResult();
        ?>
        <?php if ($fibu==NULL):?>
            <div>Registrierung mit Amagno Stempel fehlt</div>
        <?php else:?>
            <script>
                document.addEventListener("DOMContentLoaded", (event) => {
                    document.getElementById("amagnologindiv").classList.add("uk-hidden");
                    document.getElementById("settingsdiv").classList.remove("uk-hidden");
                    // atoken 
                });
            </script>
        <?php endif;?>
    <?php else:?>
    <div class="uk-width-1-2">
        <form>
            <fieldset class="uk-fieldset">
                <legend class="uk-form-label uk-text-bolder">Benutzer</legend>
                <div>
                    <div class="uk-inline uk-width-1-1">
                        <input class="uk-input" id="auser" placeholder="" aria-label="" value="">
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <div class="uk-width-1-2 uk-margin-top">
        <form>
            <fieldset class="uk-fieldset">
                <legend class="uk-form-label uk-text-bolder">Passwort</legend>
                <div>
                    <div class="uk-inline uk-width-1-1">
                        <input class="uk-input" id="apw" placeholder="" aria-label="" value="" type="password">
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <div class="uk-width-1-2 uk-margin-top">
        <form>
            <fieldset class="uk-fieldset">
                <legend class="uk-form-label uk-text-bolder">On Premise URL</legend>
                <div>
                    <div class="uk-inline uk-width-1-1">
                        <input class="uk-input" id="aurl" placeholder="" aria-label="" value="" type="text">
                    </div>
                </div>
            </fieldset>
        </form>
    </div>
    <div class="uk-width-1-2 uk-margin-top">
        <select id="logintype" class="uk-select">
            <option value="amagno">Amagno Login</option>
            <option value="windows">Windows Login</option>
        </select>
    </div>
    <div class="uk-margin-top"><a class="uk-button-primary uk-button-default uk-margin-top" onclick="login()">anmelden</a></div>
    <?php endif;?>
</div>
<div id="settingsdiv" class="uk-hidden">
    <div>
        <div>
            <legend class="uk-form-label uk-text-bolder">Ablage</legend>
            <select name="vaultselect_fibu" id="vaultselect_fibu" class="uk-input uk-width-1-2 uk-margin-bottom uk-select"></select>
        </div>
        <div>
            <legend class="uk-form-label uk-text-bolder">System</legend>
            <select id="systemselect" class="uk-margin-bottom uk-select uk-width-1-2">
            <?php if (defined('_JEXEC')):?>
                <option value="0">Bitte System auswählen</option>
                    <?php 
                        $directory = "api/components/com_apianbindungen/fibuExport/templates";
                        if (file_exists($directory)) {
                            $files = scandir($directory);
                        } else {
                            $files = [];
                        }
                    ?>
                    <?php foreach (array_diff($files,[".",".."]) as $file):?>
                        <option value="<?=explode(".",$file)[0]?>"><?=explode(".",$file)[0]?></option>            
                    <?php endforeach;?>
            <?php else:?>
                <option value="onprem">On Premise Einrichtung</option>
            <?php endif;?>
            </select>
        </div>
        <div>
            <input id="inputfile" type="file" class="uk-input uk-width-1-2 uk-margin-bottom <?=defined('_JEXEC')?"uk-hidden":""?>">
        </div>
        <div>
            <legend class="uk-form-label uk-text-bolder">Profil</legend>
            <div>
                <select name="profileselect" id="profileselect" class="uk-input uk-width-1-2 uk-margin-bottom uk-select">
                    <option value="0">Als neues Profil speichern</option>
                </select>
                <input id="newprofilename" name="newprofilename" placeholder="Neuer Profilname"class="uk-input uk-width-1-3 uk-margin-bottom">
            </div>
        </div>
        <div><a id="savebutton" class="uk-button uk-button-primary uk-margin-top" onclick='saveMatching()'>speichern</a>
        <a id="savebutton" class="uk-button uk-button-default uk-margin-top" onclick='deleteProfile()'>Profil löschen</a>
        </div>
    </div>
    
    <table class="uk-table uk-table-striped">
        <tbody id="tablebody">
        </tbody>
    </table>
</div>
