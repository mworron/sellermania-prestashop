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

require_once(dirname(__FILE__).'/../front/SellerManiaExport.php');

class SellerManiaGetContentController
{
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
	 * Test configuration
	 */
	public function testConfiguration()
	{
		try
		{
			require_once(dirname(__FILE__).'/../../classes/SellermaniaTestAPI.php');
			$test = new SellermaniaTestAPI();
			$test->run();
			Configuration::updateValue('SM_CREDENTIALS_CHECK', 'ok');
			$this->context->smarty->assign('sm_confirm_credentials', 'ok');
		}
		catch (\Exception $e)
		{
			Configuration::updateValue('SM_CREDENTIALS_CHECK', 'ko');
			$this->context->smarty->assign('sm_error_credentials', $e->getMessage());
		}
	}


	/**
	 * Save configuration
	 */
	public function saveConfiguration()
	{
		$params = array('sm_import_orders', 'sm_order_email', 'sm_order_token', 'sm_order_endpoint', 'sm_confirm_order_endpoint');
		foreach ($params as $p)
			if (isset($_POST[$p]))
				Configuration::updateValue(strtoupper($p), trim($_POST[$p]));

		if (version_compare(PHP_VERSION, '5.3.0') >= 0)
			if (Configuration::get('SM_IMPORT_ORDERS') == 'yes')
				$this->testConfiguration();
	}


	/**
	 * Assign data to Smarty
	 */
	public function assignData()
	{
		// Init vars
		$languages_list = Language::getLanguages();
		$this->context->shop->setContext(1);
		$module_web_path = Tools::getHttpHost(true).$this->context->shop->physical_uri.'modules/'.$this->module->name.'/';
		$export_directory_writable = 0;
		if (is_writable($this->dir_path.'/export/'))
			$export_directory_writable = 1;
		$sellermania_key = Configuration::get('SELLERMANIA_KEY');
		$smec = new SellerManiaExportController();

		// Check if file exists and retrieve the creation date
		$files_list = array();
		foreach ($languages_list as $language)
		{
			$iso_lang = strtolower($language['iso_code']);
			$web_path_file = $module_web_path.$smec->get_export_filename($iso_lang, true);
			$real_path_file = $smec->get_export_filename($iso_lang);
			$files_list[$iso_lang]['file'] = $web_path_file;
			if (file_exists($real_path_file))
				$files_list[$iso_lang]['generated'] = date("d/m/Y H:i:s", filectime($real_path_file));
		}

		// Assign to Smarty
		if (version_compare(PHP_VERSION, '5.3.0') < 0)
		{
			$this->context->smarty->assign('php_version', PHP_VERSION);
			$this->context->smarty->assign('no_namespace_compatibility', '1');
		}

		$this->context->smarty->assign('script_path', $this->dir_path);
		$this->context->smarty->assign('export_directory_writable', $export_directory_writable);
		$this->context->smarty->assign('module_web_path', $module_web_path);
		$this->context->smarty->assign('sellermania_key', $sellermania_key);
		$this->context->smarty->assign('files_list', $files_list);
		$this->context->smarty->assign('languages_list', $languages_list);
		$this->context->smarty->assign('sellermania_module_path', $this->web_path);

		$this->context->smarty->assign('sm_import_orders', Configuration::get('SM_IMPORT_ORDERS'));
		$this->context->smarty->assign('sm_order_email', Configuration::get('SM_ORDER_EMAIL'));
		$this->context->smarty->assign('sm_order_token', Configuration::get('SM_ORDER_TOKEN'));
		$this->context->smarty->assign('sm_order_endpoint', Configuration::get('SM_ORDER_ENDPOINT'));
		$this->context->smarty->assign('sm_confirm_order_endpoint', Configuration::get('SM_CONFIRM_ORDER_ENDPOINT'));

		$this->context->smarty->assign('sm_last_import', date('Y-m-d H:i:s', strtotime(Configuration::get('SM_NEXT_IMPORT').' -1 hour')));
		$this->context->smarty->assign('sm_next_import', Configuration::get('SM_NEXT_IMPORT'));
	}


	/**
	 * Run method
	 * @return string $html
	 */
	public function run()
	{
		$this->saveConfiguration();
		$this->assignData();
		return $this->module->compliantDisplay('displayGetContent'.(isset($this->module->bootstrap) ? '.bootstrap' : '').'.tpl');
	}
}

