<!-- UIkit CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/uikit@3.15.10/dist/css/uikit.min.css" />
<!-- UIkit JS -->
<script src="https://cdn.jsdelivr.net/npm/uikit@3.15.10/dist/js/uikit.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/uikit@3.15.10/dist/js/uikit-icons.min.js"></script>
<style>
    body {
        background-color: #f5f7fb;
    }

    .settings-wrapper {
        max-width: 1200px;
        margin: 30px auto;
        padding: 0 18px 40px;
    }

    .settings-card {
        background: #ffffff;
        border-radius: 10px;
        padding: 24px;
        box-shadow: 0 10px 25px rgba(15, 18, 34, 0.08);
        margin-bottom: 24px;
    }

    .settings-grid {
        gap: 20px;
    }

    #settingsdiv {
        padding-top: 32px;
    }

    #settingsStatus {
        margin-top: 12px;
    }

    .button-row {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
    }

    .button-row .uk-button {
        min-width: 180px;
    }
</style>
<script>
    let atoken = "";
    let profiles = {};
    let currentProfile = "";
    const statusClasses = ["uk-alert-primary","uk-alert-success","uk-alert-danger","uk-alert-warning"];
    const SESSION_STORAGE_KEY = "amagnoSettingsSession";
    const SESSION_CREDENTIALS_KEY = "amagnoSettingsCredentials";
    const SESSION_DURATION_MS = 8 * 60 * 60 * 1000; // 8 hours
    const TOKEN_REFRESH_THRESHOLD_MS = 25 * 60 * 1000; // 25 minutes
    let relogIntervalId = null;
    let cachedCredentials = null;
    let relogInProgress = false;
    let pendingAuthCallbacks = [];

    function getStatusElement() {
        return document.getElementById("settingsStatus");
    }

    function showStatusMessage(message, level = "primary") {
        const element = getStatusElement();
        if (!element) {
            return;
        }
        statusClasses.forEach(cls => element.classList.remove(cls));
        element.textContent = message;
        element.classList.remove("uk-hidden");
        element.classList.add("uk-alert-" + level);
    }

    function clearStatusMessage() {
        const element = getStatusElement();
        if (!element) {
            return;
        }
        statusClasses.forEach(cls => element.classList.remove(cls));
        element.textContent = "";
        element.classList.add("uk-hidden");
    }

    function setActionButtonsDisabled(disabled) {
        ["savebutton","deletebutton"].forEach((id)=>{
            const button = document.getElementById(id);
            if (!button) {
                return;
            }
            button.disabled = disabled;
            button.classList.toggle("uk-disabled", disabled);
            if (disabled) {
                button.setAttribute("aria-busy","true");
            } else {
                button.removeAttribute("aria-busy");
            }
        });
    }

    function handleRequestError(actionLabel, enableButtons = false, detail = "") {
        if (enableButtons) {
            setActionButtonsDisabled(false);
        }
        let message = actionLabel + " fehlgeschlagen.";
        if (detail) {
            message += " " + detail;
        }
        message += " Bitte erneut versuchen.";
        showStatusMessage(message, "danger");
    }

    function persistSessionData(sessionData) {
        if (typeof localStorage === "undefined") {
            return;
        }
        try {
            localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify({
                ...sessionData,
                timestamp: Date.now()
            }));
        } catch (error) {
            console.warn("Session konnte nicht gespeichert werden.", error);
        }
    }

    function clearSessionData() {
        if (typeof localStorage === "undefined") {
            return;
        }
        localStorage.removeItem(SESSION_STORAGE_KEY);
    }

    function persistCredentials(credentials) {
        cachedCredentials = credentials;
        if (typeof sessionStorage === "undefined") {
            return;
        }
        try {
            sessionStorage.setItem(SESSION_CREDENTIALS_KEY, JSON.stringify(credentials));
        } catch (error) {
            console.warn("Anmeldedaten konnten nicht gespeichert werden.", error);
        }
    }

    function loadStoredCredentials() {
        if (cachedCredentials !== null) {
            return cachedCredentials;
        }
        if (typeof sessionStorage === "undefined") {
            return null;
        }
        const raw = sessionStorage.getItem(SESSION_CREDENTIALS_KEY);
        if (!raw) {
            return null;
        }
        try {
            cachedCredentials = JSON.parse(raw);
            return cachedCredentials;
        } catch (error) {
            sessionStorage.removeItem(SESSION_CREDENTIALS_KEY);
        }
        return null;
    }

    function getStoredCredentials() {
        return cachedCredentials !== null ? cachedCredentials : loadStoredCredentials();
    }

    function clearStoredCredentials() {
        cachedCredentials = null;
        if (typeof sessionStorage === "undefined") {
            return;
        }
        sessionStorage.removeItem(SESSION_CREDENTIALS_KEY);
    }

    function getCurrentBaseUrl(preferredUrl = "") {
        const urlField = document.getElementById("aurl");
        if (urlField && urlField.value !== "") {
            aurl = urlField.value;
            return urlField.value;
        }
        if (preferredUrl) {
            if (urlField) {
                urlField.value = preferredUrl;
            }
            aurl = preferredUrl;
            return preferredUrl;
        }
        if (typeof aurl === "string" && aurl !== "") {
            return aurl;
        }
        return "https://amagno.me";
    }

    function showLoginView() {
        const loginDiv = document.getElementById("amagnologindiv");
        const settingsDiv = document.getElementById("settingsdiv");
        if (loginDiv) {
            loginDiv.classList.remove("uk-hidden");
        }
        if (settingsDiv) {
            settingsDiv.classList.add("uk-hidden");
        }
    }

    function logout() {
        if (relogIntervalId) {
            clearInterval(relogIntervalId);
            relogIntervalId = null;
        }
        relogInProgress = false;
        pendingAuthCallbacks = [];
        atoken = "";
        clearSessionData();
        clearStoredCredentials();
        clearStatusMessage();
        const userField = document.getElementById("auser");
        const passField = document.getElementById("apw");
        if (userField) {
            userField.value = "";
        }
        if (passField) {
            passField.value = "";
        }
        showLoginView();
        if (typeof UIkit !== "undefined" && UIkit.notification) {
            UIkit.notification({message: "Du wurdest abgemeldet.", status: "primary"});
        }
    }

    function handleUnauthorizedRequest(retryCallback) {
        const credentials = getStoredCredentials();
        if (typeof retryCallback === "function") {
            pendingAuthCallbacks.push(retryCallback);
        }
        if (!credentials || !credentials.user || !credentials.password) {
            pendingAuthCallbacks = [];
            clearSessionData();
            showLoginView();
            showStatusMessage("Sitzung abgelaufen, bitte erneut anmelden.", "warning");
            return;
        }
        if (relogInProgress) {
            return;
        }
        showStatusMessage("Sitzung wird erneuert...", "primary");
        relog({
            credentials,
            onSuccess: () => {
                const callbacks = pendingAuthCallbacks.slice();
                pendingAuthCallbacks = [];
                showStatusMessage("Sitzung erneuert. Vorgang wird wiederholt.", "success");
                callbacks.forEach((cb)=>{
                    try {
                        cb();
                    } catch (error) {
                        console.error("Callback konnte nicht erneut ausgeführt werden.", error);
                    }
                });
            },
            onError: (message) => {
                pendingAuthCallbacks = [];
                clearSessionData();
                clearStoredCredentials();
                showLoginView();
                const detail = message ? " "+message : "";
                showStatusMessage("Sitzung konnte nicht erneuert werden."+detail, "danger");
            }
        });
    }

    function initializeSettingsView() {
        const loginDiv = document.getElementById("amagnologindiv");
        const settingsDiv = document.getElementById("settingsdiv");
        if (loginDiv) {
            loginDiv.classList.add("uk-hidden");
        }
        if (settingsDiv) {
            settingsDiv.classList.remove("uk-hidden");
        }
        populateProfileOptions();
        setupProfileSelect();
        loadVaults();
    }

    function loadVaults() {
        const vaultselect = document.getElementById("vaultselect_fibu");
        if (!vaultselect || !atoken) {
            return;
        }
        vaultselect.innerHTML = "";
        const placeholder = document.createElement("option");
        placeholder.setAttribute("value","0");
        placeholder.textContent = "Bitte Ablage auswählen";
        vaultselect.appendChild(placeholder);

        const req = new XMLHttpRequest();
        req.addEventListener("load", (event2)=>{
            if (event2.target.status==200) {
                const vaultlist = JSON.parse(event2.target.responseText);
                vaultlist.forEach((vault)=> {
                    const option = document.createElement("option");
                    option.setAttribute("value",vault.id);
                    option.textContent = vault.name;
                    vaultselect.appendChild(option);
                });
            } else if (event2.target.status==401) {
                handleUnauthorizedRequest(loadVaults);
            } else {
                handleRequestError("Ablagen laden", false, "Serverantwort "+event2.target.status);
            }
        });
        req.addEventListener("error", ()=>handleRequestError("Ablagen laden"));
        req.addEventListener("timeout", ()=>handleRequestError("Ablagen laden"));
        req.timeout = 15000;
        req.open("GET", aurl+"/api/v2/vaults");
        req.setRequestHeader('Authorization', 'Bearer '+atoken);
        req.send();
    }

    function setupProfileSelect() {
        const profileselect = document.getElementById("profileselect");
        if (!profileselect || profileselect.dataset.listenerAttached === "true") {
            return profileselect;
        }
        profileselect.dataset.listenerAttached = "true";
        profileselect.addEventListener("change", handleProfileSelectChange);
        return profileselect;
    }

    function handleProfileSelectChange(event3) {
        if (event3.target.value=="0") {
            matching = {};
            currentProfile = "";
        } else {
            matching = profiles[event3.target.value];
            currentProfile = event3.target.value;
        }
        const trigger = new Event("change");
        const systemSelect = document.getElementById("systemselect");
        if (!systemSelect) {
            return;
        }
        if (systemSelect.value=="onprem") {
            const inputfile = document.getElementById("inputfile");
            if (inputfile) {
                inputfile.dispatchEvent(trigger);
            }
        } else {
            systemSelect.dispatchEvent(trigger);
        }
    }

    function populateProfileOptions() {
        const profileselect = document.getElementById("profileselect");
        if (!profileselect) {
            return;
        }
        const previousSelection = currentProfile || profileselect.value || "0";
        profileselect.innerHTML = "";
        const defaultOption = document.createElement("option");
        defaultOption.value = "0";
        defaultOption.textContent = "Als neues Profil speichern";
        profileselect.appendChild(defaultOption);
        Object.keys(profiles || {}).forEach((profile)=>{
            const tempoption = document.createElement("option");
            tempoption.value = profile;
            tempoption.textContent = profile;
            profileselect.appendChild(tempoption);
        });
        if (previousSelection !== "0" && profiles[previousSelection]) {
            profileselect.value = previousSelection;
        } else {
            profileselect.value = "0";
        }
    }

    function canRelog() {
        const credentials = getStoredCredentials();
        return Boolean(credentials && credentials.user && credentials.password);
    }

    function startRelogInterval() {
        if (relogIntervalId) {
            clearInterval(relogIntervalId);
        }
        if (!canRelog()) {
            return;
        }
        relogIntervalId = setInterval(() => {
            relog();
        }, 1500000);
    }

    function restoreSessionFromStorage() {
        if (typeof localStorage === "undefined") {
            return;
        }
        const stored = localStorage.getItem(SESSION_STORAGE_KEY);
        if (!stored) {
            return;
        }
        let sessionData;
        try {
            sessionData = JSON.parse(stored);
        } catch (error) {
            clearSessionData();
            return;
        }
        if (!sessionData.token || (sessionData.timestamp + SESSION_DURATION_MS) < Date.now()) {
            clearSessionData();
            return;
        }
        const credentials = getStoredCredentials();
        if (sessionData.url) {
            aurl = sessionData.url;
            const urlField = document.getElementById("aurl");
            if (urlField) {
                urlField.value = sessionData.url;
            }
        }
        const userField = document.getElementById("auser");
        if (userField && sessionData.user) {
            userField.value = sessionData.user;
        }
        const loginTypeField = document.getElementById("logintype");
        if (loginTypeField && sessionData.loginType) {
            loginTypeField.value = sessionData.loginType;
        }
        const sessionIsStale = sessionData.timestamp
            ? (Date.now() - sessionData.timestamp) > TOKEN_REFRESH_THRESHOLD_MS
            : false;

        const finalizeRestore = () => {
            atoken = sessionData.token;
            initializeSettingsView();
            showStatusMessage("Vorherige Sitzung wiederhergestellt.", "primary");
            startRelogInterval();
        };

        if (!sessionIsStale) {
            finalizeRestore();
            return;
        }

        if (!credentials || !credentials.user || !credentials.password) {
            clearSessionData();
            showLoginView();
            return;
        }

        showStatusMessage("Sitzung wird erneuert...", "primary");
        relog({
            credentials,
            onSuccess: () => {
                sessionData.token = atoken;
                sessionData.timestamp = Date.now();
                persistSessionData(sessionData);
                initializeSettingsView();
                showStatusMessage("Sitzung wiederhergestellt.", "primary");
            },
            onError: () => {
                clearSessionData();
                clearStoredCredentials();
                showLoginView();
                showStatusMessage("Bitte erneut anmelden.", "warning");
            }
        });
    }

    function appendFormValue(target, key, value) {
        if (typeof key === "undefined" || key === null) {
            return;
        }
        const safeValue = value === undefined || value === null ? "" : value;
        target.push(encodeURIComponent(key)+"="+encodeURIComponent(safeValue));
    }

    function getUserSecretValue() {
        const field = document.getElementById("wcx_secret");
        return field ? field.value : "";
    }
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
    let matching = {};
    <?php if (!defined('_JEXEC') && file_exists("matching.json")):?>
    profiles = JSON.parse('<?=file_get_contents("matching.json")?>');
    matching = JSON.parse('<?=file_get_contents("matching.json")?>');
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
                echo 'matching = JSON.parse(\''.file_get_contents("api/components/com_apianbindungen/fibuExport/matchings/".$file).'\');';
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
        const auserField = document.getElementById("auser");
        const apwField = document.getElementById("apw");
        const urlField = document.getElementById("aurl");
        const loginTypeField = document.getElementById("logintype");
        const username = auserField ? auserField.value.trim() : "";
        const password = apwField ? apwField.value : "";
        if (username === "") {
            alert("Bitte Benutzername eingeben");
            return;
        }
        if (password === "") {
            alert("Bitte Passwort eingeben");
            return;
        }
        aurl = urlField && urlField.value !== "" ? urlField.value : "https://amagno.me";
        if (urlField && urlField.value === "") {
            urlField.value = aurl;
        }
        const loginType = loginTypeField ? loginTypeField.value : "amagno";
        const payload = loginType === "windows"
            ? JSON.stringify({"authenticationType":"Windows","userName":username,"password":password})
            : JSON.stringify({"userName":username,"password":password});
        const req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            if (event.target.status !== 200) {
                handleRequestError("Login", false, "Serverantwort "+event.target.status);
                return;
            }
            try {
                atoken = JSON.parse(event.target.responseText);
            } catch (error) {
                handleRequestError("Login", false, "Ungültige Serverantwort.");
                return;
            }
            persistSessionData({
                token: atoken,
                user: username,
                url: aurl,
                loginType: loginType
            });
            persistCredentials({
                user: username,
                password: password,
                loginType: loginType,
                url: aurl
            });
            initializeSettingsView();
            showStatusMessage("Anmeldung erfolgreich.", "success");
            startRelogInterval();
        });
        req.addEventListener("error", ()=>handleRequestError("Login"));
        req.addEventListener("timeout", ()=>handleRequestError("Login"));
        req.timeout = 15000;
        req.open("POST", aurl+"/api/v2/token");
        req.setRequestHeader('Content-type', 'text/json');
        req.send(payload);
    }
    function relog(options = {}) {
        const credentials = options.credentials || getStoredCredentials();
        if (relogInProgress) {
            return;
        }
        if (!credentials || !credentials.user || !credentials.password) {
            if (typeof options.onError === "function") {
                options.onError("Keine gespeicherten Zugangsdaten.");
            }
            return;
        }
        relogInProgress = true;
        const loginType = credentials.loginType || (document.getElementById("logintype") ? document.getElementById("logintype").value : "amagno");
        const targetUrl = getCurrentBaseUrl(credentials.url || "");
        const payload = loginType === "windows"
            ? JSON.stringify({"authenticationType":"Windows","userName":credentials.user,"password":credentials.password})
            : JSON.stringify({"userName":credentials.user,"password":credentials.password});
        const req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            relogInProgress = false;
            if (event.target.status !== 200) {
                if (typeof options.onError === "function") {
                    options.onError("Serverantwort "+event.target.status);
                }
                return;
            }
            let tokenResponse;
            try {
                tokenResponse = JSON.parse(event.target.responseText);
            } catch (error) {
                if (typeof options.onError === "function") {
                    options.onError("Ungültige Serverantwort.");
                }
                return;
            }
            atoken = tokenResponse;
            persistSessionData({
                token: atoken,
                user: credentials.user,
                url: targetUrl,
                loginType: loginType
            });
            persistCredentials({
                user: credentials.user,
                password: credentials.password,
                loginType: loginType,
                url: targetUrl
            });
            startRelogInterval();
            if (typeof options.onSuccess === "function") {
                options.onSuccess(tokenResponse);
            }
        });
        req.addEventListener("error", ()=>{
            relogInProgress = false;
            if (typeof options.onError === "function") {
                options.onError("Netzwerkfehler");
            }
        });
        req.addEventListener("timeout", ()=>{
            relogInProgress = false;
            if (typeof options.onError === "function") {
                options.onError("Timeout");
            }
        });
        req.timeout = 15000;
        req.open("POST", targetUrl+"/api/v2/token");
        req.setRequestHeader('Content-type', 'text/json');
        req.send(payload);
    }
    <?php endif;?>


    function setValueInputMode(row, useFixValue) {
        const tagselect = document.getElementById("tag"+row);
        const fixinput = document.getElementById("fix"+row);
        if (!tagselect || !fixinput) {
            return;
        }
        if (useFixValue) {
            tagselect.classList.add("uk-hidden");
            fixinput.classList.remove("uk-hidden");
        } else {
            tagselect.classList.remove("uk-hidden");
            fixinput.classList.add("uk-hidden");
        }
    }

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
            select1.addEventListener("change",updateTags);
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
                    } else if (event2.target.status==401) {
                        handleUnauthorizedRequest(()=>createRows(rows));
                        return;
                    } else {
                        handleRequestError("Stempel laden", false, "Serverantwort "+event2.target.status);
                        return;
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
            let td4 = document.createElement("td");
            let maxleninput = document.createElement("input");
            maxleninput.setAttribute("type","number");
            maxleninput.setAttribute("min","1");
            maxleninput.classList.add("uk-input");
            maxleninput.classList.add("savevalues");
            maxleninput.setAttribute("id","maxlen"+rowcount);
            maxleninput.setAttribute("placeholder","Maxlänge (optional)");
            td4.appendChild(maxleninput);
            tr.appendChild(td4);
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
                if (typeof matching[match+"maxlen"] !== "undefined") {
                    document.getElementById("maxlen"+rowcount).value = matching[match+"maxlen"];
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
                let matches = content.match(regex) || [];
                let regex2 = /\[\:\:[^:\[\]]+\:\:\]/g;
                let matches2 = content.match(regex2) || [];
                console.log(matches);
                console.log(matches2);
                document.getElementById("tablebody").innerHTML="";
                const combinedMatches = matches2.length ? matches2.concat(matches) : matches;
                if (!combinedMatches.length) {
                    showStatusMessage("Die Vorlage enthält keine Platzhalter.", "warning");
                    return;
                }
                createRows(combinedMatches);
                
                // createRows(matches);
            };
            reader.readAsText(file);
            // document.getElementById("savebutton").classList.remove("uk-hidden");
        }
    }
    function updateTags(event) {
        let row = event.target.id.substring(5);
        let tagselect = document.getElementById("tag"+row);
        if (!tagselect) {
            return;
        }
        const isFixValue = event.target.value==="1";
        setValueInputMode(row, isFixValue);
        tagselect.innerHTML = "";
        let option = document.createElement("option");
        option.textContent = "Bitte Merkmal auswählen";
        option.value = "0";
        tagselect.appendChild(option);
        if (event.target.value=="0" || isFixValue) {
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
        if (vaultid=="0") {
            showStatusMessage("Bitte wählen Sie zuerst eine Ablage aus.", "warning");
            return;
        }
        clearStatusMessage();
        let req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            if (event.target.status==200) {
                taglist = [];
                let temp = JSON.parse(event.target.responseText);
                let blocked_groups = ["Archivierungs-Einstellungen","Bearbeitungsinformationen","Stempel","Dateiinformationen","Metadaten","Magnetisierung","ZUGFeRD Position"];
                temp.forEach((taggroup)=>{
                    if (!blocked_groups.includes(taggroup.name)) {
                        taglist[taggroup.id] = [taggroup.name];
                    }
                });
                let req2 = new XMLHttpRequest();
                req2.addEventListener("load",(event2)=>{
                        if (event2.target.status==200) {
                            let temp2 = JSON.parse(event2.target.responseText);
                        Object.entries(temp2).forEach(([type, value]) => {
                            value.forEach((tag)=>{
                                if (tag.sourceType=="UserDefined" || tag.sourceType=="Recognized" || tag.sourceType=="Default") {
                                    tag.type=type;
                                    if (taglist[tag.tagGroupDefinitionId]!==undefined) {
                                        taglist[tag.tagGroupDefinitionId].push(tag);
                                    }
                                } 
                            });
                        });
                        showStatusMessage("Merkmale aktualisiert.", "success");
                            let trigger = new Event("change");
                            if (document.getElementById("systemselect").value=="onprem") {
                                document.getElementById("inputfile").dispatchEvent(trigger);
                            } else {
                                document.getElementById("systemselect").dispatchEvent(trigger);
                            }
                        } else {
                            if (event2.target.status==401) {
                                handleUnauthorizedRequest(getTags);
                            } else {
                                handleRequestError("Merkmale laden", false, "Serverantwort "+event2.target.status);
                            }
                        }
                    });
                req2.addEventListener("error",()=>handleRequestError("Merkmale laden"));
                req2.addEventListener("timeout",()=>handleRequestError("Merkmale laden"));
                req2.timeout = 15000;
                req2.open("GET", aurl+"/api/v2/vaults/"+vaultid+"/documents/tag-definitions");
                req2.setRequestHeader('Authorization', 'Bearer '+atoken);
                req2.send();
            } else {
                if (event.target.status==401) {
                    handleUnauthorizedRequest(getTags);
                } else {
                    handleRequestError("Merkmalgruppen laden", false, "Serverantwort "+event.target.status);
                }
            }
        });
        req.addEventListener("error",()=>handleRequestError("Merkmalgruppen laden"));
        req.addEventListener("timeout",()=>handleRequestError("Merkmalgruppen laden"));
        req.timeout = 15000;
        req.open("GET", aurl+"/api/v2/vaults/"+vaultid+"/documents/tag-group-definitions");
        req.setRequestHeader('Authorization', 'Bearer '+atoken);
        req.send();
    }
    function saveMatching() {
        const container = document.getElementById("tablebody");
        if (!container) {
            return;
        }
        const savevalues = Array.from(container.getElementsByClassName("savevalues"));
        const data = [];
        savevalues.forEach((input)=>{
            appendFormValue(data, input.id, input.value);
        });
        const savenames = Array.from(container.getElementsByClassName("savenames"));
        savenames.forEach((input)=>{
            appendFormValue(data, input.id, input.textContent);
        });
        const systemselect = document.getElementById("systemselect");
        appendFormValue(data, "system", systemselect.value);
        if (systemselect.value=="onprem") {
            appendFormValue(data, "auser", document.getElementById("auser").value);
            appendFormValue(data, "apw", document.getElementById("apw").value);
        } else {
            const wcxSecret = getUserSecretValue();
            if (!wcxSecret) {
                showStatusMessage("Es konnte kein Benutzer-Token ermittelt werden.", "danger");
                return;
            }
            appendFormValue(data, "user", wcxSecret);
        }
        appendFormValue(data, "aurl", aurl);
        const profileselect = document.getElementById("profileselect");
        appendFormValue(data, "profileselect", profileselect.value);
        const profilename = document.getElementById("newprofilename");
        appendFormValue(data, "profilename", profilename.value);

        clearStatusMessage();
        setActionButtonsDisabled(true);
        showStatusMessage("Speichern läuft...", "primary");

        const req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            setActionButtonsDisabled(false);
            if (event.target.status==200) {
                let responseobj;
                try {
                    responseobj = JSON.parse(event.target.responseText);
                } catch (error) {
                    handleRequestError("Speichern", false, "Ungültige Serverantwort.");
                    return;
                }
                showStatusMessage(responseobj.message, responseobj.status=="error" ? "danger" : "success");
                if (responseobj.status=="newProfile") {
                    profiles[responseobj.profilename] = responseobj.profile;
                    currentProfile = responseobj.profilename;
                    matching = responseobj.profile;
                    populateProfileOptions();
                }
            } else {
                handleRequestError("Speichern", false, "Serverantwort "+event.target.status);
            }
        });
        req.addEventListener("error", ()=>handleRequestError("Speichern", true));
        req.addEventListener("timeout", ()=>handleRequestError("Speichern", true));
        req.timeout = 20000;
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
            showStatusMessage("Bitte ein System auswählen.", "warning");
        } else if (event.target.value=="onprem") {
            document.getElementById("inputfile").classList.remove("uk-hidden");
            showStatusMessage("Bitte laden Sie Ihre Vorlage hoch.", "primary");
        } else {
            document.getElementById("inputfile").classList.add("uk-hidden");
            let tbody = document.getElementById("tablebody");
            tbody.innerHTML = "";
            clearStatusMessage();
            let req = new XMLHttpRequest();
            req.addEventListener("load", (event)=>{
                if (event.target.status==200) {
                    try {
                        let list = JSON.parse(event.target.responseText);
                        createRows(list);
                        showStatusMessage("Vorlage geladen.", "success");
                    } catch (error) {
                        handleRequestError("Vorlage laden", false, "Ungültige Serverantwort.");
                    }
                } else {
                    handleRequestError("Vorlage laden", false, "Serverantwort "+event.target.status);
                }
            });
            req.addEventListener("error", ()=>handleRequestError("Vorlage laden"));
            req.addEventListener("timeout", ()=>handleRequestError("Vorlage laden"));
            req.timeout = 15000;
            req.open("GET", "/api/fibu_get?sys="+event.target.value);
            req.send();
        }
    }
    function deleteProfile() {
        const profileselect = document.getElementById("profileselect");
        if (!profileselect || profileselect.value=="0") {
            showStatusMessage("Bitte wählen Sie zuerst ein Profil aus.", "warning");
            return;
        }
        const wcxSecret = getUserSecretValue();
        if (!wcxSecret) {
            showStatusMessage("Es konnte kein Benutzer-Token ermittelt werden.", "danger");
            return;
        }
        const data = [];
        appendFormValue(data, "profile", profileselect.value);
        appendFormValue(data, "user", wcxSecret);
        appendFormValue(data, "system", document.getElementById("systemselect").value);
        clearStatusMessage();
        setActionButtonsDisabled(true);
        showStatusMessage("Profil wird gelöscht...", "primary");
        const req = new XMLHttpRequest();
        req.addEventListener("load", (event)=>{
            setActionButtonsDisabled(false);
            if (event.target.status==200) {
                let responseobj;
                try {
                    responseobj = JSON.parse(event.target.responseText);
                } catch (error) {
                    handleRequestError("Profil löschen", false, "Ungültige Serverantwort.");
                    return;
                }
                if (responseobj.status=="deletedProfile") {
                    showStatusMessage(responseobj.message, "success");
                    delete profiles[responseobj.profilename];
                    profileselect.value = "0";
                    currentProfile = "";
                    populateProfileOptions();
                    let trigger = new Event("change");
                    profileselect.dispatchEvent(trigger);
                } else {
                    showStatusMessage(responseobj.message || "Profil konnte nicht gelöscht werden.", "danger");
                }
            } else {
                handleRequestError("Profil löschen", false, "Serverantwort "+event.target.status);
            }
        });
        req.addEventListener("error", ()=>handleRequestError("Profil löschen", true));
        req.addEventListener("timeout", ()=>handleRequestError("Profil löschen", true));
        req.timeout = 15000;
        req.open("POST", "/api/fibu_deleteProfile");
        req.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
        req.send(data.join("&"));
    }
    document.addEventListener("DOMContentLoaded", (event) => {
        const inputfile = document.getElementById("inputfile");
        if (inputfile) {
            inputfile.addEventListener("change", createList);
        }
        const vaultselect = document.getElementById("vaultselect_fibu");
        if (vaultselect) {
            vaultselect.addEventListener("change",getTags);
        }
        const systemselect = document.getElementById("systemselect");
        if (systemselect) {
            systemselect.addEventListener("change",selectSystem);
        }
        restoreSessionFromStorage();
    });
