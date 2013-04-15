<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2013 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

$akpaymentinclude = include_once JPATH_ADMINISTRATOR.'/components/com_akeebasubs/assets/akpayment.php';
if(!$akpaymentinclude) { unset($akpaymentinclude); return; } else { unset($akpaymentinclude); }

class plgAkpaymentAlloPass extends plgAkpaymentAbstract
{
	private $apMapping = array();

	public function __construct(&$subject, $config = array())
	{
		$config = array_merge($config, array(
			'ppName'		=> 'allopass',
			'ppKey'			=> 'PLG_AKPAYMENT_ALLOPASS_TITLE',
			'ppImage'		=> rtrim(JURI::base(),'/').'/media/com_akeebasubs/images/frontend/allopass-logo.png',
		));

		parent::__construct($subject, $config);

		// Load level to alloPass mapping from plugin parameters
 		$rawApMapping = $this->params->get('apmapping','');
		$this->apMapping = $this->parseAPMatching($rawApMapping);
	}

	/**
	 * Returns the payment form to be submitted by the user's browser. The form must have an ID of
	 * "paymentForm" and a visible submit button.
	 *
	 * @param string $paymentmethod
	 * @param JUser $user
	 * @param AkeebasubsTableLevel $level
	 * @param AkeebasubsTableSubscription $subscription
	 * @return string
	 */
	public function onAKPaymentNew($paymentmethod, $user, $level, $subscription)
	{
		if($paymentmethod != $this->ppName) return false;

		// AlloPass Info for this level
		$alloPass = $this->apMapping[$level->akeebasubs_level_id];
		if(empty($alloPass)) {
			return JError::raiseError(500, 'Cannot proceed with the payment. No alloPass information are definied for this subscription.');
		}

		$kuser = FOFModel::getTmpInstance('Users','AkeebasubsModel')
			->user_id($user->id)
			->getFirstItem();

		// Payment url
		$rawPricingInfo = file_get_contents('https://payment.allopass.com/api/onetime/pricing.apu?'
				.'site_id=' . $alloPass['site_id'] . '&product_id=' . $alloPass['product_id']);
		$countryCode = trim($kuser->country);
		$url = $this->getPaymentURL($rawPricingInfo, $countryCode);
		if(empty($url)) {
			$error_url = 'index.php?option='.JRequest::getCmd('option').
				'&view=level&slug='.$level->slug.
				'&layout='.JRequest::getCmd('layout','default');
			$error_url = JRoute::_($error_url,false);
			JFactory::getApplication()->redirect($error_url, 'This payment method is not configured for your country.' ,'error');
		}
		$url .= '&merchant_transaction_id=' . $subscription->akeebasubs_subscription_id;

		// Payment button
		$button = 'https://payment.allopass.com/static/buy/button/en/162x56.png';

		// Language settings
		try {
			$lang = strtolower(substr(JFactory::getLanguage()->getTag(), 0, 2));
			$url .= '&lang=' . $lang;
			// Button in English (default), French or German
			if($lang == 'fr' || $lang == 'de') {
				$button = 'https://payment.allopass.com/static/buy/button/' . $lang . '/162x56.png';
			}
		} catch(Exception $e) {
			// Shouldn't happend. But setting the language is optional... so do nothing here.
		}

		$data = (object)array(
			'url'			=> $url,
			'button'		=> $button
		);

		@ob_start();
		include dirname(__FILE__).'/allopass/form.php';
		$html = @ob_get_clean();

		return $html;
	}

	public function onAKPaymentCallback($paymentmethod, $data)
	{
		JLoader::import('joomla.utilities.date');

		// Check if we're supposed to handle this
		if($paymentmethod != $this->ppName) return false;

		// Check IPN data for validity (i.e. protect against fraud attempt)
		$isValid = $this->isValidIPN($data);
		if(!$isValid) $data['akeebasubs_failure_reason'] = 'Invalid response received.';

		// Load the relevant subscription row
		if($isValid) {
			$id = $data['merchant_transaction_id'];
			$subscription = null;
			if($id > 0) {
				$subscription = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
					->setId($id)
					->getItem();
				if( ($subscription->akeebasubs_subscription_id <= 0) || ($subscription->akeebasubs_subscription_id != $id) ) {
					$subscription = null;
					$isValid = false;
				}
			} else {
				$isValid = false;
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'The merchant_transaction_id is invalid';
		}

		// Check that transaction_id has not been previously processed
		if($isValid && !is_null($subscription)) {
			if($subscription->processor_key == $data['transaction_id']) {
				$isValid = false;
				$data['akeebasubs_failure_reason'] = "I will not process the same transaction_id twice";
			}
		}

		// Check that currency is correct and set corresponding amount
		if($isValid && !is_null($subscription)) {
			$mc_currency = strtoupper($data['currency']);
			$currency = strtoupper(AkeebasubsHelperCparams::getParam('currency','EUR'));
			if($mc_currency != $currency) {
				$mc_currency = strtoupper($data['payout_currency']);
				if($mc_currency != $currency) {
					$mc_currency = strtoupper($data['reference_currency']);
					if($mc_currency != $currency) {
						$isValid = false;
						$data['akeebasubs_failure_reason'] = "Invalid currency";
					} else {
						$mc_gross = floatval($data['reference_amount']);
					}
				} else {
					$mc_gross = floatval($data['payout_amount']);
				}
			} else {
				$mc_gross = floatval($data['amount']);
			}
		}

		// Check that acount is correct
		$isPartialRefund = false;
		if($isValid && !is_null($subscription)) {
			$gross = $subscription->gross_amount;
			if($mc_gross > 0) {
				// A positive value means "payment". The prices MUST match!
				// Important: NEVER, EVER compare two floating point values for equality.
				$isValid = ($gross - $mc_gross) < 0.01;
			} else {
				$isPartialRefund = false;
				$temp_mc_gross = -1 * $mc_gross;
				$isPartialRefund = ($gross - $temp_mc_gross) > 0.01;
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Paid amount does not match the subscription amount';
		}

		// Log the IPN data
		$this->logIPN($data, $isValid);

		// Fraud attempt? Do nothing more!
		if(!$isValid) return false;


		// Check the payment_status
		switch($data['status'])
		{
			case -1:
				// Payment accepted
				$newStatus = 'P';
				break;
			case 0:
				// The transaction has been initiated but has not yet completed
				$newStatus = 'C';
				break;
			default:
				$newStatus = 'X';
				break;
		}

		// Update subscription status (this also automatically calls the plugins)
		$updates = array(
				'akeebasubs_subscription_id'	=> $id,
				'processor_key'					=> $data['transaction_id'],
				'state'							=> $newStatus,
				'enabled'						=> 0
		);
		JLoader::import('joomla.utilities.date');
		if($newStatus == 'C') {
			$this->fixDates($subscription, $updates);

		}
		$subscription->save($updates);

		// Run the onAKAfterPaymentCallback events
		JLoader::import('joomla.plugin.helper');
		JPluginHelper::importPlugin('akeebasubs');
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKAfterPaymentCallback',array(
			$subscription
		));

		return true;
	}

