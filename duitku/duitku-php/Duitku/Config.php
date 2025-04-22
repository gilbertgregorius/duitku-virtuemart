<?php

class Duitku_Config {

  public static $serverKey;
  public static $apiVersion = 2;
  public static $isProduction = false;  
  public static $isSanitized = true;

  const SANDBOX_BASE_URL = 'http://182.23.85.10/rbsnewwebapi/';
  const PRODUCTION_BASE_URL = 'https://api.veritrans.co.id/v2';

  public static function getBaseUrl()
  {
    return Duitku_Config::$isProduction ?
        Duitku_Config::PRODUCTION_BASE_URL : Duitku_Config::SANDBOX_BASE_URL;
  }
}