</script>
<div class="settings-wrapper uk-container uk-container-large">
<div id="amagnologindiv" class="settings-card">
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
    <div class="uk-grid-small uk-child-width-1-2@m settings-grid" uk-grid>
        <div>
            <legend class="uk-form-label uk-text-bolder">Benutzer</legend>
            <input class="uk-input uk-width-1-1" id="auser" placeholder="" aria-label="" value="">
        </div>
        <div>
            <legend class="uk-form-label uk-text-bolder">Passwort</legend>
            <input class="uk-input uk-width-1-1" id="apw" placeholder="" aria-label="" value="" type="password">
        </div>
        <div>
            <legend class="uk-form-label uk-text-bolder">On Premise URL</legend>
            <input class="uk-input uk-width-1-1" id="aurl" placeholder="" aria-label="" value="" type="text">
        </div>
        <div>
            <legend class="uk-form-label uk-text-bolder">Login-Typ</legend>
            <select id="logintype" class="uk-select uk-width-1-1">
                <option value="amagno">Amagno Login</option>
                <option value="windows">Windows Login</option>
            </select>
        </div>
    </div>
    <div class="uk-margin-top">
        <button class="uk-button uk-button-primary" type="button" onclick="login()">Anmelden</button>
    </div>
    <?php endif;?>
