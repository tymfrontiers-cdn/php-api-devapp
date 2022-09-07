<?php
namespace TymFrontiers\API;
use \TymFrontiers\Data,
    \TymFrontiers\MySQLDatabase,
    \TymFrontiers\MultiForm,
    \TymFrontiers\InstanceError;

class DevApp{
  use \TymFrontiers\Helper\MySQLDatabaseObject,
      \TymFrontiers\Helper\Pagination;

  protected static $_primary_key = 'name';
  protected static $_db_name;
  protected static $_table_name="apps";
  protected static $_prop_type = [];
  protected static $_prop_size = [];
  protected static $_db_fields = [
    "name",
    "status",
    "user",
    "_pu_key",
    "_pr_key",
    "prefix",
    "domain",
    "endpoint",
    "title",
    "description",
    "_created",
    "_updated"
  ];


  public $name;
  public $status = "PENDING";
  public $user;
  public $domain = null;
  public $endpoint = null;
  public $title;
  public $description;
  public $prefix;
  protected $is_system = false;

  private $_pu_key;
  private $_pr_key;
  protected $_created;
  protected $_updated;
  protected $_status = ["PENDING","ACTIVE","SUSPENDED","BANNED"];

  public $errors = [];

  function __construct (MySQLDatabase $conn, string $db, string $db_table = "apps") {
    self::$_conn = $conn;
    self::$_db_name = $db;
    self::$_table_name = $db_table;
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
  public function register (array $app, bool $activate = false) {
    $req = ["name", "domain", "prefix", "user", "title", "description" ];
    $unseen = [];
    foreach ($app as $prop => $value) {
      if ($this->isEmpty($prop, $value)) {
        $unseen[] = $prop;
      }
    }
    if ( empty($unseen) ){
      $app['name'] = \strtolower($app['name']);
      if( self::nameExists($app['name']) ){
        $this->errors['register'][] = [0,256, "App name({$app['name']}) is not available.",__FILE__, __LINE__];
        return false;
      }
      foreach($app as $key=>$val){
        if( \property_exists(__CLASS__, $key) && !empty($val) ){
          $this->$key = $val;
        }
      }
      $this->name = \strtolower($this->name);
      $this->status = $activate ? "ACTIVE" : 'PENDING';
      $this->_pu_key = "puk-" . Data::uniqueRand('',48,Data::RAND_MIXED);
      $this->_pr_key = "prk-" . Data::uniqueRand('',64,Data::RAND_MIXED);
      // get user privileges
      $app_user = (new MultiForm(self::$_db_name, "users", "code", self::$_conn))->findById($app["user"]);
      if (!$app_user) {
        $this->errors['register'][] = [0,256, "App user({$app['user']}) not found.",__FILE__, __LINE__];
        return false;
      } if ($app_user->status !== "ACTIVE") {
        $this->errors['register'][] = [0,256, "App user({$app['user']}) is not active.",__FILE__, __LINE__];
        return false;
      }
      if( $this->_create() ){
        $this->load($this->name, $this->_pu_key);
        return true;
      }else{
        $this->name = null;
        $this->errors['register'][] = [0,256, "Request failed at this this tym.",__FILE__, __LINE__];
        if( \class_exists('\TymFrontiers\InstanceError') ){
          $ex_errors = new InstanceError(self::$_conn);
          if( !empty($ex_errors->errors) ){
            foreach( $ex_errors->get("",true) as $key=>$errs ){
              foreach($errs as $err){
                $this->errors['register'][] = [0,256, $err,__FILE__, __LINE__];
              }
            }
          }
        }
      }
    } else {
      $this->errors['register'][] = [0,256, "Missing required parameters: '".\implode("', '", $unseen). "'",__FILE__, __LINE__];
    }
    return false;
  }
  public function load(string $name, string $puk, bool $strict=false){
    $conn = self::$_conn;
    $name = \strtolower($conn->escapeValue($name));
    $puk = $conn->escapeValue($puk);
    $sql = "SELECT a.name, a.status, a.user, a.domain, a.prefix, 
                   a.endpoint,a.title,a._pu_key,a._pr_key,a._created,
                   usr.`status` AS dev_status, usr.is_system
            FROM :db:.:tbl: AS a
            LEFT JOIN :db:.users AS usr ON a.user = usr.`code`
            WHERE a.name='{$name}' AND a._pu_key = '{$puk}'
            LIMIT 1";
    $obj = self::findBySql($sql);
    if( !empty($obj) ){
      $obj = $obj[0];
      if( (bool)$strict && ( $obj->status !=='ACTIVE' || $obj->dev_status !== 'ACTIVE' ) ){
        $this->name = null;
        $this->errors['load'][] = [0,256,"Dev/App not active.",__FILE__,__LINE__];
        return false;
      }
      foreach ($obj as $key => $value) {
        if( \in_array($key, self::$_db_fields) ) $this->$key = $value;
      }
      return true;
    }else{
      $this->name = null;
      $this->errors['load'][] = [0,256,"No App was found with give name: ({$name}).",__FILE__,__LINE__];
    }
    return false;
  }

  public static function nameExists(string $name){
    $name = \strtolower($name);
    if( self::validName($name) ){
      ###############
      $name = self::$_conn->escapeValue($name);
      return self::findBySql("SELECT name FROM :db:.:tbl: WHERE name='{$name}' LIMIT 1") ? true :false;
    }
    return false;
  }
  public static function validName(string $name){
    return (bool) \preg_match('/^([a-z0-9\.\-]{5,35})$/s', $name);
  }
  private static function _instantiate ($record) {
    $class_name = \get_called_class();
		// $object = new $class_name();
		$object = new self (self::$_conn, static::$_db_name,static::$_table_name);
		foreach ($record as $attribute=>$value) {
      if ( !\is_int($attribute) ) {
        $object->$attribute = $value;
      }
		}
		return $object;
	}
  public function conn () {
    return self::$_conn;
  }
  public function isSystem () { return $this->is_system; }
}
