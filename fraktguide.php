<?php

class FraktGuide extends CarrierModule {


    private $_carrier_config = array(
		'name' => 'Bring',
                'url' => 'http://sporing.bring.no/sporing.html?q=@',
                'active' => true,
                'deleted' => 0,
		'shipping_handling' => false,
		'range_behaviour' => 0,
		'is_module' => true,
		'id_zone' => 1,
		'delay' => array('no' => 'Avhengig av postnummer', 'en' => 'Depends on postal code'),
                'shipping_external' => true,
		'external_module_name' => 'fraktguide',
		'need_range' => true
    );

    private $_products;

    public $id_carrier;

    function __construct() {
        $this->name = 'fraktguide';
        $this->tab = 'shipping_logistics';
        $this->version = '0.10.5';
        $this->author = 'Nils-Helge Garli Hegvik';
	$this->module_key = '5191156334d29ca0c5d3f70c80e8ba38';
        parent::__construct();

        $this->displayName = $this->l('Bring Fraktguide');
        $this->description = $this->l('Integrasjon av Bring Fraktguide');
   }

    public function install() {
    	if(!parent::install()) {
	        return false;
	    }
    	if(!$this->registerHook('actionCarrierUpdate')) {
      		return false;
    	}
	if(!$this->createDatabaseTable()) {
		return false;
	}
        Configuration::updateValue('FRAKTGUIDE_EDI', true);
	    Configuration::updateValue('FRAKTGUIDE_FORSIKRING', true);
    	Configuration::updateValue('FRAKTGUIDE_FRA_POSTNUMMER', '');
	    Configuration::updateValue('FRAKTGUIDE_CARRIER_IDS', '');
	    Configuration::updateValue('FRAKTGUIDE_CREATED_CARRIER_IDS', '');
	    Configuration::updateValue('FRAKTGUIDE_PRODUCTS', 'SERVICEPAKKE');
	    Configuration::updateValue('FRAKTGUIDE_A_POST_MAX_PRIS', '');
	    Configuration::updateValue('FRAKTGUIDE_DEBUG_MODE', false);
	    return true;
    }

    public function uninstall() {
	if(!parent::uninstall() OR !$this->unregisterHook('actionCarrierUpdate') OR !Db::getInstance()->Execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'fraktguide_product_names`')) {
		return false;
	}
	else {
		$carrier_ids_str = Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS');
		$carrier_ids = $carrier_ids_str ? explode(';', Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS')) : array();
		foreach($carrier_ids as $carrier_id) {
                   $carrier = new Carrier((int)$carrier_id);
                   $carrier->deleted = 1;
		   if(!$carrier->update()) {
			return false;
		   }
		}
		Configuration::deleteByName('FRAKTGUIDE_CREATED_CARRIER_IDS');
                Configuration::deleteByName('FRAKTGUIDE_CARRIER_IDS');
		Configuration::deleteByName('FRAKTGUIDE_PRODUCTS');
		Configuration::deleteByName('FRAKTGUIDE_EDI');
		Configuration::deleteByName('FRAKTGUIDE_DEBUG_MODE');
		Configuration::deleteByName('FRAKTGUIDE_A_POST_MAX_PRIS');
		Configuration::deleteByName('FRAKTGUIDE_FRA_POSTNUMMER');
		Configuration::deleteByName('FRAKTGUIDE_FORSIKRING');
		return true;
	}
    }

	private function createDatabaseTable() {
		$sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'fraktguide_product_names` (
				`id_carrier` varchar(255) NOT NULL,
				`product_id` varchar(255) NOT NULL,
				PRIMARY KEY (`id_carrier`)
		   ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';
	    	return Db::getInstance()->Execute($sql);
    	}

