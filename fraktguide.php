<?php

class FraktGuide extends CarrierModule {


    private $_carrier_config = array(
		'name' => 'Bring',
                'id_tax_rules_group' => 0,
                'url' => 'http://sporing.posten.no/q=@',
                'active' => true,
                'deleted' => 0,
		'shipping_handling' => false,
		'range_behaviour' => 0,
		'is_module' => true,
		'id_zone' => 1,
		'delay' => array('no' => 'Avhengig av postnummer', 'en' => 'Depdens on postal code'),
                'shipping_external' => true,
		'external_module_name' => 'fraktguide',
		'need_range' => false
    );

    private $_products;

    function __construct() {
        $this->name = 'fraktguide';
        $this->tab = 'shipping_logistics';
        $this->version = '0.1';
   	$this->author = 'Nils-Helge Garli Hegvik';   
   	parent::__construct();
   
  	$this->displayName = $this->l('Bring Fraktguide');
   	$this->description = $this->l('Integrasjon av Bring Fraktguide');
   }

    public function install() {
    	if(!parent::install()) {
	  return false;
	}
    	if(!$this->registerHook('extraCarrier') || !$this->registerHook('processCarrier') || !$this->registerHook('beforeCarrier')) {
      		return false;
    	}
        //foreach($this->_products as $product_id => $product_name) {
        //    if(!$this->createCarrier($this->_carrier_config, $product_id, $product_name)) {
	//	return false;
        //    }
        //}
        if(!$this->createDatabaseTable()) {
           return false;
        } 
        //Configuration::updateValue('FRAKTGUIDE_SERVICEPAKKE', true);
        //Configuration::updateValue('FRAKTGUIDE_PA_DOREN', true);
        //Configuration::updateValue('FRAKTGUIDE_NORGESPAKKE', false);
        Configuration::updateValue('FRAKTGUIDE_EDI', true);
	Configuration::updateValue('FRAKTGUIDE_FORSIKRING', true);
    	Configuration::updateValue('FRAKTGUIDE_FRA_POSTNUMMER', '');
	Configuration::updateValue('FRAKTGUIDE_CARRIER_IDS', '');
	Configuration::updateValue('FRAKTGUIDE_CREATED_CARRIER_IDS', '');
	Configuration::updateValue('FRAKTGUIDE_PRODUCTS', 'SERVICEPAKKE');
	return true;
    }

