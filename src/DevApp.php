<?php
namespace TymFrontiers\API;
use \TymFrontiers\Data,
    \TymFrontiers\MySQLDatabase,
    \TymFrontiers\InstanceError;

class DevApp{
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key='name';
  protected static $_db_name=MYSQL_DEV_DB;
  protected static $_table_name="apps";
  protected static $_prop_type = [];
  protected static $_prop_size = [];
  protected static $_db_fields = [
    "name",
    "live",
    "status",
    "user",
    "_pu_key",
    "_pr_key",
    "prefix",
    "request_timeout",
    "domain",
    "endpoint",
    "title",
    "description",
    "_created",
    "_updated"
  ];


  public $name;
  public $live = false;
  public $status = "PENDING";
  public $user;
  public $domain = null;
  public $endpoint = null;
  public $title;
  public $description;
  public $prefix;
  public $request_timeout = "5S";

  private $_pu_key;
  private $_pr_key;
  protected $_created;
  protected $_updated;
  protected $_status = ["PENDING","ACTIVE","SUSPENDED","BANNED"];
  public $request_timeout_opt = [
    "5S" => "5 Seconds",
    "15S" => "15 Seconds",
    "30S" => "30 Seconds",
    "5M" => "5 Minutes", // development
    "30M" => "30 Minutes" // development
  ];

  public $errors = [];

  function __construct($app = [], string $puk="", bool $strict=false){
    if (!empty($app)) {
      self::_checkEnv();
      if( \is_array($app) ){
        $this->_createNew($app);
      }else{
        if( self::validName($app) && !empty($puk) ){
          $this->_objtize($app,$puk,$strict);
        }
      }
    }
  }
  public function setStatus ( string $status ){
    if ( !empty($this->name) ) {
      $status = \strtoupper($status);
      if( \in_array($status,$this->_status) && $this->status !== 'BANNED' ){
        $this->status = $status;
        return $this->_update();
      }
    }
    return false;
  }
  public function publicKey(){ return $this->_pu_key; }
  public function privateKey(){ return $this->_pr_key; }
  private function _createNew(array $app){
    self::_checkEnv();
    global $database;
    if( \is_array($app) && (
      \array_key_exists('name',$app) &&
      \array_key_exists('prefix',$app) &&
      \array_key_exists('request_timeout',$app) &&
      \array_key_exists('user',$app) &&
      \array_key_exists('title',$app) &&
      \array_key_exists('description',$app)
      ) ){
      $app['name'] = \strtolower($app['name']);
      if( self::nameExists($app['name']) ){
        $this->errors['self'][] = [0,256, "App name({$app['name']}) is not available.",__FILE__, __LINE__];
        return false;
      }
      foreach($app as $key=>$val){
        if( \property_exists(__CLASS__, $key) && !empty($val) ){
          $this->$key = $val;
        }
      }
      $this->name = \strtolower($this->name);
      $this->live = false;
      $this->status = 'PENDING';
      $this->_pu_key = "puk-" . Data::uniqueRand('',48,Data::RAND_MIXED);
      $this->_pr_key = "prk-" . Data::uniqueRand('',64,Data::RAND_MIXED);
      if( $this->_create() ){
        return true;
      }else{
        $this->name = null;
        $this->errors['self'][] = [0,256, "Request failed at this this tym.",__FILE__, __LINE__];
        if( \class_exists('\TymFrontiers\InstanceError') ){
          $ex_errors = new InstanceError($database);
          if( !empty($ex_errors->errors) ){
            foreach( $ex_errors->get(null,true) as $key=>$errs ){
              foreach($errs as $err){
                $this->errors['self'][] = [0,256, $err,__FILE__, __LINE__];
              }
            }
          }
        }
      }
    } else {
      $this->errors['self'][] = [0,256, "Missing required parameters.",__FILE__, __LINE__];
    }
    return false;
  }
  private function _objtize(string $name, string $puk, bool $strict=false){
    self::_checkEnv();
    global $database;
    $name = \strtolower($database->escapeValue($name));
    $puk = $database->escapeValue($puk);
    $adm_db = MYSQL_ADMIN_DB;
    $sql = "SELECT a.name, a.live, a.status,a.user,a.domain, a.prefix, a.request_timeout, a.endpoint,a.title,a._pu_key,a._pr_key,a._created,
                   d.status AS dev_status
            FROM :db:.:tbl: AS a
            LEFT JOIN `{$adm_db}`.user AS d ON a.user = d.`_id`
            WHERE a.name='{$name}' AND a._pu_key = '{$puk}'
            LIMIT 1";
    $obj = self::findBySql($sql);
    if( !empty($obj) ){
      $obj = $obj[0];
      if( (bool)$strict && ( $obj->status !=='ACTIVE' || $obj->dev_status !== 'ACTIVE' ) ){
        $this->name = null;
        $this->errors['self'][] = [0,256,"Dev/App not active.",__FILE__,__LINE__];
        return false;
      }
      foreach ($obj as $key => $value) {
        if( \in_array($key, self::$_db_fields) ) $this->$key = $value;
      }
      return true;
    }else{
      $this->name = null;
      $this->errors['self'][] = [0,256,"No App was found with give name: ({$name}).",__FILE__,__LINE__];
    }
    return false;
  }

  public static function nameExists(string $name){
    $name = \strtolower($name);
    if( self::validName($name) ){
      self::_checkEnv();
      global $database;
      ###############
      $name = $database->escapeValue($name);
      return self::findBySql("SELECT name FROM :db:.:tbl: WHERE name='{$name}' LIMIT 1") ? true :false;
    }
    return false;
  }
  public static function validName(string $name){
    return (bool) \preg_match('/^([a-z0-9\.\-\_]{5,35})$/s', $name);
  }
  private static function _checkEnv(){
    global $database;
    if ( !$database instanceof \TymFrontiers\MySQLDatabase ) {
      if(
        !\defined("MYSQL_DEV_DB") ||
        !\defined("MYSQL_SERVER") ||
        !\defined("MYSQL_DEVELOPER_USERNAME") ||
        !\defined("MYSQL_DEVELOPER_PASS")
      ){
        throw new \Exception("Required defination(s)[MYSQL_BASE_DB, MYSQL_SERVER, MYSQL_USER_USERNAME, MYSQL_USER_PASS] not [correctly] defined.", 1);
      }
      // check if guest is logged in
      $GLOBALS['database'] = new \TymFrontiers\MySQLDatabase(MYSQL_SERVER,MYSQL_DEVELOPER_USERNAME,MYSQL_DEVELOPER_PASS,self::$_db_name);
    }
  }

}