    private function createCarrier($config, $product_id, $name, $debug_mode = false, $debug_info = array()) {
	    $carrier = new Carrier();
	if($debug_mode) {
		array_push($debug_info, "Trying to create carrier ".$product_id." with name ".$name);
	}
        $carrier->name = $name;
        $carrier->id_zone = $config['id_zone'];
        $carrier->url = $config['url'];
        $carrier->active = $config['active'];
        $carrier->deleted = $config['deleted'];
	    $carrier->shipping_handling = $config['shipping_handling'];
        $carrier->range_behaviour = $config['range_behaviour'];
        $carrier->is_module = $config['is_module'];
        $carrier->shipping_external = $config['shipping_external'];
        $carrier->external_module_name = $config['external_module_name'];
        $carrier->need_range = $config['need_range'];
	    $languages = Language::getLanguages(true);
        foreach ($languages as $language) {   
            if ($language['iso_code'] == 'en')
                $carrier->delay[$language['id_lang']] = $config['delay'][$language['iso_code']];
	        if ($language['iso_code'] == 'no')
                $carrier->delay[$language['id_lang']] = $config['delay'][$language['iso_code']];
	    }
	if($debug_mode) {
		array_push($debug_info, "Adding carrier");
	}
        if($carrier->add()) {
	   	if($debug_mode) {
			array_push($debug_info, "Carrier added, setting up associations");
		}
	    $carriers_str = Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS');
	    $carriers = $carriers_str ? explode(';', Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS')) : array();
	    $carriers[] = $carrier->id;
	    Configuration::updateValue('FRAKTGUIDE_CREATED_CARRIER_IDS', implode(';', $carriers));
            $zones = Zone::getZones(true);
            foreach($zones as $zone) {
		Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'carrier_zone VALUE(\''.(int)($carrier->id).'\', \''.(int)($zone['id_zone']).'\')');
	    }

	    $groups = Group::getgroups(true);

            foreach ($groups as $group)
                Db::getInstance()->execute('INSERT INTO ' . _DB_PREFIX_ . 'carrier_group VALUE (\'' . (int) ($carrier->id) . '\',\'' . (int) ($group['id_group']) . '\')');

            $rangePrice = new RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '10000';
            $rangePrice->add();

            $rangeWeight = new RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '10000';
            $rangeWeight->add();
	    if($debug_mode) {
		array_push($debug_info, "Trying to update product name table");
		}
		if(!Db::getInstance()->Execute('INSERT INTO `'._DB_PREFIX_.'fraktguide_product_names`(`id_carrier`, `product_id`) VALUES('.$carrier->id.', \''.$product_id.'\')')) {
			return false;
	    	}

	    if (!copy(dirname(__FILE__) . '/img/logo.png', _PS_SHIP_IMG_DIR_ . '/' . $carrier->id . '.jpg'))
                return false;
		if($debug_mode) {
			array_push($debug_info, "Success");
		}	
            return true;
        }
        else {
	    Tools::dieOrLog("Error creating carrier ".$product_id);
	    return false;
        }
    }

	public function getContent() {
       		if(Tools::isSubmit('submit')) {
          		$edi = Tools::getIsset('fraktguide_edi');
          		$forsikring = Tools::getIsset('fraktguide_insurance');
          		$frapostnr = Tools::getValue('fraktguide_postal_code');
          		$selected_products = Tools::getIsset('fraktguide_product') ? Tools::getValue('fraktguide_product') : array();
          		$max_price = Tools::getValue('fraktguide_a_post_max_price');
			$debug_mode = Tools::getIsset('fraktguide_debug_mode');
         		Configuration::updateValue('FRAKTGUIDE_EDI', $edi);
          		Configuration::updateValue('FRAKTGUIDE_FORSIKRING', $forsikring);
          		Configuration::updateValue('FRAKTGUIDE_FRA_POSTNUMMER', $frapostnr);
          		Configuration::updateValue('FRAKTGUIDE_PRODUCTS', implode(';', $selected_products));
          		Configuration::updateValue('FRAKTGUIDE_A_POST_MAX_PRIS', $max_price);
			Configuration::updateValue('FRAKTGUIDE_DEBUG_MODE', $debug_mode);
			$debug_info = array();
          		$this->createCarriers($selected_products, $debug_mode, &$debug_info);
          		$this->updateSelectedCarriers($selected_products, $debug_mode, &$debug_info);
			$this->_displayForm(true, $debug_mode, &$debug_info);
       		}
		else {
       			$this->_displayForm(false, Configuration::get('FRAKTGUIDE_DEBUG_MODE'));
		}
       		return $this->_html;
    	}

	private function updateSelectedCarriers($selected_products, $debug_mode = false, $debug_info = array()) {
		$carriers_by_name = $this->getCarriersByName();
		if($debug_mode) {
			array_push($debug_info, "Carriers by name: ".print_r($carriers_by_name, true));
		}
		$ids = array();
		foreach($carriers_by_name as $carrier_name => $carrier_id) {
			if(in_array($carrier_name, $selected_products)) {
				if($debug_mode) {
					array_push($debug_info, "Carrier ".$carrier_name." (".$carrier_id.") selected. Enabling");
				}
				// Set active
				$update_values = array('active' => 1);
				Db::getInstance()->update('carrier', $update_values, '`id_carrier` = '.(int)$carrier_id);
				$ids[] = $carrier_id;
			}
			else {
				// Set disabled
				if($debug_info) {
					array_push($debug_info, "Carrier ".$carrier_name." (".$carrier_id.") not selected. Disabling");
				}
				$update_values = array('active' => 0);
				Db::getInstance()->update('carrier', $update_values, '`id_carrier` = '.(int)$carrier_id);
			}
		}
		if($debug_mode) {
			array_push($debug_info, "New list of selected carriers: ".print_r($ids, true));
		}
		Configuration::updateValue('FRAKTGUIDE_CARRIER_IDS', implode(';', $ids));
	}

