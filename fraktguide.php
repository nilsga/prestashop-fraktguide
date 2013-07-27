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
		'delay' => array('no' => 'Avhengig av postnummer', 'en' => 'Depdens on postal code'),
                'shipping_external' => true,
		'external_module_name' => 'fraktguide',
		'need_range' => true
    );

    private $_products;

    public $id_carrier;

    function __construct() {
        $this->name = 'fraktguide';
        $this->tab = 'shipping_logistics';
        $this->version = '0.10';
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
	    Configuration::updateValue('FRAKTGUIDE_A_POST_MAX_PRIS', null);
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

	    if (!copy(dirname(__FILE__) . '/img/logo.png', _PS_SHIP_IMG_DIR_ . '/' . $carrier->id . '.jpg'))
                return false;
	
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
          $max_price = Tools::getValue('fraktguide_a_post_max_pris');
          Configuration::updateValue('FRAKTGUIDE_EDI', $edi);
          Configuration::updateValue('FRAKTGUIDE_FORSIKRING', $forsikring);
          Configuration::updateValue('FRAKTGUIDE_FRA_POSTNUMMER', $frapostnr);
          Configuration::updateValue('FRAKTGUIDE_PRODUCTS', implode(';', $selected_products));
          Configuration::updateValue('FRAKTGUIDE_A_POST_MAX_PRIS', $max_price);
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
        $existing_carriers = array();
        if(count($carriers) > 0) {
            $sql = 'SELECT id_carrier, name FROM `'._DB_PREFIX_.'carrier` WHERE id_carrier IN ('.implode(',', $carriers).')';
            $result = Db::getInstance()->ExecuteS($sql);
            if($result) {
                foreach($result as $row) {
                    $existing_carriers[$row['name']] = $row['id_carrier'];
                }
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
	$max_price = Configuration::get('FRAKTGUIDE_A_POST_MAX_PRICE');
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
	     <div style="clear: both";>
                <span><label for="fraktguide_a_post_max_pris">'.$this->l('Maks ordrepris for A-post').'</label></span><input type="text" id="fraktguide_a_post_max_pris" name="fraktguide_a_post_max_pris" value="'.$max_price.'"></span>
             </div>
	     <div style="clear: both; ">
         	<input type="submit" name="submit" value="'.$this->l('Oppdater').'">
        	</div>  
	 </form>
        ';
    }

	private function getProductForCarrier($carrier_id) {
		$sql = "SELECT name FROM `"._DB_PREFIX_."carrier` WHERE `id_carrier` = ".$carrier_id;
		$result = Db::getInstance()->ExecuteS($sql);
		return $result[0]['name'];
	}

    public function hookProcessCarrier($params) {
        $cart = $params["cart"];
	if(in_array($cart->id_carrier, split(";", Configuration::get('FRAKTGUIDE_CARRIER_IDS')))) {
                $cust_id = $cart->id_customer;
                $shipping_cost = $this->getShippingCost($cart);
                $update_values = array("id_cart" => (int)$cart->id, "id_customer" => (int)$cust_id, "shipping_cost" => floatval($shipping_cost));
                $row = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.'fraktguide_cart_cache` WHERE `id_cart` = '.(int)$cart->id.' AND `id_customer` = '.(int)$cust_id);
                if($row) {
                        Db::getInstance()->autoExecute(_DB_PREFIX_.'fraktguide_cart_cache', $update_values, 'UPDATE', 'id_cart = ' . (int)$cart->id);
                }
                else {
                       Db::getInstance()->autoExecute(_DB_PREFIX_.'fraktguide_cart_cache', $update_values, 'INSERT');
                }
        }
    }
	
    public function hookBeforeCarrier() {
	return '';
    }

    public function getShippingCost($cart) {
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

    public function getOrderShippingCost($params, $shipping_cost) {
	return $this->getShippingCost($params); 
    }

    public function getOrderShippingCostExternal($cart) {
	$shipping_cost = Db::getInstance()->getValue('SELECT shipping_cost FROM `'._DB_PREFIX_.'fraktguide_cart_cache` WHERE `id_cart` = '.(int)$cart->id.' AND `id_customer` = '.(int)$cart->id_customer);
	if(!$shipping_cost) $shipping_cost = $this->getShippingCost($cart);
	return $shipping_cost;
    }
}

?>

