<?php

namespace WebPlatform\Importer\Cache;

class InvalidUserExportCacheFormatException extends \Exception {
  public $message = 'We could not convert the User object from the array we received and got incorrect conversion.';
}