	private function getCarriersByName() {
		$carrier_ids = Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS');
		$carriers = $carrier_ids ? explode(';', $carrier_ids) : array();
        $existing_carriers = array();
        if(count($carriers) > 0) {
            $sql = 'SELECT id_carrier, product_id FROM `'._DB_PREFIX_.'fraktguide_product_names` WHERE id_carrier IN ('.implode(',', $carriers).')';
            $result = Db::getInstance()->ExecuteS($sql);
            if($result) {
                foreach($result as $row) {
                    $existing_carriers[$row['product_id']] = $row['id_carrier'];
                }
            }
        }
		return $existing_carriers;
	}

	private function createCarriers($selected_products, $debug_mode = false, $debug_info = array()) {
		$carriers_by_name = $this->getCarriersByName();
		if($debug_mode) {
			array_push($debug_info, "Carriers by name: ".print_r($carriers_by_name, true));
		}
		foreach($selected_products as $product) {
			if(!array_key_exists($product, $carriers_by_name)) {
				// Create the carrier
				if($debug_mode) {
					array_push($debug_info, "Carrier ".$product." does not exist. Creating");
				}
				$name = Tools::getValue('fraktguide_product_'.$product.'_name');
				$this->createCarrier($this->_carrier_config, $product, $name, $debug_mode, &$debug_info);
			}
		}
	}

	private function getJson($url, $debug_mode = false, $debug_info = null) {
		$http = curl_init();
        	curl_setopt($http, CURLOPT_URL, $url);
        	curl_setopt($http, CURLOPT_POST, false);
        	curl_setopt($http, CURLOPT_RETURNTRANSFER, true);
        	$raw_json = curl_exec($http);
		if($debug_mode) {
			array_push($debug_info, "Response from service: ".$raw_json);
		}
		$status = curl_getinfo($http, CURLINFO_HTTP_CODE);
		curl_close($http);
		if($status == 200) {
        		return json_decode($raw_json, true);
         	}
		else {
			return false;
		}		
	}

    private function _displayForm($updated, $debug_mode = false, $debug_info = array()) {
	$forsikring = Configuration::get('FRAKTGUIDE_FORSIKRING');
    $edi = Configuration::get('FRAKTGUIDE_EDI');
	$frapostnr = Configuration::get('FRAKTGUIDE_FRA_POSTNUMMER');
	$products_str = Configuration::get('FRAKTGUIDE_PRODUCTS');
	$selected_products = $products_str ? explode(';', $products_str) : array();
	$max_price = Configuration::get('FRAKTGUIDE_A_POST_MAX_PRIS');
        $products = array();
        $product_descriptions = array();
        $error = null;
	if($debug_mode) {
		array_push($debug_info, "Starting display of configuration");
	}	

    if($frapostnr) {
		$url = 'http://fraktguide.bring.no/fraktguide/products/all.json?from='.$frapostnr.'&to=0185&weightInGrams=1000&edi='.($edi ? 'true' : 'false');
		if($debug_mode) {
			array_push($debug_info, "Requesting url: ".$url);
		}
		$json_products = $this->getJson($url, $debug_mode, &$debug_info);
		if($debug_mode) {
			array_push($debug_info, "JSON response: ".print_r($json_products, true));
		}
		if($json_products) {
			foreach($json_products["Product"] as $json_product) {
            
				$id = $json_product["ProductId"];
				$name = $json_product["GuiInformation"]["ProductName"];
				$desc = $json_product["GuiInformation"]["HelpText"];
            
            			$products[$id] = $name;
            			$product_descriptions[$id] = $desc;
			}
			if($debug_mode) {
				array_push($debug_info, "Products: ".print_r($products, true));
				array_push($debug_info, "Product descriptions: ".print_r($product_descriptions, true));
			}
		}
		else {
			$error = $this->l("Feil ved uthenting av produkter");
		}
	}
        $this->context->smarty->assign(array(
					'error' => $error,
					'updated' => $updated,
                                            'fraktguide_edi' => $edi,
                                            'fraktguide_a_post_max_price' => $max_price,
                                            'fraktguide_insurance' => $forsikring,
                                            'fraktguide_postal_code' => $frapostnr,
                                            'fraktguide_products' => $products,
                                            'fraktguide_product_descriptions' => $product_descriptions,
					'fraktguide_selected_products' => $selected_products,
					'fraktguide_debug_mode' => $debug_mode,
					'fraktguide_debug_info' => $debug_info					    
        ));
        $this->_html = $this->display(__FILE__, "templates/config.tpl");
    }

