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
  protected static $_table_name="app";
  protected static $_db_fields = [
    "name",
    "status",
    "user",
    "_pu_key",
    "_pr_key",
    "prefix",
    "api_max_request_tym",
    "url",
    "endpoint",
    "title",
    "_created"
  ];

  public $name;
  public $status="PENDING";
  public $user;
  public $url;
  public $endpoint;
  public $title;
  public $prefix;
  public $api_max_request_tym = "+30 Seconds";

  private $_pu_key;
  private $_pr_key;
  protected $_created;
  protected $_status = ["PENDING","ACTIVE","SUSPENDED","BANNED"];

  public $errors = [];

  function __construct($app, string $puk="",bool $strict=false){
    self::_checkEnv();
    if( \is_array($app) ){
      $this->_createNew($app);
    }else{
      if( self::validName($app) && !empty($puk) ){
        $this->_objtize($app,$puk,$strict);
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
      \array_key_exists('api_max_request_tym',$app) &&
      \array_key_exists('user',$app) &&
      \array_key_exists('title',$app)
      ) ){
      $app['name'] = \strtolower($app['name']);
      if( self::nameExists($app['name']) ){
        $this->errors['Self'][] = [0,256, "App name({$app['name']}) is not available.",__FILE__, __LINE__];
        return false;
      }
      foreach($app as $key=>$val){
        if( \property_exists(__CLASS__, $key) && !empty($val) ){
          $this->$key = $val;
        }
      }
      $this->name = \strtolower($this->name);
      $this->status = 'PENDING';
      $this->_pu_key = "puk-" . Data::uniqueRand('',48,Data::RAND_MIXED);
      $this->_pr_key = "prk-" . Data::uniqueRand('',64,Data::RAND_MIXED);
      if( $this->_create() ){
        return true;
      }else{
        $this->name = null;
        $this->errors['Self'][] = [0,256, "Request failed at this this tym.",__FILE__, __LINE__];
        if( \class_exists('\TymFrontiers\InstanceError') ){
          $ex_errors = new InstanceError($database);
          if( !empty($ex_errors->errors) ){
            foreach( $ex_errors->get(null,true) as $key=>$errs ){
              foreach($errs as $err){
                $this->errors['Self'][] = [0,256, $err,__FILE__, __LINE__];
              }
            }
          }
        }
      }
    }
    return false;
  }
  private function _objtize(string $name, string $puk, bool $strict=false){
    self::_checkEnv();
    global $database;
    $dev_prim_key = (New Developer)->primaryKey();
    $name = \strtolower($database->escapeValue($name));
    $puk = $database->escapeValue($puk);
    $sql = "SELECT a.name,a.status,a.user,a.url, a.prefix, a.api_max_request_tym, a.endpoint,a.title,a._pu_key,a._pr_key,a._created,
                   d.status AS dev_status
            FROM :db:.:tbl: AS a
            LEFT JOIN :db:.developer AS d ON a.user = d.`{$dev_prim_key}`
            WHERE a.name='{$name}' AND a._pu_key = '{$puk}'
            LIMIT 1";
    $obj = self::findBySql($sql);
    if( !empty($obj) ){
      $obj = $obj[0];
      if( (bool)$strict && ( $obj->status !=='ACTIVE' || $obj->dev_status !== 'ACTIVE' ) ){
        $this->name = null;
        $this->errors['Self'][] = [0,256,"Dev/App not active.",__FILE__,__LINE__];
        return false;
      }
      foreach ($obj as $key => $value) {
        if( \in_array($key, self::$_db_fields) ) $this->$key = $value;
      }
      return true;
    }else{
      $this->name = null;
      $this->errors['Self'][] = [0,256,"No App was found with give name: ({$name}).",__FILE__,__LINE__];
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
      $_GLOBAL['database'] = new \TymFrontiers\MySQLDatabase(MYSQL_SERVER,MYSQL_DEVELOPER_USERNAME,MYSQL_DEVELOPER_PASS,self::$_db_name);
    }
  }

}