</div>
<div id="settingsdiv" class="settings-card uk-hidden">
    <div class="uk-flex uk-flex-between uk-flex-middle uk-margin-bottom">
        <h3 class="uk-margin-remove">Einstellungen</h3>
        <button class="uk-button uk-button-default" type="button" onclick="logout()">Abmelden</button>
    </div>
    <div class="uk-grid-small uk-child-width-1-2@m settings-grid" uk-grid>
        <div>
            <legend class="uk-form-label uk-text-bolder">Ablage</legend>
            <select name="vaultselect_fibu" id="vaultselect_fibu" class="uk-input uk-width-1-1 uk-select"></select>
        </div>
        <div>
            <legend class="uk-form-label uk-text-bolder">System</legend>
            <select id="systemselect" class="uk-select uk-width-1-1">
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
        <div class="<?=defined('_JEXEC')?"uk-hidden":""?>">
            <legend class="uk-form-label uk-text-bolder">Vorlage hochladen</legend>
            <input id="inputfile" type="file" class="uk-input uk-width-1-1">
        </div>
        <div>
            <legend class="uk-form-label uk-text-bolder">Profil</legend>
            <select name="profileselect" id="profileselect" class="uk-input uk-width-1-1 uk-select">
                <option value="0">Als neues Profil speichern</option>
            </select>
            <input id="newprofilename" name="newprofilename" placeholder="Neuer Profilname"class="uk-input uk-width-1-1 uk-margin-small-top">
        </div>
    </div>
    <div class="button-row uk-margin-top">
        <button id="savebutton" class="uk-button uk-button-primary" type="button" onclick='saveMatching()'>Speichern</button>
        <button id="deletebutton" class="uk-button uk-button-default" type="button" onclick='deleteProfile()'>Profil löschen</button>
    </div>
    <div id="settingsStatus" class="uk-alert uk-hidden" uk-alert></div>
    <div class="uk-overflow-auto uk-margin-top">
        <table class="uk-table uk-table-striped">
            <tbody id="tablebody">
            </tbody>
        </table>
    </div>
</div>
</div>
