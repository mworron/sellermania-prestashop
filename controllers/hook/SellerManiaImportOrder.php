<?php
/*
* 2010 - 2014 Sellermania / Froggy Commerce / 23Prod SARL
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to team@froggy-commerce.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade your module to newer
* versions in the future.
*
*  @author Fabien Serny - Froggy Commerce <team@froggy-commerce.com>
*  @copyright	2010-2014 Sellermania / Froggy Commerce / 23Prod SARL
*  @version		1.0
*  @license		http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

if (!defined('_PS_VERSION_'))
	exit;


class SellerManiaImportOrderController
{
	public $data;

	public $id_lang;
	public $customer;
	public $address;
	public $cart;
	public $order;

	public $country_iso_match_cache = array();

	/**
	 * Controller constructor
	 */
	public function __construct($module, $dir_path, $web_path)
	{
		$this->module = $module;
		$this->web_path = $web_path;
		$this->dir_path = $dir_path;
		$this->context = Context::getContext();
	}


	/**
	 * Import order
	 * @param $data
	 */
	public function run($data)
	{
		$this->data = $data;
		$this->preprocessData();
		$this->createCustomer();
		$this->createAddress();
		$this->createCart();
		$this->createOrder();
		$this->saveSellermaniaOrder();
	}


	/**
	 * Preprocess data array
	 */
	public function preprocessData()
	{
		// Forbidden characters
		$forbidden_characters = array('_', '/', '(', ')', '*');

		// Fix name
		$this->data['User'][0]['OriginalName'] = $this->data['User'][0]['Name'];
		$this->data['User'][0]['Name'] = str_replace($forbidden_characters, ' ', $this->data['User'][0]['Name']);
		$this->data['User'][0]['Name'] = preg_replace('/[0-9]+/', '', $this->data['User'][0]['Name']);
		if (strpos($this->data['User'][0]['Name'], '/'))
		{
			$name = explode('/', $this->data['User'][0]['Name']);
			$name[1] = trim($name[1]);
			if (!empty($name[1]))
				$this->data['User'][0]['Name'] = $name[1];
		}

		// Retrieve firstname and lastname
		$names = explode(' ', trim($this->data['User'][0]['Name']));
		$firstname = $names[0];
		if (isset($names[1]) && !empty($names[1]) && count($names) == 2)
			$lastname = $names[1];
		else
		{
			$lastname = $this->data['User'][0]['Name'];
			$lastname = str_replace($firstname.' ', '', $lastname);
		}

		// Retrieve shipping phone
		$shipping_phone = '';
		if (isset($this->data['User'][0]['Address']['ShippingPhone']) && !empty($this->data['User'][0]['Address']['ShippingPhone']))
			$shipping_phone = $this->data['User'][0]['Address']['ShippingPhone'];
		if (isset($this->data['User'][0]['ShippingPhone']) && !empty($this->data['User'][0]['ShippingPhone']))
			$shipping_phone = $this->data['User'][0]['ShippingPhone'];
		if (isset($this->data['User'][0]['UserPhone']) && !empty($this->data['User'][0]['UserPhone']))
			$shipping_phone = $this->data['User'][0]['UserPhone'];

		// Retrieve currency
		$currency_iso_code = 'EUR';
		if (isset($this->data['OrderInfo']['Amount']['Currency']) && Currency::getIdByIsoCode($this->data['OrderInfo']['Amount']['Currency']) > 0)
			$currency_iso_code = $this->data['OrderInfo']['Amount']['Currency'];

		// Refill data
		$this->data['User'][0]['FirstName'] = substr($firstname, 0, 32);
		$this->data['User'][0]['LastName'] = substr($lastname, 0, 32);
		$this->data['User'][0]['Address']['ShippingPhonePrestaShop'] = '0100000000';
		if (!empty($shipping_phone))
			$this->data['User'][0]['Address']['ShippingPhonePrestaShop'] = substr(str_replace($forbidden_characters, ' ', $shipping_phone), 0, 16);
		$this->data['OrderInfo']['Amount']['Currency'] = $currency_iso_code;

		// Set currency sign
		$id_currency = (int)Currency::getIdByIsoCode($this->data['OrderInfo']['Amount']['Currency']);
		$currency_object = new Currency($id_currency);
		$this->data['OrderInfo']['Amount']['CurrencySign'] = $currency_object->sign;

		// Retrieve from cache
		$country_key = 'FR';
		if (isset($this->data['User'][0]['Address']['Country']))
		{
			$country_key = $this->data['User'][0]['Address']['Country'];
			if (isset($this->country_iso_match_cache[$country_key]))
				$this->data['User'][0]['Address']['Country'] = $this->country_iso_match_cache[$country_key];
			else
			{
				// Set match with exception reservations
				$country_exceptionnal_iso_code = array('FX' => 'FR', 'FRA' => 'FR', 'France' => 'FR');
				if (isset($country_exceptionnal_iso_code[$this->data['User'][0]['Address']['Country']]))
					$this->data['User'][0]['Address']['Country'] = $country_exceptionnal_iso_code[$this->data['User'][0]['Address']['Country']];
				else
				{
					// Check if there is a match with a country
					$id_country = Country::getIdByName(null, $this->data['User'][0]['Address']['Country']);
					if ($id_country > 0)
						$this->data['User'][0]['Address']['Country'] = Country::getIsoById($id_country);

					// If Iso is not known, we set FR
					if (!Validate::isLanguageIsoCode($this->data['User'][0]['Address']['Country']) || Country::getByIso($this->data['User'][0]['Address']['Country']) < 1)
						$this->data['User'][0]['Address']['Country'] = 'FR';
				}

				// Set cache
				$this->country_iso_match_cache[$country_key] = $this->data['User'][0]['Address']['Country'];
			}
		}


		// Fix address
		$this->data['User'][0]['Company'] = substr(str_replace($forbidden_characters, ' ', $this->data['User'][0]['Company']), 0, 32);
		$this->data['User'][0]['Address']['Street1'] = str_replace($forbidden_characters, ' ', $this->data['User'][0]['Address']['Street1']);
		$this->data['User'][0]['Address']['Street2'] = str_replace($forbidden_characters, ' ', $this->data['User'][0]['Address']['Street2']);
		$this->data['User'][0]['Address']['City'] = str_replace($forbidden_characters, ' ', $this->data['User'][0]['Address']['City']);
		if (empty($this->data['User'][0]['Address']['Street1']) && !empty($this->data['User'][0]['Address']['Street2']))
		{
			$this->data['User'][0]['Address']['Street1'] = $this->data['User'][0]['Address']['Street2'];
			$this->data['User'][0]['Address']['Street2'] = '';
		}
		$checkNotProvided = array('Street1' => 'Not provided', 'ZipCode' => '00000', 'City' => 'Not provided', 'Country' => 'FR');
		foreach ($checkNotProvided as $key => $value)
			if (empty($this->data['User'][0]['Address'][$key]))
				$this->data['User'][0]['Address'][$key] = $value;
		if (!Validate::isPostCode($this->data['User'][0]['Address']['ZipCode']))
			$this->data['User'][0]['Address']['ZipCode'] = '00000';

		// Fix data (when only one product, array is not the same)
		if (!isset($this->data['OrderInfo']['Product'][0]))
			$this->data['OrderInfo']['Product'] = array($this->data['OrderInfo']['Product']);

		// Calcul total product without tax
		$this->data['OrderInfo']['TotalProductsWithVAT'] = 0;
		$this->data['OrderInfo']['TotalProductsWithoutVAT'] = 0;
		$this->data['OrderInfo']['TotalInsurance'] = 0;
		$this->data['OrderInfo']['RefundedAmount'] = 0;
		$this->data['OrderInfo']['OptionalFeaturePrice'] = 0;
		$this->data['OrderInfo']['TotalPromotionDiscount'] = 0;
		foreach ($this->data['OrderInfo']['Product'] as $kp => $product)
		{
			// If it's not a cancelled product
			if ($product['Status'] != \Sellermania\OrderConfirmClient::STATUS_CANCELLED_SELLER)
			{
				// Calcul total product without tax
				$product_price = $product['Amount']['Price'];
				$vat_rate = 1;
				if (isset($product['VatRate']))
					$vat_rate = 1 + ($product['VatRate'] / 10000);
				$product_tax = $product_price * ($vat_rate - 1);
				$this->data['OrderInfo']['TotalProductsWithoutVAT'] += (($product_price / $vat_rate) * $product['QuantityPurchased']);
				$this->data['OrderInfo']['TotalProductsWithVAT'] += ($product_price * $product['QuantityPurchased']);

				// Calcul total Insurance
				if (isset($product['InsurancePrice']['Amount']['Price']))
					$this->data['OrderInfo']['TotalInsurance'] += ($product['InsurancePrice']['Amount']['Price'] * $product['QuantityPurchased']);

				// Calcul total Promotion Discount
				if (isset($product['ItemPromotionDiscount']['Amount']['Price']))
					$this->data['OrderInfo']['TotalPromotionDiscount'] += $product['ItemPromotionDiscount']['Amount']['Price'];

				// Calcul total refunded
				if (isset($product['RefundedAmount']['Amount']['Price']))
					$this->data['OrderInfo']['RefundedAmount'] += $product['RefundedAmount']['Amount']['Price'];

				// Calcul total optional feature price
				if (isset($product['OptionalFeaturePrice']['Amount']['Price']))
					$this->data['OrderInfo']['OptionalFeaturePrice'] += $product['OptionalFeaturePrice']['Amount']['Price'] * $product['QuantityPurchased'];

				// Create order detail (only create order detail for unmatched product)
				$this->data['OrderInfo']['Product'][$kp]['ProductVAT'] = array('total' => $product_tax, 'rate' => $vat_rate);
			}

			// Fix Ean
			if (!isset($this->data['OrderInfo']['Product'][$kp]['Ean']))
				$this->data['OrderInfo']['Product'][$kp]['Ean'] = '';

			// Fix Sku
			if (!isset($this->data['OrderInfo']['Product'][$kp]['Sku']))
				$this->data['OrderInfo']['Product'][$kp]['Sku'] = '';

			// Fix non existing variable
			if (!isset($this->data['OrderInfo']['Product'][$kp]['ProductVAT']['total']))
				$this->data['OrderInfo']['Product'][$kp]['ProductVAT']['total'] = 0;
			if (!isset($this->data['OrderInfo']['Product'][$kp]['Amount']['Price']))
				$this->data['OrderInfo']['Product'][$kp]['Amount']['Price'] = 0;
		}

		// Fix paiement date
		if (!isset($this->data['Paiement']['Date']))
			$this->data['Paiement']['Date'] = date('Y-m-d H:i:s');
		$this->data['Paiement']['Date'] = substr($this->data['Paiement']['Date'], 0, 19);
		$this->data['OrderInfo']['Date'] = substr($this->data['OrderInfo']['Date'], 0, 19);
	}


	/**
	 * Create customer
	 */
	public function createCustomer()
	{
		// Create customer as guest
		$this->customer = new Customer();
		$this->customer->id_gender = 9;
		$this->customer->firstname = $this->data['User'][0]['FirstName'];
		$this->customer->lastname = $this->data['User'][0]['LastName'];
		$this->customer->email = Configuration::get('PS_SHOP_EMAIL');
		$this->customer->passwd = md5(pSQL(_COOKIE_KEY_.rand()));
		$this->customer->is_guest = 1;
		$this->customer->active = 1;
		$this->customer->add();

		// Fix lang for PS 1.4
		$this->id_lang = Configuration::get('PS_LANG_DEFAULT');
		if (version_compare(_PS_VERSION_, '1.5') >= 0)
			$this->id_lang = $this->customer->id_lang;

		// Set context
		$this->context->customer = $this->customer;
	}


	/**
	 * Create Address
	 */
	public function createAddress()
	{
		// Create address
		$this->address = new Address();
		$this->address->alias = 'Sellermania';
		$this->address->company = $this->data['User'][0]['Company'];
		$this->address->firstname = $this->data['User'][0]['FirstName'];
		$this->address->lastname = $this->data['User'][0]['LastName'];
		$this->address->address1 = $this->data['User'][0]['Address']['Street1'];
		$this->address->address2 = $this->data['User'][0]['Address']['Street2'];
		$this->address->postcode = $this->data['User'][0]['Address']['ZipCode'];
		$this->address->city = $this->data['User'][0]['Address']['City'];
		$this->address->id_country = Country::getByIso($this->data['User'][0]['Address']['Country']);
		$this->address->phone = $this->data['User'][0]['Address']['ShippingPhonePrestaShop'];
		$this->address->id_customer = $this->customer->id;
		$this->address->active = 1;
		$this->address->add();
	}


	/**
	 * Create Cart
	 */
	public function createCart()
	{
		// Create Cart
		$this->cart = new Cart();
		$this->cart->id_customer = $this->customer->id;
		$this->cart->id_address_invoice = $this->address->id;
		$this->cart->id_address_delivery = $this->address->id;
		$this->cart->id_carrier = Configuration::get('PS_CARRIER_DEFAULT');
		$this->cart->id_lang = $this->id_lang;
		$this->cart->id_currency = Currency::getIdByIsoCode($this->data['OrderInfo']['Amount']['Currency']);
		$this->cart->recyclable = 0;
		$this->cart->gift = 0;
		$this->cart->add();

		// Update cart with products
		$cart_nb_products = 0;
		foreach ($this->data['OrderInfo']['Product'] as $kp => $product)
		{
			// Get Product Identifiers
			$product = $this->getProductIdentifier($product);
			$this->data['OrderInfo']['Product'][$kp] = $product;

			// Add to cart
			$quantity = (int)$product['QuantityPurchased'];
			$id_product = (int)$product['id_product'];
			$id_product_attribute = (int)$product['id_product_attribute'];
			if ($this->cart->updateQty($quantity, $id_product, $id_product_attribute))
				$cart_nb_products++;
		}

		// Cart update
		$this->cart->update();

		// Flush cart delivery cache
		if (version_compare(_PS_VERSION_, '1.5') >= 0)
		{
			$this->cart->getDeliveryOptionList(null, true);
			$this->cart->getDeliveryOption(null, false, false);
		}
	}


	/**
	 * Create order
	 */
	public function createOrder()
	{
		// Remove customer e-mail to avoid email sending
		$customer_email = $this->context->customer->email;
		Db::getInstance()->autoExecute(_DB_PREFIX_.'customer', array('email' => 'NOSEND-SM'), 'UPDATE', '`id_customer` = '.(int)$this->customer->id);
		$this->context->customer->email = 'NOSEND-SM';
		$this->context->customer->clearCache();

		// Retrieve amount paid
		$amount_paid = (float)$this->data['OrderInfo']['TotalAmount']['Amount']['Price'];

		// Fix for PS 1.4 to avoid PS_OS_ERROR status, amount paid will be fixed after order creation anyway
		if (version_compare(_PS_VERSION_, '1.5') < 0)
			$amount_paid = (float)(Tools::ps_round((float)($this->cart->getOrderTotal(true, Cart::BOTH)), 2));

		// Create order
		$payment_method = $this->data['OrderInfo']['MarketPlace'].' - '.$this->data['OrderInfo']['OrderId'];
		$payment_module = new SellermaniaPaymentModule();
		$payment_module->name = $this->module->name;
		$payment_module->validateOrder((int)$this->cart->id, Configuration::get('PS_OS_SM_AWAITING'), $amount_paid, $payment_method, NULL, array(), (int)$this->cart->id_currency, false, $this->customer->secure_key);
		$id_order = $payment_module->currentOrder;
		$this->order = new Order((int)$id_order);

		// Restore customer e-mail
		Db::getInstance()->autoExecute(_DB_PREFIX_.'customer', array('email' => pSQL($customer_email)), 'UPDATE', '`id_customer` = '.(int)$this->customer->id);
		$this->context->customer->email = $customer_email;

		// If last order status is not PS_OS_SM_AWAITING, we update it
		if ($this->order->getCurrentState() != Configuration::get('PS_OS_SM_AWAITING'))
		{
			// Create new OrderHistory
			$history = new OrderHistory();
			$history->id_order = $this->order->id;
			$history->id_employee = (int)$this->context->employee->id;
			$history->id_order_state = (int)Configuration::get('PS_OS_SM_AWAITING');
			$history->changeIdOrderState((int)Configuration::get('PS_OS_SM_AWAITING'), $this->order->id);
			$history->add();
		}

		// Fix order depending on version
		$this->fixOrder(true);

		// Since we update the order values by direct SQL request, we need to flush the Object cache
		// "changeIdOrderState" method uses order "update" method (old values were set again)
		$this->order->clearCache();
	}

	/**
	 * Save Sellermania order
	 */
	public function saveSellermaniaOrder($error = '')
	{
		$id_currency = Currency::getIdByIsoCode($this->data['OrderInfo']['Amount']['Currency']);
		$amount_total = $this->data['OrderInfo']['TotalAmount']['Amount']['Price'];

		$sellermania_order = new SellermaniaOrder();
		$sellermania_order->marketplace = trim($this->data['OrderInfo']['MarketPlace']);
		$sellermania_order->customer_name = $this->data['User'][0]['OriginalName'];
		$sellermania_order->ref_order = trim($this->data['OrderInfo']['OrderId']);
		$sellermania_order->amount_total = Tools::displayPrice($amount_total, $id_currency);
		$sellermania_order->info = json_encode($this->data);
		$sellermania_order->error = $error;
		$sellermania_order->id_order = $this->order->id;
		$sellermania_order->id_employee_accepted = 0;
		$sellermania_order->date_payment = substr($this->data['Paiement']['Date'], 0, 19);
		$sellermania_order->add();
	}


	/**
	 * Get Product Identifier
	 * @param array $product
	 * @return array $product
	 */
	public function getProductIdentifier($product)
	{
		$fields = array('reference' => 'Sku', 'ean13' => 'Ean');
		$tables = array('product_attribute', 'product');

		// Check fields sku and ean13 on table product_attribute and product
		// If a match is found, we return it
		foreach ($fields as $field_ps => $fields_sm)
			foreach ($tables as $table)
				if (isset($product[$fields_sm]) && strlen($product[$fields_sm]) > 2)
				{
					// Check product attribute
					$pr = Db::getInstance()->getRow('SELECT * FROM `'._DB_PREFIX_.$table.'` WHERE `'.$field_ps.'` = \''.pSQL($product[$fields_sm]).'\'');
					if ($pr['id_product'] > 0)
					{
						$product['id_product'] = $pr['id_product'];
						$product['id_product_attribute'] = 0;
						if (isset($pr['id_product_attribute']))
							$product['id_product_attribute'] = $pr['id_product_attribute'];
						return $product;
					}
				}

		// If product unmatch, we return the default SellerMania product, method createOrderDetail will fix this
		$product['id_product'] = Configuration::get('SM_DEFAULT_PRODUCT_ID');
		$product['id_product_attribute'] = 0;

		return $product;
	}


	/************** FIX ORDER **************/

	/**
	 * Fix order on PrestaShop
	 */
	public function fixOrder($fix_details = true)
	{
		if (version_compare(_PS_VERSION_, '1.5') >= 0)
			$this->fixOrder15($fix_details);
		else
			$this->fixOrder14($fix_details);
	}

	/************** FIX ORDER 1.4 **************/


	/**
	 * Fix order on PrestaShop 1.4
	 */
	public function fixOrder14($fix_details = true)
	{
		// Fix order detail
		if ($fix_details)
			foreach ($this->data['OrderInfo']['Product'] as $kp => $product)
				$this->fixOrderDetail14($this->order->id, $product);

		// Fix on order (use of autoExecute instead of Insert to be compliant PS 1.4)
		$update = array(
			'total_paid' => (float)$this->data['OrderInfo']['TotalAmount']['Amount']['Price'],
			'total_paid_real' => (float)$this->data['OrderInfo']['TotalAmount']['Amount']['Price'],
			'total_products' => (float)$this->data['OrderInfo']['TotalProductsWithoutVAT'],
			'total_products_wt' => (float)$this->data['OrderInfo']['TotalProductsWithVAT'],
			'total_shipping' => (float)$this->data['OrderInfo']['Transport']['Amount']['Price'],
			'date_add' => pSQL(substr($this->data['OrderInfo']['Date'], 0, 19)),
		);
		Db::getInstance()->autoExecute(_DB_PREFIX_.'orders', $update, 'UPDATE', '`id_order` = '.(int)$this->order->id);
	}

	/**
	 * Create order detail
	 * @param $id_order
	 * @param $product
	 */
	public function fixOrderDetail14($id_order, $product)
	{
		// Calcul price without tax
		$product_price_with_tax = $product['Amount']['Price'];
		$vat_rate = 1 + ($product['VatRate'] / 10000);
		$product_price_without_tax = $product_price_with_tax / $vat_rate;

		// SQL data
		$sql_data = array(
			'id_order' => (int)$id_order,
			'product_id' => $product['id_product'],
			'product_attribute_id' => $product['id_product_attribute'],
			'product_name' => pSQL($product['ItemName']),
			'product_quantity' => (int)$product['QuantityPurchased'],
			'product_quantity_in_stock' => 0,
			'product_price' => (float)$product_price_without_tax,
			'tax_rate' => (float)($product['VatRate'] / 100),
			'tax_name' => ((float)($product['VatRate'] / 100)).'%',
			'product_ean13' => pSQL($product['Ean']),
			'product_reference' => pSQL($product['Sku']),
		);


		// We check if the product has a match
		// If yes, we update it, if not, we continue
		$id_order_detail = (int)Db::getInstance()->getValue('
		SELECT `id_order_detail`
		FROM `'._DB_PREFIX_.'order_detail`
		WHERE `id_order` = '.(int)$id_order.'
		AND `product_reference` = \''.pSQL($product['Sku']).'\'');

		// We check if a default Sellermania product is in Order Detail
		// If yes, we update it, if not, we create a new Order Detail
		if ($id_order_detail < 1)
			$id_order_detail = (int)Db::getInstance()->getValue('
			SELECT `id_order_detail`
			FROM `'._DB_PREFIX_.'order_detail`
			WHERE `id_order` = '.(int)$id_order.'
			AND `product_id` = '.(int)Configuration::get('SM_DEFAULT_PRODUCT_ID').'
			AND `product_name` = \'Sellermania product\'');

		if ($id_order_detail > 0)
		{
			$where = '`id_order` = '.(int)$id_order.' AND `id_order_detail` = '.(int)$id_order_detail;
			Db::getInstance()->autoExecute(_DB_PREFIX_.'order_detail', $sql_data, 'UPDATE', $where);
		}
		else
		{
			Db::getInstance()->autoExecute(_DB_PREFIX_.'order_detail', $sql_data, 'INSERT');
			$id_order_detail = Db::getInstance()->Insert_ID();
		}
	}



	/************** CREATE ORDER 1.5 / 1.6 **************/


	/**
	 * Create order on PrestaShop 1.5 / 1.6
	 */
	public function fixOrder15($fix_details = true)
	{
		// Fix order detail
		if ($fix_details)
			foreach ($this->data['OrderInfo']['Product'] as $kp => $product)
				$this->fixOrderDetail15($this->order->id, $product);

		// Fix on order (use of autoExecute instead of Insert to be compliant PS 1.4)
		$update = array(
			'total_paid' => (float)$this->data['OrderInfo']['TotalAmount']['Amount']['Price'],
			'total_paid_tax_incl' => (float)$this->data['OrderInfo']['TotalAmount']['Amount']['Price'],
			'total_paid_tax_excl' => (float)$this->data['OrderInfo']['TotalProductsWithoutVAT'] + (float)$this->data['OrderInfo']['Transport']['Amount']['Price'],
			'total_paid_real' => (float)$this->data['OrderInfo']['TotalAmount']['Amount']['Price'],
			'total_products' => (float)$this->data['OrderInfo']['TotalProductsWithoutVAT'],
			'total_products_wt' => (float)$this->data['OrderInfo']['TotalProductsWithVAT'],
			'total_shipping' => (float)$this->data['OrderInfo']['Transport']['Amount']['Price'],
			'total_shipping_tax_incl' => (float)$this->data['OrderInfo']['Transport']['Amount']['Price'],
			'total_shipping_tax_excl' => (float)$this->data['OrderInfo']['Transport']['Amount']['Price'],
			'date_add' => pSQL(substr($this->data['OrderInfo']['Date'], 0, 19)),
		);
		Db::getInstance()->update('orders', $update, '`id_order` = '.(int)$this->order->id);


		// Fix payment
		$updateTab = array(
			'amount' => $update['total_paid_real'],
			'payment_method' => $this->data['OrderInfo']['MarketPlace'].' '.$this->data['OrderInfo']['OrderId'],
		);
		$where = '`order_reference` = \''.pSQL($this->order->reference).'\'';
		Db::getInstance()->update('order_payment', $updateTab, $where);

		// Fix carrier
		$carrier_update = array(
			'shipping_cost_tax_incl' => (float)$this->data['OrderInfo']['Transport']['Amount']['Price'],
			'shipping_cost_tax_excl' => (float)$this->data['OrderInfo']['Transport']['Amount']['Price'],
		);
		$where = '`id_order` = \''.pSQL($this->order->id).'\'';
		Db::getInstance()->update('order_carrier', $carrier_update, $where);

		// Fix invoice
		unset($update['total_paid']);
		unset($update['total_paid_real']);
		unset($update['total_shipping']);
		$where = '`id_order` = '.(int)$this->order->id;
		Db::getInstance()->update('order_invoice', $update, $where);

		// Update Sellermania default product quantity
		Db::getInstance()->update('stock_available', array('quantity' => 0), '`id_product` = '.Configuration::get('SM_DEFAULT_PRODUCT_ID'));
	}


	/**
	 * Create order detail
	 * @param $id_order
	 * @param $product
	 */
	public function fixOrderDetail15($id_order, $product)
	{
		// Calcul prices
		if (!isset($product['VatRate']))
			$product['VatRate'] = 0;
		$product_price_with_tax = $product['Amount']['Price'];
		$vat_rate = 1 + ($product['VatRate'] / 10000);
		$product_price_without_tax = $product_price_with_tax / $vat_rate;

		// Get order invoice ID
		$id_order_invoice = Db::getInstance()->getValue('
		SELECT `id_order_invoice` FROM `'._DB_PREFIX_.'order_invoice`
		WHERE `id_order` = '.(int)$id_order);

		// SQL data
		$sql_data = array(
			'id_order' => (int)$id_order,
			'product_id' => $product['id_product'],
			'product_attribute_id' => $product['id_product_attribute'],
			'product_name' => pSQL($product['ItemName']),
			'product_quantity' => (int)$product['QuantityPurchased'],
			'product_quantity_in_stock' => 0,
			'product_price' => (float)$product_price_without_tax,
			'tax_rate' => (float)($product['VatRate'] / 100),
			'tax_name' => ((float)($product['VatRate'] / 100)).'%',
			'product_ean13' => pSQL($product['Ean']),
			'product_reference' => pSQL($product['Sku']),

			'id_order_invoice' => $id_order_invoice,
			'id_warehouse' => 0,
			'id_shop' => Context::getContext()->shop->id,
			'total_price_tax_incl' => (float)($product_price_with_tax * (int)$product['QuantityPurchased']),
			'total_price_tax_excl' => (float)($product_price_without_tax * (int)$product['QuantityPurchased']),
			'unit_price_tax_incl' => (float)$product_price_with_tax,
			'unit_price_tax_excl' => (float)$product_price_without_tax,
			'original_product_price' => (float)$product_price_without_tax,
		);

		$sql_data_tax = array(
			'id_tax' => 0,
			'unit_amount' => (float)$product['ProductVAT']['total'],
			'total_amount' => (float)((float)$product['ProductVAT']['total'] * (int)$product['QuantityPurchased']),
		);

		// We check if the product has a match
		// If yes, we update it, if not, we continue
		$id_order_detail = (int)Db::getInstance()->getValue('
		SELECT `id_order_detail`
		FROM `'._DB_PREFIX_.'order_detail`
		WHERE `id_order` = '.(int)$id_order.'
		AND `product_reference` = \''.pSQL($product['Sku']).'\'');

		// We check if a default Sellermania product is in Order Detail
		// If yes, we update it, if not, we create a new Order Detail
		if ($id_order_detail < 1)
			$id_order_detail = Db::getInstance()->getValue('
			SELECT `id_order_detail`
			FROM `'._DB_PREFIX_.'order_detail`
			WHERE `id_order` = '.(int)$id_order.'
			AND `product_id` = '.(int)Configuration::get('SM_DEFAULT_PRODUCT_ID').'
			AND `product_name` = \'Sellermania product\'');

		if ($id_order_detail > 0)
		{
			$where = '`id_order` = '.(int)$id_order.' AND `id_order_detail` = '.(int)$id_order_detail;
			Db::getInstance()->update('order_detail', $sql_data, $where);

			$where = '`id_order_detail` = '.(int)$id_order_detail;
			Db::getInstance()->update('order_detail_tax', $sql_data_tax, $where);
		}
		else
		{
			Db::getInstance()->insert('order_detail', $sql_data);
			$id_order_detail = Db::getInstance()->Insert_ID();

			$sql_data_tax['id_order_detail'] = (int)$id_order_detail;
			Db::getInstance()->insert('order_detail_tax', $sql_data_tax);
		}
	}


}

