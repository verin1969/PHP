<?php
//
// Скрипт управления платформой IPTV
// Version 1.0.08
//
date_default_timezone_set('Europe/Moscow');
// Коды ответов
define("C_OK_HEADER", "HTTP/1.1 200 OK");      
define("C_ERR_HEADER", "HTTP/1.1 406 ERROR"); 
// Пакеты программ
$packages[498]  = 'Расширенный';
$packages[499]  = 'Наш футбол';
$packages[500]  = 'Amedia Premium';
$packages[501]  = 'Взрослый';
$packages[504]  = 'B2B';
$packages[2771] = 'B2B';
// Базовые пакеты, которые нельзя удалять
$base_packs = array(498, 504, 2771);
// Коды комманд
define("C_SUBS_CREATE",     1);  // Создание абонента
define("C_SUBS_CH_TYPE",    2);  // Изменить тип абонента
define("C_SUBS_CH_NAME",    3);  // Изменить имя абонента
define("C_SUBS_DELETE",     4);  // Удаление абонента
define("C_SUBS_CH_TERM",    5);  // Изменить количество доступных терминалов
define("C_SUBS_CH_STATE_D", 6);  // Заблокировать абонента
define("C_SUBS_CH_STATE_E", 7);  // Разблокировать абонета
define("C_SUBS_CH_ADR",     8);  // Изменить адресс
define("C_SUBS_CH_SEGM",    9);  // Изменить сегмент
define("C_SUBS_ADD_PKG",    10); // Добавить пакет программ
define("C_SUBS_DEL_PKG",    11); // Удалить пакет программ
define("C_SUBS_CH_PSWD",    12); // Изменить пароль
//Переменные
$log_file_name = "./iptv_".strftime ("%d%m%Y").".log"; 
//Список разрешенных адресов
$allowed_ips  = "10.15.5.15;10.199.32.27;10.15.5.70";
//Список адресов серверов, для которых необходимо делать перекодировку в UTF8
$breez_ip     = "10.15.5.70";
//Сервер, откуда пришел запрос
if (isset($_SERVER["REMOTE_ADDR"])) {
    $current_ip = $_SERVER["REMOTE_ADDR"];
} else {
    $current_ip = '0.0.0.0';
}
$xml_header = '<?xml version="1.0" encoding="windows-1251"?>';
//IPTV
$_uri = 'https://91.143.36.163';
$_auth_uri = $_uri.'/jsonrpc/';
$_username = 'billing_cifra1'; $_password = 'scX%IP!A';
$_jsonsql_uri = $_uri.'/jsonsql/';
$_iptvportal_header = null;
$res = null;    
// Запись в журнал
function save_to_log ($logstr) {
    global $log_file_name, $current_ip;
    $str = '';
    if (is_array($logstr)) {
        foreach ($logstr as $item => $description) {
            $str .= "[" . $item . "] = [" . $description . "] ";
        }
    } else {
      $str = $logstr; 
    }
    $fh = @fopen($log_file_name, "a");
    $curtime = strftime ("%d.%m.%Y %H:%M:%S ");
    fwrite($fh, $current_ip." ".$curtime.$str."\n");
    fclose($fh);
}
// Создаем ответ
function response( $ret_code, $ret_text)
{
    global $xml_header;
    $str = '';
    if (is_array($ret_text)) {
        foreach ($ret_text as $item => $description) {
            $str .= "[" . $item . "] = [" . $description . "] ";
        }
    } else {
      $str = $ret_text; 
    }
    $out_data = $xml_header."<response><result>$ret_code</result><comment>$str</comment></response>";
    if ($ret_code == 0) {
        $header_data = C_OK_HEADER;
    } else {
        $header_data = C_ERR_HEADER;
    }
    // Выводим ответ
    header($header_data);  
    header("Content-Type: text/xml; charset=windows-1251");
    save_to_log ($out_data);
    echo $out_data;
}
// Послать сообщение на платформу IPTV
function send ($url, $data, $extra_headers=null) {
    $ch = curl_init ();
    curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT,  120);
    curl_setopt ($ch, CURLOPT_RETURNTRANSFER,  true);
    curl_setopt ($ch, CURLOPT_TIMEOUT,         10);
    curl_setopt ($ch, CURLOPT_URL,             $url);
    curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER,  false);
    curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST,  false);
    curl_setopt ($ch, CURLOPT_POST,            true);
    curl_setopt ($ch, CURLOPT_POSTFIELDS,      $data);
    if (isset ($extra_headers)) {
        curl_setopt ($ch, CURLOPT_HTTPHEADER,  $extra_headers);
    }
    $content = curl_exec ($ch);
    $http_code = curl_getinfo ($ch, CURLINFO_HTTP_CODE);

    if ($content === false) {
        return null;
    }
    if ($http_code != 200) {
        return null;
    }
    curl_close ($ch);
    return $content;
}
// Вызов RPC
function jsonrpc_call ($url, $method, $params, $extra_headers=null) {
    static $req_id = 1;

    $req = array (
        "jsonrpc" => '2.0',
        "id"      => $req_id++,
        "method"  => $method,
        "params"  => $params
    );
    $req = json_encode ($req);
    $res = send ($url, $req, $extra_headers=$extra_headers);
    $res = json_decode ($res, true);
    if (!isset ($res)) {
        return null;
    } else if (!array_key_exists ('result', $res) || !isset ($res ['result'])) {
        return null;
    } else {
        return $res ['result'];
    }
    return $res;
}
//Вызов SQL
function jsonsql_call ($cmd, $params) {
    global $_jsonsql_uri, $_iptvportal_header;
    return jsonrpc_call ($_jsonsql_uri, $cmd, $params, $extra_headers=$_iptvportal_header);
}
// Авторизация сессии управления
function authorize_user ($auth_uri, $username, $password) {
    global $_iptvportal_header;
    $res = jsonrpc_call ($auth_uri, $cmd="authorize_user", $params=array (
        'username' => $username,
        'password' => $password
    ));
    if (isset ($res) && array_key_exists ('session_id', $res)) {
        $_iptvportal_header = array ('Iptvportal-Authorization: ' . 'sessionid=' . $res ['session_id']);
    }
    return $res;
}
// Изменение количества текминалов абонента
function change_abonent_state ($p_account, $p_is_disabled) {
    $res = jsonsql_call ("update", array (
        "table" => "subscriber",
        "set" => array (
        "disabled" => $p_is_disabled
         ),
        "where" => array ("eq" => array ("username", $p_account)),
        "returning" => "id"
    ));
    return $res;
}
// Добавление пакета программ
function add_package ($p_account, $p_package) {
    global $packages;
    //Добавляем пакет
    if (isset($packages[$p_package])) {
       $res = jsonsql_call ("insert", array (
           "into" => "subscriber_package",
           "columns" => array ("subscriber_id", "package_id", "enabled"),
           "select" => array (
               "data" => array (array ("s" => "id"), array ("p" => "id"), true),
               "from" => array (array (
                   "table" => "subscriber", "as" => "s"
               ), array (
                   "table" => "package", "as" => "p"
               )),
               "where" => array (
                   "and" => array (array (
                       "eq" => array (array ("s" => "username"), $p_account)
                   ), array (
                       "in" => array (array ("p" => "name"), $packages[$p_package])
                   ))
               )
           ),
           "returning" => "package_id"
        ));
    } else {
       $res = null;    
    }
    return $res;
}
// Удаление пакета программ
function del_package ($p_account, $p_package) {
    global $packages, $base_packs;
    if ((isset($packages[$p_package])) && (array_search($p_package, $base_packs) === FALSE)) {
       //Удаляем пакет, если он не базовый 
       $res = jsonsql_call ("delete", array (
           "from" => "subscriber_package",
           "where" => array ("and" => array (
               array ("in" => array ("subscriber_id", array (
                   "select" => array (
                       "data" => "id",
                       "from" => "subscriber",
                       "where" => array ("eq" => array ("username", $p_account))
                   )
               ))), array ("in" => array ("package_id", array (
                  "select" => array (
                       "data" => "id",
                       "from" => "package",
                       "where" => array ("in" => array ("name", $packages[$p_package]))
                   )
               )))
           )),
        "returning" => "package_id"
        ));
    } else {
       $res = null; 
    }
    return $res;
}
// Добавление абонента 
function add_abonent ($p_account, $p_password, $p_name, $p_branch, $p_state, $p_type, $p_maxterm,  $p_init_package) {
    $is_disabled = false;
    $v_init_package = $p_init_package;
    $type = 'BILL';
    if ($p_state == 'DISABLED') {
        $is_disabled = true;  
    }
    if ($p_type == 1) {
        $type = 'INT';
    }
    if ($p_branch == 'Breezz') {
        $v_init_package = 504;    
    }
    // создаем абонента
    $res = jsonsql_call ("insert", array (
        "into" => "subscriber",
        "columns" => array ("username", "password", "surname", "billing", "max_terminal", "disabled", "type"),
        "values" => array (
            "username"      => $p_account,
            "password"      => $p_password,
            "surname"       => $p_name,
            "billing"       => $p_branch,
            "max_terminal"  => $p_maxterm,
            "disabled"      => $is_disabled,
            "type"          => $type
        ),
        "returning" => "id"
    ));
    if ($res) {
    // добавляем базовый пакет
        $res = add_package($p_account, $v_init_package);
    } else {
     // Если не удалось создать абонента, то пробуем его разблокирвать
        $res =  change_abonent_state ($p_account, false);
    }
    return $res;
}
// Изменение количества текминалов абонента
function change_abonent_maxterm ($p_account, $p_maxterm) {
    $res = jsonsql_call ("update", array (
        "table" => "subscriber",
        "set" => array (
            "max_terminal" => $p_maxterm
        ),
        "where" => array ("eq" => array ("username", $p_account)),
        "returning" => "id"
    ));
    return $res;
}
// Изменение пароля абонента
function change_abonent_passwd ($p_account, $p_password) {
    $res = jsonsql_call ("update", array (
        "table" => "subscriber",
        "set" => array (
            "password" => $p_password
        ),
        "where" => array ("eq" => array ("username", $p_account)),
        "returning" => "id"
    ));
    return $res;
}
// Изменение типа абонента
function change_abonent_type ($p_account, $p_type) {
    $type = 'BILL';
    if ($p_type == 1) {
    $type = 'INT';
    }
    $res = jsonsql_call ("update", array (
        "table" => "subscriber",
        "set" => array (
            "type" => $type
        ),
        "where" => array ("eq" => array ("username", $p_account)),
        "returning" => "id"
    ));
    return $res;
}
// Изменение имени абонента
function change_abonent_name ($p_account, $p_name) {
    $res = jsonsql_call ("update", array (
        "table" => "subscriber",
        "set" => array (
            "surname" => $p_name
        ),
        "where" => array ("eq" => array ("username", $p_account)),
        "returning" => "id"
    ));
    return $res;
}
// Изменение адреса абоента
function change_abonent_address ($p_account, $p_address) {
    $res = jsonsql_call ("update", array (
        "table" => "subscriber",
        "set" => array (
            "address" => $p_address
        ),
        "where" => array ("eq" => array ("username", $p_account)),
        "returning" => "id"
    ));
    return $res;
}
// Изменение сегмента абоента
function change_abonent_segment ($p_account, $p_segment) {
    $res = jsonsql_call ("update", array (
        "table" => "subscriber",
        "set" => array (
            "business" => $p_segment
        ),
        "where" => array ("eq" => array ("username", $p_account)),
        "returning" => "id"
    ));
    return $res;
}
// Удаление абонента )
function delete_abonent ($p_account) { 
    // удаляем абонента
    /*$res = jsonsql_call ("delete", array (
        "from" => "subscriber",
        "where" => array ("in" => array ("id", array (
            "select" => array (
                "data" => "id",
                "from" => "subscriber",
                "where" => array ("eq" => array ("username", $p_account))
            )
        ))),
        "returning" => "id"
    ));
    // Если не удалось удалить абонента, то блокируем его
    if (!$res) {*/
        $res = change_abonent_state ($p_account, true);
    //}
    return $res;
}
// Проверка разрешенных IP для доступа
if (array_search($current_ip, explode(";",$allowed_ips)) === FALSE) {
	response(1, 'Wrong request IP');
    exit;
}

