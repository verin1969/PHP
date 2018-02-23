<?php
#
# finduser.php - Класс для выбора пользователей из СУБД 
# Autor: Vyacheslav Erin
# Crated: 21.02.2018
#
namespace SearchDB;
use PDO;
/*
 * Класс для получения данных о пользователях из СУБД по заданному фильтру
 */

class FindUser {
    # Константы
    const WRONG_FILTER = "Wrong filter structure";
    # Пременные класса
    protected $connection;
    protected $filter = "";
    protected $templateSQL =
         "select id, email, role, rdate
            from (select users.id as id, 
                         users.email as email, 
                         users.role as role, 
                         users.reg_date as rdate, 
                         max((case when users_about.item='firstname' then users_about.value  else null end)) as fname, 
                         max((case when users_about.item='country' then users_about.value  else null end)) as country, 
                         max((case when users_about.item='state' then users_about.value  else null end)) as state 
                         from users inner join users_about on users.id = users_about.user
                   group by users.id) ulist";
    protected $whereSQL = "";
    protected $execSQL = ""; 
    protected $filterFields = array("ID"         => "ulist.id",
                                    "E-Mail"     => "ulist.email",
                                    "Страна"     => "ulist.country",
                                    "Имя"        => "ulist.fname",
                                    "Состояние"  => "ulist.state",
                                    );
    # Статические функции
    # Открыть соединение с заданной базой MySQL
    public static function connectDB ($host, $dbname, $dbuser, $dbpswd) {
        return new \PDO("mysql:host=$host;dbname=$dbname", $dbuser, $dbpswd, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                                                                   PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"));
    }
    # Конструктор
    public function __construct(PDO $connection) {
        $this->connection = $connection;
    }
    # Внутренние функции класса
    # Обработка опреций сравнения (=, >, <, <=, >=, !=) 
    private function compareOperation($filter) {
       $result = null;
       if(is_array($filter))	{
           foreach ($filter as $key=>$val) {
               switch ($key) {
                   case "eq":
                       $operation = " = ";
                       break;
                   case "neq":
                       $operation = " != ";
                       break;
                   case "lt":
                       $operation = " < ";
                       break;
                   case "gt":
                       $operation = " > ";
                       break;
                   case "lte":
                       $operation = " <= ";
                       break;
                   case "gte":
                       $operation = " >= ";
                       break;
                   default:
                       throw new \Exception(self::WRONG_FILTER);
               }
               if (is_array($val) && (count($val) == 2)) {
                   if (isset($this->filterFields[$val[0]])) {
                      $result = "(" . $this->filterFields[$val[0]] . $operation . ($val[1]) . ")";
                   }
               }
           }
       }
       return $result;
    }
    # Обработка логических опреций ( и, или) 
    private function logicOperation($filter) {
       if (is_null($filter)) {
         throw new \Exception(self::WRONG_FILTER);
       }
       $result = null;
       if(is_array($filter))	{
           foreach ($filter as $key => $val) {
               switch ($key) {
                   case "and":
                       $operation = " and ";
                       break;
                   case "or":
                       $operation = " or ";
                       break;
                   default:
                       $operation = "?";
               }
               if ($operation == "?") {
                       $result = $this->compareOperation($filter);
               } else {
                   if (is_array($val) && (count($val) == 2)) {
                       $result = "(" . $this->logicOperation($val[0]) . $operation . $this->logicOperation($val[1]) . ")";
                   }
               }
           }
       }
       if (is_null($result)) {
         throw new \Exception(self::WRONG_FILTER);
       }
       return $result;
    }
    # Внешние функции класса
    # Установка заданного фильтра 
    public function setFilter($filter) {
        $this->whereSQL = $this->logicOperation(json_decode($filter, true));
        $this->execSQL = $this->templateSQL . " where " . $this->whereSQL;
        return $this;
    }
    # Полученеи спиcка пользователей в соответствии с заданным фильтром 
    public function getUsers() {
        return ($this->connection)->query($this->execSQL)->fetchAll();
   }
}
/*
 * Клиентский код вызова
 */
$users = new FindUser(FindUser::connectDB('localhost', 'user', 'testuser', 'test1969'));
$filter = '{"or":[{"and":[{"and":[{"eq":["Страна","\"Россия\""]},{"neq":["Состояние","\"active\""]}]},{"eq":["E-Mail","\"www@mail.du\""]}]},{"neq":["Имя","\"\""]}]}';
$ulist = $users->setFilter("$filter")->getUsers();
var_dump($ulist);