	private function getProductForCarrier($carrier_id) {
		$sql = "SELECT product_id FROM `"._DB_PREFIX_."fraktguide_product_names` WHERE `id_carrier` = ".$carrier_id;
		$result = Db::getInstance()->ExecuteS($sql);
		return $result[0]['product_id'];
	}

    public function getOrderShippingCost($cart, $shipping_cost) {
        $address = new Address((int)$cart->id_address_delivery);
        $weight = $cart->getTotalWeight() * 1000 > 0.0 ? $cart->getTotalWeight() * 1000 : 5000;
        $product_id = $this->getProductForCarrier($this->id_carrier);
	$forsikring = Configuration::get('FRAKTGUIDE_FORSIKRING');
	$url = "http://fraktguide.bring.no/fraktguide/products/$product_id/price.json?from=".Configuration::get('FRAKTGUIDE_FRA_POSTNUMMER')."&to=$address->postcode&weightInGrams=$weight&edi=".(Configuration::get('FRAKTGUIDE_EDI') ? 'true' : 'false');
	$http = curl_init();
	curl_setopt($http, CURLOPT_URL, $url);
        curl_setopt($http, CURLOPT_POST, false);
        curl_setopt($http, CURLOPT_RETURNTRANSFER, true);
        $fraktguide_json = curl_exec($http);
	$json_obj = json_decode($fraktguide_json, true);
        $status = curl_getinfo($http, CURLINFO_HTTP_CODE);
        curl_close($http);
        $shipping_cost = $json_obj["Product"]["Price"]["PackagePriceWithoutAdditionalServices"]["AmountWithVAT"];
	if($shipping_cost) {
	    if($forsikring) {
                $order_total = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING);
                $forsikring = ($order_total * 0.003 < 30 ? 30 : ($order_total > 25000 ? 25000 * 0.003 : $order_total * 0.003));
                $shipping_cost += $forsikring;
            }
	    return $shipping_cost;
        }
        else
            return false; // Indicates that carrier is not available due to size/weight restrictions
    }

    public function getOrderShippingDelay($cart) {
	$address = new Address((int)$cart->id_address_delivery);
        $weight = $cart->getTotalWeight() * 1000 > 0.0 ? $cart->getTotalWeight() * 1000 : 5000;
        $product_id = $this->getProductForCarrier($this->id_carrier);
        $forsikring = Configuration::get('FRAKTGUIDE_FORSIKRING');
        $url = "http://fraktguide.bring.no/fraktguide/products/$product_id/expectedDelivery.json?from=".Configuration::get('FRAKTGUIDE_FRA_POSTNUMMER')."&to=$address->postcode&weightInGrams=$weight&edi=".(Configuration::get('FRAKTGUIDE_EDI') ? 'true' : 'false');
        $http = curl_init();
        curl_setopt($http, CURLOPT_URL, $url);
        curl_setopt($http, CURLOPT_POST, false);
        curl_setopt($http, CURLOPT_RETURNTRANSFER, true);
        $fraktguide_json = curl_exec($http);
        $json_obj = json_decode($fraktguide_json, true);
        $status = curl_getinfo($http, CURLINFO_HTTP_CODE);
        curl_close($http);
	$expected_delivery = $json_obj["Product"]["ExpectedDelivery"]["WorkingDays"];
	return sprintf("%d arbeidsdag(er)", $expected_delivery);
    }

	public function hookActionCarrierUpdate($params) {
		$carrier_ids = explode(';', Configuration::get('FRAKTGUIDE_CARRIER_IDS'));
		$created_carrier_ids = explode(';', Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS'));
		$old_id = $params['id_carrier'];
		if(in_array($old_id, $carrier_ids) || in_array($old_id, $created_carrier_ids)) {
			$new_carrier = $params['carrier'];
			for($i = 0; $i < count($carrier_ids); $i++) {
				if($carrier_ids[$i] == $old_id) {
					$carrier_ids[$i] = $new_carrier->id;
				}
			}
			for($j = 0; $j < count($created_carrier_ids); $j++) {
				if($created_carrier_ids[$j] == $old_id) {
					$created_carrier_ids[$j] = $new_carrier->id;
				}
			}
			Configuration::updateValue('FRAKTGUIDE_CARRIER_IDS', implode(';', $carrier_ids));
			Configuration::updateValue('FRAKTGUIDE_CREATED_CARRIER_IDS', implode(';', $created_carrier_ids));
			$sql = "UPDATE `'._DB_PREFIX_.'fraktguide_product_names` SET `id_carrier` = ".$new_carrier->id." WHERE `id_carrier` = ".$old_id;
			Db::getInstance()->Execute($sql); 
		}
		
	}

	public function getOrderShippingCostExternal($params) {
		return $this->getOrderShippingCost($params, 0);
	}

}

?>