// Выполняем заявку
if ($_POST) {
    save_to_log('START');
    $user = authorize_user($_auth_uri, $_username, $_password);
    if (isset($_POST['reqtype']) && isset($_POST['account']) ) {
        // Для М2000 перекодируем, для Бриза оставляем как есть.
        if (isset($_POST['name']) && isset($_POST['branch'])) {
            if ($breez_ip != $current_ip) {
                $v_name   = iconv('windows-1251', 'utf-8', $_POST['name']);
                $_POST['name']   = $v_name;
                $v_branch = iconv('windows-1251', 'utf-8', $_POST['branch']);
                $_POST['branch'] = $v_branch;
            } else {
                $v_name   = $_POST['name'];
                $v_branch = $_POST['branch'];
            }
        }
        if (isset($_POST['param'])) {
            if ($breez_ip != $current_ip) {
                $v_param   = iconv('windows-1251', 'utf-8', $_POST['param']);
                $_POST['param']   = $v_param;
            } else {
                $v_param   = $_POST['param'];
            }
        }
        save_to_log($_POST);
        switch ($_POST['reqtype']) {
            case C_SUBS_CREATE     : $res = add_abonent($_POST['account'], $_POST['password'], $v_name, $v_branch, $_POST['state'], $_POST['type'], $_POST['maxterm'], $_POST['packages']);
                break;
            case C_SUBS_CH_TYPE    : $res = change_abonent_type($_POST['account'], $_POST['type']);
                break;
            case C_SUBS_CH_NAME    : $res = change_abonent_name($_POST['account'], $v_name);
                break;
            case C_SUBS_DELETE     : $res = delete_abonent($_POST['account']);
                break;
            case C_SUBS_CH_TERM    : $res = change_abonent_maxterm($_POST['account'], $_POST['maxterm']);
                break;
            case C_SUBS_CH_STATE_D : $res = change_abonent_state($_POST['account'], true);
                break;
            case C_SUBS_CH_STATE_E : $res = change_abonent_state($_POST['account'], false);
                break;
            case C_SUBS_CH_ADR     : $res = change_abonent_address($_POST['account'], $v_param);
                break;
            case C_SUBS_CH_SEGM    : $res = change_abonent_segment($_POST['account'], $v_param);
                break;
            case C_SUBS_ADD_PKG    : $res = add_package($_POST['account'], $v_param);
                break;
            case C_SUBS_DEL_PKG    : $res = del_package($_POST['account'], $v_param);
                break;
            case C_SUBS_CH_PSWD    : $res = change_abonent_passwd($_POST['account'], $v_param);
                break;
            default : response(1, "Unknown request type");
                break;
        }
    } else {
        response(1, 'Unknown request format');
    }
    if (isset($res)) {
        response(0, $res);
    } else {
        response(1, 'ERROR');
    }
} else {
    response(1, 'No POST message received');
}
?>
