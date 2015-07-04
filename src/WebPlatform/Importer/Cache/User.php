<?php

namespace WebPlatform\Importer\Cache;

class User {

    protected $name;
    protected $email;
    protected $real_name = '';
    protected $email_authenticated = false;
    protected $id = 0;

    /**
     * Build an user entity based on either an array or a JSON string
     *
     * Acceptable input:
     *
     * 1. String
     *
     *     {"user_email":"foo@bar.org","user_id":"1","user_name":"WikiSysop","user_real_name":"","user_email_authenticated":null}
     *
     * 2. Array
     *
     *     array('user_email'=>'foo@bar.org', 'user_id'=>1, 'user_name'=> 'WikiSysop', 'user_real_name'=>'', 'user_email_authenticated'=> null);
     **/
    public function __construct($dto=null) {
      if(is_string($dto)) {
        $data = json_decode($dto, true);
      } elseif(is_array($dto)) {
        $data = $dto;
      } else {
        throw new UnsupportedUserExportCacheInputException();
      }

      if(is_array($data)) {
        foreach($data as $k => $v) {
          $key = str_replace('user_', '', $k);
          if(property_exists($this, $key)) {
            $this->{$key} = $v;
          }
        }
      } else {
        throw new InvalidUserExportCacheFormatException();
      }

      return $this;
    }

    public function getUsername() {
      return $this->username;
    }

    public function getEmail() {
      return $this->email;
    }

    public function getFullname() {
      return $this->full_name;
    }

    public function isValid() {
      return ($this->email_validated === null)?false:true;
    }
}