    private function createDatabaseTable() {
	$sql = 'CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'fraktguide_cart_cache` (
                                  `id_cart` int(10) NOT NULL,
                                  `id_customer` int(10) NOT NULL,
                                  `shipping_cost` double(10,2) NOT NULL,
                                  PRIMARY KEY  (`id_cart`,`id_customer`)
                                ) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8;';
                
                if (!Db::getInstance()->Execute($sql)) {
                        return false;
		}
		else {
			return true;
		}
    }

    public function uninstall() {
	if(!parent::uninstall() OR !Db::getInstance()->Execute('DROP TABLE IF EXISTS `'._DB_PREFIX_.'fraktguide_cart_cache`')
		OR !$this->unregisterHook('extraCarrier')
 		OR !$this->unregisterHook('processCarrier')) {
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
		return true;
	}
    }

    private function createCarrier($config, $product_id) {
	$carrier = new Carrier();
        $carrier->name = $product_id;
        $carrier->id_tax_rules_group = $config['id_tax_rules_group'];
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
        if($carrier->add()) {
	    $carriers_str = Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS');
	    $carriers = $carriers_str ? explode(';', Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS')) : array();
	    $carriers[] = $carrier->id;
	    Configuration::updateValue('FRAKTGUIDE_CREATED_CARRIER_IDS', implode(';', $carriers));
            $zones = Zone::getZones(true);
            foreach($zones as $zone) {
		Db::getInstance()->Execute('INSERT INTO '._DB_PREFIX_.'carrier_zone VALUE(\''.(int)($carrier->id).'\', \''.(int)($zone['id_zone']).'\')');
	    }
            return true;
        }
        else {
	    return false;
        }
    }

    public function getContent() {
       if(Tools::isSubmit('submit')) {
          $edi = Tools::getIsset('fraktguide_edi');
	  $forsikring = Tools::getIsset('fraktguide_forsikring');
	  $frapostnr = Tools::getValue('fraktguide_fra_postnummer');
	  $selected_products = Tools::getIsset('fraktguide_product') ? Tools::getValue('fraktguide_product') : array();
          Configuration::updateValue('FRAKTGUIDE_EDI', $edi);
	  Configuration::updateValue('FRAKTGUIDE_FORSIKRING', $forsikring);
	  Configuration::updateValue('FRAKTGUIDE_FRA_POSTNUMMER', $frapostnr);
	  Configuration::updateValue('FRAKTGUIDE_PRODUCTS', implode(';', $selected_products));
	  $this->createCarriers($selected_products);
	  $this->updateSelectedCarriers($selected_products);
       }
       $this->_displayForm();
       return $this->_html;
    }


	private function updateSelectedCarriers($selected_products) {
		$carriers_by_name = $this->getCarriersByName();
		$ids = array();
		foreach($carriers_by_name as $carrier_name => $carrier_id) {
			if(in_array($carrier_name, $selected_products)) {
				// Set active
				$update_values = array('id_carrier' => (int)$carrier_id, 'active' => true);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier', $update_values, 'UPDATE', '`id_carrier` = '.(int)$carrier_id);
				$ids[] = $carrier_id;
			}
			else {
				// Set disabled
				$update_values = array('active' => false);
				Db::getInstance()->autoExecute(_DB_PREFIX_.'carrier', $update_values, 'UPDATE', '`id_carrier` = '.(int)$carrier_id);
			}
		}
		Configuration::updateValue('FRAKTGUIDE_CARRIER_IDS', implode(';', $ids));
	}

	private function getCarriersByName() {
		$carrier_ids = Configuration::get('FRAKTGUIDE_CREATED_CARRIER_IDS');
		$carriers = $carrier_ids ? explode(';', $carrier_ids) : array();
		$sql = 'SELECT id_carrier, name FROM `'._DB_PREFIX_.'carrier` WHERE id_carrier IN ('.implode(',', $carriers).')';
		$existing_carriers = array();
		$result = Db::getInstance()->ExecuteS($sql);
		if($result) {
			foreach($result as $row) {
				$existing_carriers[$row['name']] = $row['id_carrier'];
			}
		}
		return $existing_carriers;
	}
	
	private function createCarriers($selected_products) {
		$carriers_by_name = $this->getCarriersByName();
		foreach($selected_products as $product) {
			if(!array_key_exists($product, $carriers_by_name)) {
				// Create the carrier
				$this->createCarrier($this->_carrier_config, $product);
			}
		}
	}

	private function getJson($url) {
		$http = curl_init();
        	curl_setopt($http, CURLOPT_URL, $url);
        	curl_setopt($http, CURLOPT_POST, false);
        	curl_setopt($http, CURLOPT_RETURNTRANSFER, true);
        	$raw_json = curl_exec($http);
        	$json_obj = json_decode($raw_json, true);
        	$status = curl_getinfo($http, CURLINFO_HTTP_CODE);
        	curl_close($http);
		return $json_obj;
	}

    private function _displayForm() {
	$forsikring = Configuration::get('FRAKTGUIDE_FORSIKRING');
        $edi = Configuration::get('FRAKTGUIDE_EDI');
	$frapostnr = Configuration::get('FRAKTGUIDE_FRA_POSTNUMMER');
	$products_str = Configuration::get('FRAKTGUIDE_PRODUCTS');
	$selected_products = $products_str ? explode(';', $products_str) : array();
	$this->_html .= '<style>
		.fraktguide_product {
			clear: both;
		}		
	</style>';
	$this->_html .= '<form action="'.$_SERVER['REQUEST_URI'].'" method="POST">';

	if($frapostnr) {
		$url = 'http://fraktguide.bring.no/fraktguide/products/all.json?from='.$frapostnr.'&to=0185&weightInGrams=1000&edi='.($edi ? 'true' : 'false');
		$this->_html .= '<table id="fraktguide_products">
			<tr>
				<th>Navn</th>
				<th>Beskrivelse</th>
				<th>Aktiver</th>
			</tr>';
		$products = $this->getJson($url);
		foreach($products["Product"] as $product) {
			$id = $product["ProductId"];
			$name = $product["GuiInformation"]["ProductName"];
			$desc = $product["GuiInformation"]["HelpText"];
			$this->_html .= '<tr>
				<td><label for="fraktguide_product_'.$id.'">'.$name.'</label></td>
				<td>'.$desc.'</td>
				<td><input type="checkbox" id="fraktguide_product_'.$id.'" name="fraktguide_product[]" value="'.$id.'"'.(in_array($id, $selected_products) ? ' checked' : '').'></td>
			</tr>';
		}
		$this->_html .= '</table>';
	}

	$this->_html .= '
		<div style="clear: both;">
               <span><label for="fraktguide_edi">'.$this->l('Bruk EDI').'</label></span><span><input type="checkbox" id="fraktguide_edi" name="fraktguide_edi" value="true"'.($edi ? ' checked' : '').'></span>
             </div>
	     <div style="clear: both;">
		<span><label for="fraktguide_forsikring">'.$this->l('Forsikring').'</label></span><span><input type="checkbox" id="fraktguide_forsikring" name="fraktguide_forsikring" value="true"'.($forsikring ? ' checked' : '').'></span>
	     </div>    
	     <div style="clear: both";>
		<span><label for="fraktguide_fra_postnummer">'.$this->l('Fra postnummer').'</label></span><span><input type="text" id="fraktguide_fra_postnummer" name="fraktguide_fra_postnummer" value="'.$frapostnr.'"></span>
	     </div>
		<div style="clear: both; ">
         	<input type="submit" name="submit" value="'.$this->l('Oppdater').'">
        	</div>  
	 </form>
        ';
    }

    public function hookExtraCarrier($params) {
        $opc = Configuration::get("PS_ORDER_PROCESS_TYPE");
	$address = $params['address'];
        $postcode = $address->postcode;
        $edi = Configuration::get('FRAKTGUIDE_EDI');
	$cart = $params['cart'];
        $cart_weight = ($cart->getTotalWeight() * 1000 > 0.0 ? $cart->getTotalWeight() * 1000 : 6000);
        $url = "http://fraktguide.bring.no/fraktguide/products/all.json?from=".Configuration::get('FRAKTGUIDE_FRA_POSTNUMMER')."&to=$postcode&weightInGrams=$cart_weight&edi=".($edi ? 'true' : 'false');
	$products_str = Configuration::get('FRAKTGUIDE_PRODUCTS');
	$selected_products = $products_str ? explode(';', Configuration::get('FRAKTGUIDE_PRODUCTS')) : array();
	foreach($selected_products as $selected_product) {
		$url .= '&product='.$selected_product;
	}
        $products_json = $this->getJson($url);
        $products = $products_json["Product"];
        $html = '';
	$forsikring = Configuration::get('FRAKTGUIDE_FORSIKRING');
        // The format of the json is different if there are one or if there are more products
        if(count($products) > 0 && !is_array($products[0])) {
            $products = array($products);
        }
	$carriers_by_name = $this->getCarriersByName();
        foreach($products as $product) {
            $productId = $product["ProductId"];
       	    $productName = $product["GuiInformation"]["ProductName"];
            $productText = $product["GuiInformation"]["DisplayName"];
            $carrier_id = $carriers_by_name[$productId];
            $price = $product["Price"]["PackagePriceWithoutAdditionalServices"]["AmountWithVAT"];
            if($forsikring) {
                $order_total = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING);
                $forsikring = ($order_total * 0.003 < 30 ? 30 : ($order_total > 25000 ? 25000 * 0.003 : $order_total * 0.003));
                $price += $forsikring;
            }
	    $html .= "<tr class='item'>\n<td class='carrier_action radio'><input type='radio' name='id_carrier' id='$productId' value='$carrier_id'".($opc ? ' onclick="updateCarrierSelectionAndGift();"' : '')."></td><td class='carrier_name'><label for='$productId'>$productName</label></td><td class='carrier_infos'>$productText</td><td class='carrier_price'><span class='price'>$price kr</span></td></tr></tr>";
        }
        return "<tr>$html</tr>";
    }

	private function getProductForCarrier($carrier_id) {
		$sql = "SELECT name FROM `"._DB_PREFIX_."carrier` WHERE `id_carrier` = ".$carrier_id;
		$result = Db::getInstance()->ExecuteS($sql);
		return $result[0]['name'];
	}

    public function hookProcessCarrier($params) {
        $cart = $params["cart"];
        $cust_id = $cart->id_customer;
        $carrier = $cart->id_carrier;
        $address = new Address((int)$cart->id_address_delivery);
        $weight = $cart->getTotalWeight() * 1000 > 0.0 ? $cart->getTotalWeight() * 1000 : 5000;
        $product_id = $this->getProductForCarrier($carrier);
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
	if($forsikring) {
                $order_total = $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS_WITHOUT_SHIPPING);
                $forsikring = ($order_total * 0.003 < 30 ? 30 : ($order_total > 25000 ? 25000 * 0.003 : $order_total * 0.003));
                $shipping_cost += $forsikring;
        }
	$update_values = array("id_cart" => (int)$cart->id, "id_customer" => (int)$cust_id, "shipping_cost" => floatval($shipping_cost));
	$row = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.'fraktguide_cart_cache` WHERE `id_cart` = '.(int)$cart->id.' AND `id_customer` = '.(int)$cust_id);
	$op = '';
	if($row) {
	     $op = "UPDATE";
        }
	else {
	     $op = "INSERT";
	}
        Db::getInstance()->autoExecute(_DB_PREFIX_.'fraktguide_cart_cache', $update_values, $op);
	
    }
	
    public function hookBeforeCarrier() {
	return '';
    }

    public function getOrderShippingCost($params, $shipping_cost) {
	return $shipping_cost;
    }

    public function getOrderShippingCostExternal($cart) {
	return Db::getInstance()->getValue('SELECT shipping_cost FROM `'._DB_PREFIX_.'fraktguide_cart_cache` WHERE `id_cart` = '.(int)$cart->id.' AND `id_customer` = '.(int)$cart->id_customer);
    }
}

?>
