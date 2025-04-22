<?php

defined('_JEXEC') or die('Restricted access');

if (!class_exists('Duitku_Config'))
    require(VMPATH_PLUGINS . DS . 'vmpayment' . DS . 'duitku' . DS . 'duitku-php' . DS . 'Duitku.php');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

if (!class_exists('VirtueMartModelOrders'))
    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

class plgVmpaymentDuitku extends vmPSPlugin
{
    private $paymentConfigs = array();

    function __construct(& $subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = TRUE;
        $this->_tablepkey = 'id';
        $this->_tableId = 'id';
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = array(
            'merchant_code' => array('', 'char'),
            'secret_key' => array('', 'char'),
            'url_endpoint' => array('', 'char'),
            'duitku_expired' => array('', 'char'),
            'status_success' => array('', 'char'),          
			'status_canceled' => array('', 'char'),
			'payment_logos' => array('', 'char'),
			'duitkuproduct' => array('', 'char'),
        );
        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Duitku Table');
    }

    function getTableSQLFields()
    {
         $sqlFields = array(
            'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(2000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'email_currency' => 'char(3)',
            'cost_per_transaction' => 'decimal(10,2)',
            'cost_min_transaction' => 'decimal(10,2)',
            'cost_percent_total' => 'decimal(10,2)',
            'tax_id' => 'smallint(1)'
        );

        return $sqlFields;
    }

