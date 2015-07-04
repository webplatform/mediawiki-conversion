<?php

namespace WebPlatform\Importer\Cache;

class UnsupportedUserExportCacheInputException extends \Exception {
  public $message = 'Unsupported input given, User class requires either a JSON string, or an Array with keys: user_email, user_name, user_real_name, user_email_authenticated';
}
