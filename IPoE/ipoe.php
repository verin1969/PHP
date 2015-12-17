<?php
#
# Скрипт управления платформой IPoE
# Version 1.0.00
#
# Данные для тестов
$_POST = array( 'reqtype' => '1', 'ip' => '10.0.0.2', 'mask' => '255.255.255.0', 'iface' => 'if_10.0.0.2', 'switch' => 'ACC_BIZARRE', 'port' => '1', 'rate' => '0', 'burst' => '0');
#
date_default_timezone_set('Europe/Moscow');
# Коды ответов
define("C_OK_HEADER", "HTTP/1.1 200 OK");      
define("C_ERR_HEADER", "HTTP/1.1 406 ERROR"); 
# Типы заявок
define("C_UNKNOWN",     0);  // Неизвестная заявка
define("C_SUBS_CREATE", 1);  // Создание абонента
define("C_SUBS_DELETE", 2);  // Удаление абонента
#Файл журнала разбиваем по дням
$log_file_name = "./log/ipoe_".strftime ("%d%m%Y").".log"; 
# Список разрешенных адресов
$allowed_ips  = "0.0.0.0;10.15.5.15";
# Сервер, откуда пришел запрос
if (isset($_SERVER["REMOTE_ADDR"])) {
    $current_ip = $_SERVER["REMOTE_ADDR"];
} else {
    $current_ip = '0.0.0.0';
}
# DB радиуса
$_host     = '81.25.61.146';
$_db_name  = 'radius';
$_username = 'postgres'; 
$_password = 'altldG5t';
# Сообщение об ошибке
$error_message = 'ERROR';

# Перехват ошибок
function error_handle($errcode, $err_message) {
    global $error_message;
    $error_message = $err_message;
    return true;
}

# Запись в журнал
function save_to_log($logstr) {
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

# Создаем ответ
function response($ret_code, $ret_text)
{
    $xml_header = '<?xml version="1.0" encoding="windows-1251"?>';
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
    # Выводим ответ
    header($header_data);  
    header("Content-Type: text/xml; charset=windows-1251");
    save_to_log ($out_data);
    die($out_data);
}

# Класс для работы с БД радиуса
class radius {
    # Дескриптор коннекта с БД радиуса
    private $connection;
    # Массив данных для передачи в БД радиуса
    private $rad_array = array( 'ip' => '', 'mask' => '', 'iface' => '', 'switch' => '', 'port' => '', 'rate' => '', 'burst' => '0');
    
    # Соединение с БД радиуса
    function __construct($host, $db_name, $username, $password, $radius_data)
    {
        $pass = base64_decode($password);
        $this->connection = pg_connect("hostaddr=$host port=5432 dbname=$db_name user=$username password=$pass connect_timeout = 10");
        $this->set_data($radius_data);
    }
    
    # Заполнение массива с данными для БД радиуса
    function set_data($in_array) {
        if (isset($in_array)) {
            foreach ($this->rad_array as $key => $value ) {
                $this->rad_array[$key] = isset($in_array[$key]) ? $in_array[$key] : $value;
            }
        }
    }

    # Соединено с БД ?
    function is_connected () {
        return (boolean) ($this->connection);
    }
    
    # Добавление абонента 
    function add_abonent () {
        $ins_sql = "INSERT INTO public.users(ip, mask, iface, switch, port, rate, burst) VALUES ( $1, $2, $3, $4, $5, $6, $7 )";
        # Подготовка запроса
        $result = pg_prepare($this->connection, "ins_sql", $ins_sql);
        $subs_data = array($this->rad_array["ip"], $this->rad_array["mask"], $this->rad_array["iface"], $this->rad_array["switch"], $this->rad_array["port"], $this->rad_array["rate"], $this->rad_array["burst"]);
        # Запуск запроса на выполнение. 
        $result = pg_execute($this->connection, "ins_sql", $subs_data);
        return $result;
    }
    
    # Удаление абонента 
    function delete_abonent () { 
        $del_sql = "DELETE FROM public.users WHERE ip = $1";
        # Подготовка запроса
        $result = pg_prepare($this->connection, "del_sql", $del_sql);
        $subs_data =  array($this->rad_array["ip"]);
        # Запуск запроса на выполнение
        $result = pg_execute($this->connection, "del_sql", $subs_data);
        return $result;
    }
    
}
################################ Старт скрипта ################################
# Устанавливаем обработку ошибок
set_error_handler("error_handle", E_ALL);
# Выполняем заявку
save_to_log('START');

# Проверка разрешенных IP для доступа
if (array_search($current_ip, explode(";",$allowed_ips)) === FALSE) {
	response(1, 'Wrong request IP');
    exit;
}

# Создаем независимость от способа передачи параметров
if ((isset($_GET)) and (!isset($_POST)))  $_POST = $_GET;
# Создаем внутренние переменные из глобальныз массивов
$reqtype = (isset($_POST['reqtype'])) ? $_POST['reqtype'] : C_UNKNOWN;
   
$radius = new radius($_host, $_db_name, $_username, $_password, $_POST);

if ($radius->is_connected()) {
    save_to_log($_POST);
    switch ($reqtype) {
        case C_SUBS_CREATE     : $res = $radius->add_abonent();
            break;
        case C_SUBS_DELETE     : $res = $radius->delete_abonent();
            break;
        default : response(1, "Unknown request type");
            break;
    }
    if ($res) {
       response(0, "OK"); 
    } else {
       response(1, $error_message);
    }
} else {
    response(1, $error_message);
}