    /**
     * Create the table for this plugin if it does not yet exist.
     * This functions checks if the called plugin is active one.
     * When yes it is calling the standard method to create the tables
     */
    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        if (!defined('VM_VERSION') || VM_VERSION < 3) {
            // for older vm version
            return $this->onStoreInstallPaymentPluginTable($jplugin_id);
        } else {
            return $this->onStoreInstallPluginTable($jplugin_id);
        }
    }

    /**
     * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
     * The plugin must check first if it is the correct type
     *
     * @param VirtueMartCart cart: the cart object
     * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
     */
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array())
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices);
    }

    /**
     * Process after buyer set confirm purchase in check out< it loads a new page with widget
     */
    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($this->_currentMethod = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
            return NULL; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
            return FALSE;
        }

        VmConfig::loadJLang('com_virtuemart', true);		
        // Prepare data that should be stored in the database
        $transaction_data = $this->prepareTransactionData($order, $cart);
        $this->storePSPluginInternalData($transaction_data);
		
        $userinfo = $order['details']['BT']->virtuemart_user_id != 0 ? $order['details']['BT']->email : $_SERVER['REMOTE_ADDR'];        
								 
		//generate Signature
		$configs = $this->getPaymentConfigs($order['details']['BT']->virtuemart_paymentmethod_id);		
		$merchant_code = $configs['merchant_code'];
		$api_key = $configs['secret_key'];
		$url_endpoint = stripslashes(trim($configs['url_endpoint']));		
		$duitku_expired = $configs['duitku_expired'] != null ? $configs['duitku_expired'] : 1440;		
		$success_url = $configs['success_url'];
		$order_id = $order['details']['BT']->virtuemart_order_id;		
		$order_number = $order['details']['BT']->order_number;
		$def_curr = $this->getCurrencyCodeById($order['details']['BT']->order_currency);
		//$order_total = $def_curr == 'IDR' ? $order_info['total'] : $this->currency->convert($order_info['total'], $order_info['currency_code'], 'IDR');
		$order_total = $order['details']['BT']->order_total;
		$signature = md5($merchant_code . $order_number . intval($order_total) . $api_key);    				
		// Prepare Parameters
		
		// set payment method
		$payment_method = 'VC'; //default payment method
		if ($this->_currentMethod->duitkuproduct == "cc")
			$payment_method = "VC";
		else if ($this->_currentMethod->duitkuproduct == "ovo")
			$payment_method = "OV";
		else if ($this->_currentMethod->duitkuproduct == "bca")
			$payment_method = "BK";
		else if ($this->_currentMethod->duitkuproduct == "permata")
			$payment_method = "BT";
		else if ($this->_currentMethod->duitkuproduct == "vaatmbersama")
			$payment_method = "A1";
		else if ($this->_currentMethod->duitkuproduct == "vabni")
			$payment_method = "I1";
		else if ($this->_currentMethod->duitkuproduct == "vamandiri")
			$payment_method = "M1";
		else if ($this->_currentMethod->duitkuproduct == "vacimb")
			$payment_method = "B1";
		else if ($this->_currentMethod->duitkuproduct == "vamaybank")
			$payment_method = "VA";
		else if ($this->_currentMethod->duitkuproduct == "varitel")
			$payment_method = "FT";
		else if ($this->_currentMethod->duitkuproduct == "shopeepay")
			$payment_method = "SP";
		else if ($this->_currentMethod->duitkuproduct == "indodana")
			$payment_method = "DN";
        else if ($this->_currentMethod->duitkuproduct == "briva")
            $payment_method = "BR";
        else if ($this->_currentMethod->duitkuproduct == "bnc")
            $payment_method = "NC";
        else if ($this->_currentMethod->duitkuproduct == "atome")
            $payment_method = "AT";
        else if ($this->_currentMethod->duitkuproduct == "jeniuspay")
            $payment_method = "JP";
        else if ($this->_currentMethod->duitkuproduct == "gudangvoucherqris")
            $payment_method = "GQ";
		
		$conversion_rate = floatval($this->_currentMethod->conversion_rate);
		if(!isset($conversion_rate) OR $conversion_rate='' OR $conversion_rate='1'){
			$conversion_rate = 1;
		}
		
		//ItemDetails
	  	$itemDetailParams = array();

	  	//item details array
		foreach ($order['items'] as $line_item_wrapper) {
			$item = array();
			$line_item_price = $line_item_wrapper->product_final_price;
			$item['quantity'] = $line_item_wrapper->product_quantity;
			$item['price'] = ceil($line_item_price * $conversion_rate) * $item['quantity'];
			$item['name'] = $line_item_wrapper->order_item_name;
			$itemDetailParams[] = $item;
		}
		
		//shipment tax item details
		$price_shipment = ceil(($order['details']['BT']->order_shipment + $order['details']['BT']->order_shipment_tax) * $conversion_rate);
		if ( $price_shipment != 0 ) {
			$item = array();
			$item['quantity'] = 1;
			$item['price'] = $price_shipment;
			$item['name'] = "Shipment & tax";
			$itemDetailParams[] = $item;
		}

		// $gross_amount += $item['price'] * $item['quantity']
		
		//discount item details
		$price_discount = -(ceil($order['details']['BT']->coupon_discount) * $conversion_rate);
		if ( $price_discount != 0 ) {
			$item = array();
			$item['quantity'] = 1;
			$item['price'] = $price_discount;
			$item['name'] = "Coupon";
			$itemDetailParams[] = $item;
		}
		
		//address
		$address = $order['details']['BT']->address_1;
		if (isset($order['details']['BT']->address_2) and $order['details']['BT']->address_2) {
			$address .= $order['details']['BT']->address_2;
		}
		
		$billing_address = array(
		  'firstName' => $order['details']['BT']->first_name,
		  'lastName' => $order['details']['BT']->last_name,
		  'address' => $address,
		  'city' => $order['details']['BT']->city,
		  'postalCode' => $order['details']['BT']->zip,
		  'phone' => $order['details']['BT']->phone_1,
		  'countryCode' => "ID"
		);
		
		$customerDetails = array(
			'firstName' => $order['details']['BT']->first_name,
			'lastName' => $order['details']['BT']->last_name,
			'email' => $order['details']['BT']->email,
			'phoneNumber' => $order['details']['BT']->phone_1,
			'billingAddress' => $billing_address,
			'shippingAddress' => $billing_address
		);
		
		$params = array(
          'merchantCode' => $merchant_code, // API Key Merchant /
          'paymentAmount' => intval($order_total), //transform order into integer
          'paymentMethod' => $payment_method,
          'merchantOrderId' => $order_number,
          'productDetails' => ' Order : #' . $order_number,
          'additionalParam' => '',
          'merchantUserInfo' => $userinfo, // id of the end-user who's making the payment
          'customerVaName' => $userinfo,
          'email' => $order['details']['BT']->email,
          'phoneNumber' => $order['details']['BT']->phone_1,
          'signature' => $signature,          
          'returnUrl' => JURI::root() . 'index.php?option=com_virtuemart&view=vmplg&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id . '&Itemid=' . vRequest::getInt('Itemid') . '&lang=' . vRequest::getCmd('lang', ''),
          'callbackUrl' => JURI::root() . 'index.php?option=com_virtuemart&view=vmplg&task=notify&tmpl=component' . '&lang=' . vRequest::getCmd('lang', ''),
          'expiryPeriod' => $duitku_expired,
          'customerDetail' => $customerDetails,
          'itemDetails' => $itemDetailParams
		);       				
		
		try {
			$redirUrl = DuitkuCore_Web::getRedirectionUrl($url_endpoint, $params);  
            // var_dump($params);
            // exit;     			
		}
		catch (Exception $e) {			
			//$data['errors'][] = $e->getMessage();
			//error_log($e->getMessage());
			echo $e->getMessage();
			die();
		}		    											
		
		//might be required if virtual account
		if ($this->_currentMethod->duitkuproduct == "permata" || $this->_currentMethod->duitkuproduct == "vaatmbersama" || $this->_currentMethod->duitkuproduct == "vabni" || $this->_currentMethod->duitkuproduct == "vamandiri" || $this->_currentMethod->duitkuproduct == "ovo" || $this->_currentMethod->duitkuproduct == "vacimb" || $this->_currentMethod->duitkuproduct == "vamaybank") {			            
			$cart->emptyCart();
			$order_history = array(
					'customer_notified' => 1, 
					'virtuemart_order_id' => $order_id,
					'comments' => 'waiting payment from ' . $this->_currentMethod->payment_name,							
					'order_status' => 'P', //status set to pending
			);			
			$orderModel = VmModel::getModel('orders');			
			$orderModel->updateStatusForOneOrder($order_id, $order_history, FALSE);
		} else {
			// 	2 = don't delete the cart, don't send email and don't redirect
			$cart->_confirmDone = FALSE;
			$cart->_dataValidated = FALSE;
			$cart->setCartIntoSession();
		}
		
		$app = JFactory::getApplication();
		$msg = 'redirecting';
		$app->redirect($redirUrl);		
		//vRequest::setVar('html', $html);				       
    }

    /**
     * This event is fired after the payment method has been selected. It can be used to store
     * additional payment info in the cart.
     *
     * @param VirtueMartCart $cart : the actual cart
     * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
     */
    function plgVmOnSelectCheckPayment(VirtueMartCart $cart, &$msg)
    {
        return $this->OnSelectCheck($cart);
    }

    /**
     * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
     *
     * @param object|VirtueMartCart $cart Cart object
     * @param integer $selected ID of the method selected
     * @param $htmlIn
     * @return bool True on success, false on failures, null when this plugin was not selected.
     * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
     */
    function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        //ToDo add image logo
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    /**
     * Check if the payment conditions are fulfilled for this payment method
     *
     * @param VirtueMartCart $cart
     * @param int $method
     * @param array $cart_prices : cart prices
     * @return true : if the conditions are fulfilled, false otherwise
     *
     */
    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

    /**
     * This method is fired when showing the order details in the frontend.
     * It displays the method-specific data.
     *
     * @param $virtuemart_order_id
     * @param $virtuemart_paymentmethod_id
     * @param $payment_name
     * @return mixed Null for methods that aren't active, text (HTML) otherwise
     */
    function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    /**
     * Calculate the price (value, tax_id) of the selected method
     * It is called by the calculator
     * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
     *
     * @param VirtueMartCart $cart the current cart
     * @param array cart_prices the new cart prices
     * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
     *
     *
     */
    function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return null; // Another method was selected, do nothing
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return false;
        }

        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    /**
     * Addition triggers for VM3
     * @param $data
     * @return bool
     */
    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    /**
     * This method is fired when showing when priting an Order
     * It displays the the payment method-specific data.
     *
     * @param $order_number
     * @param integer $method_id method used for this order
     * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
     * @internal param int $_virtuemart_order_id The order ID
     */
    function plgVmOnShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    public function getCurrencyCodeById($currency_id)
    {
        $db = JFactory::getDBO();
        $query = $db->getQuery(true);
        $query->select($db->quoteName(array('currency_code_3')));
        $query->from($db->quoteName('#__virtuemart_currencies'));
        $query->where($db->quoteName('virtuemart_currency_id') . '=' . $db->quote($currency_id));
        $db->setQuery($query, 0, 1);

        $result = $db->loadRow();
        return $result ? $result[0] : false;
    }    
		
    /**
     * Get Payment configs
     * @param $payment_id
     * @return array|bool
     */
    public function getPaymentConfigs($payment_id = false)
    {
        if (!$this->paymentConfigs && $payment_id) {

            $db = JFactory::getDBO();
            $query = $db->getQuery(true);
            $query->select($db->quoteName(array('payment_params')));
            $query->from($db->quoteName('#__virtuemart_paymentmethods'));
            $query->where($db->quoteName('virtuemart_paymentmethod_id') . '=' . $db->quote($payment_id));
            $db->setQuery($query, 0, 1);
            $result = $db->loadRow();

            if (count($result[0]) > 0) {
                $payment_params = array();
                foreach (explode("|", $result[0]) as $payment_param) {
                    if (empty($payment_param)) {
                        continue;
                    }
                    $param = explode('=', $payment_param);
                    $payment_params[$param[0]] = substr($param[1], 1, -1);
                }
                $this->paymentConfigs = $payment_params;
            }
        }

        return $this->paymentConfigs;
    }

    private function getUserProfileData($orderInfo)
    {
        return array(
            'customer[city]' => $orderInfo->city,
            'customer[state]' => $orderInfo->virtuemart_state_id,
            'customer[address]' => $orderInfo->address_1,
            'customer[country]' => $orderInfo->virtuemart_country_id,
            'customer[zip]' => $orderInfo->zip,
            'customer[username]' => $orderInfo->virtuemart_user_id,
            'customer[firstname]' => $orderInfo->first_name,
            'customer[lastname]' => $orderInfo->last_name,
            'email' => $orderInfo->email,
        );
    }       

    /**
     * Extends the standard function in vmplugin. Extendst the input data by virtuemart_order_id
     * Calls the parent to execute the write operation
     *
     * @param $values
     * @param int $primaryKey
     * @param bool $preload
     * @return array
     * @internal param array $_values
     * @internal param string $_table
     */
    protected function storePSPluginInternalData($values, $primaryKey = 0, $preload = FALSE) 
    {

        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
        }
        if (!isset($values['virtuemart_order_id'])) {
            $values['virtuemart_order_id'] = VirtueMartModelOrders::getOrderIdByOrderNumber($values['order_number']);
        }
        return $this->storePluginInternalData($values, $primaryKey, 0, $preload);
    }

    /**
     * @param $order
     * @param $cart
     * @return array
     */
    public function prepareTransactionData($order, $cart) {
        // Prepare data that should be stored in the database
        return array(
            'order_number' => $order['details']['BT']->order_number,
            'payment_name' => $this->_currentMethod->payment_name,
            'virtuemart_paymentmethod_id' => $cart->virtuemart_paymentmethod_id,
            'cost_per_transaction' => $this->_currentMethod->cost_per_transaction,
            'cost_percent_total' => $this->_currentMethod->cost_percent_total,
            'payment_currency' => $this->_currentMethod->payment_currency,
            'payment_order_total' => $order['details']['BT']->order_total,
            'tax_id' => $this->_currentMethod->tax_id,
        );
    }

	protected function renderPluginName($activeMethod) {				
		$return = '';
		$plugin_name = $this->_psType . '_name';
		$plugin_desc = $this->_psType . '_desc';		
		$description = '';
		// 		$params = new JParameter($plugin->$plugin_params);
		// 		$logo = $params->get($this->_psType . '_logos');
		$logosFieldName = $this->_psType . '_logos';
		$logos = $activeMethod->$logosFieldName;		
		if (!empty($logos)) {			
			$return .= $this->displayLogos($logos) . ' ';			
		}
		$pluginName = $return . '<span class="' . $this->_type . '_name">' . $activeMethod->$plugin_name . '</span>';		
		if (!empty($activeMethod->$plugin_desc)) {
			$pluginName .= '<span class="' . $this->_type . '_description">' . $activeMethod->$plugin_desc . '</span>';
		}		
		//$pluginName .= $this->displayExtraPluginNameInfo($activeMethod);
		return $pluginName;
	}

		
	/**
	 * @param $html
	 * @return bool|null|string
	 */
	function plgVmOnPaymentResponseReceived(&$html) {

		if (!class_exists('VirtueMartCart')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
		}
		if (!class_exists('shopFunctionsF')) {
			require(VMPATH_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
		}
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}
		VmConfig::loadJLang('com_virtuemart_orders', TRUE);				

		// the payment itself should send the parameter needed.
		$virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);		
		$order_number = vRequest::getString('on', 0);
		$vendorId = 0;
		if (!($this->_currentMethod = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			return NULL; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
			return NULL;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return NULL;
		}
		if (!($payments = $this->getDatasByOrderNumber($order_number))) {
			return '';
		}				
		VmConfig::loadJLang('com_virtuemart');
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);

		$this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod,$order['details']['BT']->payment_currency_id);
		$payment_name = $this->renderPluginName($this->_currentMethod);
		$payment = end($payments);		

		// to do: this
		//$this->debugLog($payment, 'plgVmOnPaymentResponseReceived', 'debug', false);
		if (!class_exists('CurrencyDisplay')) {
			require(VMPATH_ADMIN . DS . 'helpers' . DS . 'currencydisplay.php');
		}		
		$currency = CurrencyDisplay::getInstance('', $order['details']['BT']->payment_currency_id);
		
		 if (isset($_GET['resultCode']) && isset($_GET['merchantOrderId']) && isset($_GET['reference']) && $_GET['resultCode'] == '00') {
			$success = true;
		}else if( isset($_GET['resultCode']) && isset($_GET['merchantOrderId']) && isset($_GET['reference']) && $_GET['resultCode'] == '01') {
			$success = true;
		}else {
			$success = false;
		}		
				
		$html = $this->renderByLayout('stdresponse', array(
			"payment_name" => $payment_name,
			"reference" => $_GET['reference'],
			"order" => $order,
			"currency" => $currency,
			"success" => $success,
		));					
			
		//We delete the old stuff
		// get the correct cart / session
		$cart = VirtueMartCart::getCart();
		$cart->emptyCart();
		return TRUE;
	}
	
	function plgVmOnPaymentNotification() {
					
		if (!class_exists('VirtueMartModelOrders')) {
			require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
		}				
				
		if (empty($_REQUEST['resultCode']) || empty($_REQUEST['merchantOrderId']) || empty($_REQUEST['reference'])) {
            return FALSE;
		}				
		
		$order_number = stripslashes($_REQUEST['merchantOrderId']);
		$status = stripslashes($_REQUEST['resultCode']);
		$reference = stripslashes($_REQUEST['reference']);		
		
		//check order id in virtue mart
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return FALSE;
		}		
		
		if (!($payments = $this->getDatasByOrderNumber($order_number))) {
			return FALSE;
		}
		
		$this->_currentMethod = $this->getVmPluginMethod($payments[0]->virtuemart_paymentmethod_id);
		if (!$this->selectedThisElement($this->_currentMethod->payment_element)) {
			return FALSE;
		}								
		
		$orderModel = VmModel::getModel('orders');
		$order = $orderModel->getOrder($virtuemart_order_id);				
		
		$configs = $this->getPaymentConfigs($order['details']['BT']->virtuemart_paymentmethod_id);		
		$merchant_code = $configs['merchant_code'];
		$api_key = $configs['secret_key'];
		$url_endpoint =stripslashes(trim($configs['url_endpoint']));
		
		$this->_currentMethod->payment_currency = $this->getPaymentCurrency($this->_currentMethod,$order['details']['BT']->payment_currency_id);
				
	     if ($order) {			 
			 if ($status == '00' && DuitkuCore_Web::validateTransaction($url_endpoint, $merchant_code, $order_number, $reference, $api_key)) 
			 {				 
				$order_history = array(
					'customer_notified' => 1, //send notification to user
					'virtuemart_order_id' => $virtuemart_order_id,
					'comments' => 'payment with ' . $this->_currentMethod->payment_name . ' was successful',
					'order_status' => $this->_currentMethod->status_success,
				);
				$orderModel->updateStatusForOneOrder($virtuemart_order_id, $order_history, TRUE);
			 } 
			 else 
			 {
				 $order_history = array(
					'customer_notified' => 1, //send notification to user
					'virtuemart_order_id' => $virtuemart_order_id,
					'comments' => 'payment with ' . $this->_currentMethod->payment_name . ' was failed',
					'order_status' => $this->_currentMethod->status_canceled,					
				);
				$orderModel->updateStatusForOneOrder($virtuemart_order_id, $order_history, TRUE);				 
			 }     
		 } 				
	}
}
