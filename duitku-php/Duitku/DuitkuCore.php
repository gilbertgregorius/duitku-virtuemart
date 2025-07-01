<?php

class DuitkuCore_Web {

  public static function getRedirectionUrl($baseUrl, $params)
  {
    //$payloads = array();
    //$payloads = array_replace_recursive($payloads, $params);    
    
    $result = Duitku_ApiRequestor::post($baseUrl . '/api/merchant/v2/inquiry',$params);

    //var_dump($result);
    //die();
    return $result->paymentUrl;
  }
  
  public static function validateTransaction($baseUrl, $merchantCode, $order_id, $reference, $apikey)
  {

        $url = $baseUrl . '/api/merchant/transactionStatus';                        

        //generate Signature
        $signature = md5($merchantCode . $order_id . $apikey);

        // Prepare Parameters
        $params = array(
          'merchantCode' => $merchantCode, // API Key Merchant /
          'merchantOrderId' => $order_id,
          'signature' => $signature,
          'reference' => $reference,
        );

        //throw error if failed
        $result = Duitku_ApiRequestor::post($url,$params);    

        if ($result->statusCode == "00")			
			return true;
		else
			return false;	
  }
}