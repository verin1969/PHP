<?php

$_auth_uri = 'https://go.iptvportal.ru/api/jsonrpc/';
$_username = 'admin'; $_password = 'psw';
$_jsonsql_uri = 'https://192.168.0.250/api/jsonsql/';
$_iptvportal_header = null;

function send ($url, $data, $extra_headers=null) {
    $ch = curl_init ();
    curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT,  10);
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
    //echo "HTTP fetching '$url'...\n";
    $content = curl_exec ($ch);
    $http_code = curl_getinfo ($ch, CURLINFO_HTTP_CODE);
    if ($content === false) {
        $err_msg = "HTTP error: $http_code (" . curl_error($ch) . ')' . '\n';
        echo $err_msg;
        throw new Exception ($err_msg);
    }
    if ($http_code != 200) {
        $err_msg = "HTTP request failed ($http_code)\n";
        echo $err_msg;
        throw new Exception ($err_msg);
    }
    //echo "HTTP OK ($http_code)\n";
    curl_close ($ch);
    return $content;
}

function jsonrpc_call ($url, $method, $params, $extra_headers=null) {
    static $req_id = 1;

    $req = array (
        "jsonrpc" => '2.0',
        "id" => $req_id++,
        "method" => $method,
        "params" => $params
    );
    $req = json_encode ($req);
    $res = send ($url, $req, $extra_headers=$extra_headers);
    #echo $res;
    $res = json_decode ($res, true);
    if (!isset ($res)) {
        echo "error: not result\n";
        return null;
    } else if (!array_key_exists ('result', $res) || !isset ($res ['result'])) {
        print_r ($res ['error']);
        return null;
    } else {
        return $res ['result'];
    }
    return $res;
}

function jsonsql_call ($cmd, $params) {
    global $_jsonsql_uri, $_iptvportal_header;
    //echo 'iptvportal_header: '; print_r ($_iptvportal_header);
    return jsonrpc_call ($_jsonsql_uri, $cmd, $params, $extra_headers=$_iptvportal_header);
}

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

$user = authorize_user ($auth_uri=$_auth_uri, $username=$_username, $password=$_password);
echo 'authorize user result: '; print_r ($user);

# выборка списка абонентов
$res = jsonsql_call ("select", array (
    "data" => array ("username", "password"),
    "from" => "subscriber"
));
echo 'select cmd result: '; print_r ($res);

# выборка списка тв медиа
$res = jsonsql_call ("select", array (
    "data" => array ("name", array (
        "concat" => array ("protocol", "://", "inet_addr",
                            array ("coalesce" => array (array ("concat" => array (":", "port")), "")),
                            array ("coalesce" => array (array ("concat" => array ("/", "path")), ""))
        ), "as" => "mrl")),
    "from" => "media",
    "where" => array ("eq" => array ("is_tv", true))
));
#echo 'select cmd result: '; print_r ($res);

# выборка списка терминалов
$res = jsonsql_call ("select", array (
    "data" => array (array ("t" => "inet_addr"), array ("t" => "mac_addr"), array ("s" => "username")),
    "from" => array (
        array ("table" => "terminal", "as" => "t"),
        array ("join" => "subscriber", "join_type" => "left", "as" => "s",
               "on" => array ("eq" => array (array ("t" => "subscriber_id"), array ("s" => "id")))
        )
    ),
    "order_by" => array ("s" => "username")
));
echo 'select cmd result: '; print_r ($res);

# добавление абонента "123456" с паролем "111"
$res = jsonsql_call ("insert", array (
    "into" => "subscriber",
    "columns" => array ("username", "password"),
    "values" => array (
        "username" => "123456",
        "password" => "111",
    ),
    "returning" => "id"
));
echo 'insert cmd result: '; print_r ($res);

# добавление терминала с мак-адресом '11-22-33-44-55-66' абоненту "123456"
$res = jsonsql_call ("insert", array (
    "into" => "terminal",
    "columns" => array ("subscriber_id", "mac_addr", "registered"),
    "select" => array (
        "data" => array ("id", '11-22-33-44-55-66', true),
        "from" => array (
            "table" => "subscriber", "as" => "s"
        ),
        "where" => array (
            "eq" => array ("username", "123456")
        )
    ),
    "returning" => "id"
));
echo 'insert cmd result: '; print_r ($res);