	/**
	 * Validates the incoming data.
	 */
	private function isValidIPN($data)
	{
		$secretKey = $this->params->get('skey','');

		$apiHash = 'sha1';
		if(!empty($data['api_hash'])) {
			$apiHash = $data['api_hash'];
		}
		$apiSig = $data['api_sig'];

		$ignore = array('api_sig', 'Itemid', 'option', 'view', 'paymentmethod');

		ksort($data);
		$string2compute = '';
		foreach($data as $name => $val) {
			if (in_array($name, $ignore))
			{
				continue;
			}

			$string2compute .= $name . $val;
		}

		if($apiHash == 'sha1')
		{
			$hash = sha1($string2compute . $secretKey);
		}
		elseif($apiHash == 'md5')
		{
			$hash = md5($string2compute . $secretKey);
		}
		else
		{
			$hash = '';
		}

		return $hash == $apiSig;
	}


	private function getPaymentURL($rawPricingInfo, $countryCode){
		// Load XML
		$pricingDoc = new DOMDocument();
		$pricingDoc->loadXML($rawPricingInfo);
		$responseElem = $pricingDoc->getElementsByTagName('response')->item(0);
		// Check if response code is ok
		if($responseElem->getAttribute('code') != 0) {
			return null;
		}
		// Find market by country code
		$marketElems = $pricingDoc->getElementsByTagName('market');
		foreach ($marketElems as $marketElem) {
			$marketCC = $marketElem->getAttribute('country_code');
			if(strtoupper($marketCC) == strtoupper($countryCode)) {
				$ppElem = $marketElem->getElementsByTagName('pricepoint')->item(0);
				if(! empty($ppElem)) {
					$urlElem = $ppElem->getElementsByTagName('buy_url')->item(0);
					$url = $urlElem->nodeValue;
					if(! empty($url)) {
						return $url;
					}
				}
			}
		}
		return null;
	}

	private function parseAPMatching($rawData)
	{
		if(empty($rawData)) return array();

		$ret = array();

		// Just in case something funky happened...
		$rawData = str_replace("\\n", "\n", $rawData);
		$rawData = str_replace("\r", "\n", $rawData);
		$rawData = str_replace("\n\n", "\n", $rawData);

		$lines = explode("\n", $rawData);

		foreach($lines as $line) {
			$line = trim($line);
			$parts = explode('=', $line, 2);
			if(count($parts) != 2) continue;

			$level = trim($parts[0]);
			$levelId = $this->ASLevelToId($level);
			if($levelId < 0) continue;

			$rawAlloPass = $parts[1];
			$alloPass = explode(':', $rawAlloPass);
			if(empty($alloPass)) continue;
			if(count($alloPass) < 2) continue;

			$siteId = trim($alloPass[0]);
			if(empty($siteId)) continue;
			$productId = trim($alloPass[1]);
			if(empty($productId)) continue;

			$pricePoint = array(
				'site_id'		=> $siteId,
				'product_id'	=> $productId
			);

			$ret[$levelId] = $pricePoint;
		}

		return $ret;
	}

	/**
	 * Converts an Akeeba Subscriptions level to a numeric ID
	 *
	 * @param $title string The level's name to be converted to an ID
	 *
	 * @return int The subscription level's ID or -1 if no match is found
	 */
	private function ASLevelToId($title)
	{
		static $levels = null;

		// Don't process invalid titles
		if(empty($title)) return -1;

		// Fetch a list of subscription levels if we haven't done so already
		if(is_null($levels)) {
			$levels = array();
			$list = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
				->getList();
			if(count($list)) foreach($list as $level) {
				$thisTitle = strtoupper($level->title);
				$levels[$thisTitle] = $level->akeebasubs_level_id;
			}
		}

		$title = strtoupper($title);
		if(array_key_exists($title, $levels)) {
			// Mapping found
			return($levels[$title]);
		} elseif( (int)$title == $title ) {
			// Numeric ID passed
			return (int)$title;
		} else {
			// No match!
			return -1;
		}
	}
}