# добавление пакетов "movie", "sports" абоненту "123456"
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
                "eq" => array (array ("s" => "username"), "123456")
            ), array (
                "in" => array (array ("p" => "name"), "movie", "sports")
            ))
        )
    ),
    "returning" => "package_id"
));
echo 'insert cmd result: '; print_r ($res);

# получение пакетов для акаунта "123456"
$res = jsonsql_call ("select", array (
    "data" => array (array ("p" => "id"), array ("p" => "name")),
    "from" => array (
        array ("table" => "package", "as" => "p"),
        array ("join" => "subscriber_package", "join_type" => "inner", "as" => "s2p",
               "on" => array ("eq" => array (array ("s2p" => "package_id"), array ("p" => "id")))
        ),
        array ("join" => "subscriber", "join_type" => "inner", "as" => "s",
               "on" => array ("eq" => array (array ("s2p" => "subscriber_id"), array ("s" => "id")))
        )
    ),
    "where" => array ("eq" => array (array ("s" => "username"), "123456")),
    "order_by" => array ("p" => "name")
));
echo 'select cmd result: '; print_r ($res);

# получение терминалов для акаунта "123456"
$res = jsonsql_call ("select", array (
    "data" => array (array ("t" => "id"), array ("t" => "inet_addr"), array ("t" => "mac_addr")),
    "from" => array (
        array ("table" => "terminal", "as" => "t"),
        array ("join" => "subscriber", "join_type" => "inner", "as" => "s",
               "on" => array ("eq" => array (array ("t" => "subscriber_id"), array ("s" => "id")))
        )
    ),
    "where" => array ("eq" => array (array ("s" => "username"), "123456"))
));
echo 'select cmd result: '; print_r ($res);

# отключение абонента с акаунтом "123456"
$res = jsonsql_call ("update", array (
    "table" => "subscriber",
    "set" => array (
        "disabled" => true
    ),
    "where" => array ("eq" => array ("username", "123456")),
    "returning" => "id"
));
echo 'update cmd result: '; print_r ($res);

# обновляем абонента с акаунтом "123456"
$res = jsonsql_call ("update", array (
    "table" => "subscriber",
    "set" => array (
        "disabled" => true
    ),
    "where" => array ("eq" => array ("username", "123456")),
    "returning" => "id"
));
echo 'update cmd result: '; print_r ($res);
if (!(count ($res) > 0)) {
    # добавление абонента "123456" с паролем "111"
    $res = jsonsql_call ("insert", array (
        "into" => "subscriber",
        "columns" => array ("username", "password"),
        "values" => array (
            "username" => "123456",
            "password" => "111",
        ),
        "returning" => "id"
    ));
    echo 'insert cmd result: '; print_r ($res);
}

# удаление абонентских устройств акаунта "123456"
$res = jsonsql_call ("delete", array (
    "from" => "terminal",
    "where" => array ("in" => array ("subscriber_id", array (
        "select" => array (
            "data" => "id",
            "from" => "subscriber",
            "where" => array ("eq" => array ("username", "123456"))
        )
    ))),
    "returning" => "id"
));
echo 'delete cmd result: '; print_r ($res);

# удаление пакетов "movie", "sports" для акаунта "123456"
$res = jsonsql_call ("delete", array (
    "from" => "subscriber_package",
    "where" => array ("and" => array (
        array ("in" => array ("subscriber_id", array (
            "select" => array (
                "data" => "id",
                "from" => "subscriber",
                "where" => array ("eq" => array ("username", "123456"))
            )
        ))), array ("in" => array ("package_id", array (
            "select" => array (
                "data" => "id",
                "from" => "package",
                "where" => array ("in" => array ("name", "movie", "sports"))
            )
        )))
    )),
    "returning" => "package_id"
));
echo 'delete cmd result: '; print_r ($res);

# удаление пакетов для акаунта "123456"
$res = jsonsql_call ("delete", array (
    "from" => "subscriber_package",
    "where" => array ("in" => array ("subscriber_id", array (
        "select" => array (
            "data" => "id",
            "from" => "subscriber",
            "where" => array ("eq" => array ("username", "123456"))
        )
    ))),
    "returning" => "package_id"
));
echo 'delete cmd result: '; print_r ($res);


?>
