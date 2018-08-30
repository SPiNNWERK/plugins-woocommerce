<?php

/**
 * Plugin Name: WooCommerce Cashpresso Payment Gateway
 * Plugin URI: https://www.lintranex.com
 * Description: A payment gateway for Cashpresso (https://www.cashpresso.com/).
 * Version: 0.0.1
 * Author: Lintranex Systems
 * Author URI: https://www.lintranex.com
 * Copyright: © 2017 Lintranex Systems.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: lnx-cashpresso-woocommerce
 * Domain Path: /languages
 */
defined('ABSPATH') or exit;

if (false && !in_array($_SERVER["REMOTE_ADDR"], array("212.241.126.109", "212.186.68.179", "213.240.115.62", "35.157.69.65", "35.157.63.173", "35.156.113.120", "35.157.90.220", "178.165.130.181"))) {
	return;
}

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
	return;
}

/**
 * Add the gateway to WC Available Gateways
 *
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + cashpresso gateway
 */
function wc_cashpresso_add_to_gateways($gateways) {
	$gateways[] = 'WC_Gateway_Cashpresso';
	return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_cashpresso_add_to_gateways');

/**
 * Adds plugin page links
 *
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_cashpresso_gateway_plugin_links($links) {
	$plugin_links = array(
		'<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=cashpresso') . '">' . __('Einstellungen', 'lnx-cashpresso-woocommerce') . '</a>',
	);
	return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_cashpresso_gateway_plugin_links');

function wc_cashpresso_gateway_init() {

	if (!class_exists('WC_Payment_Gateway')) {
		return;
	}

	class WC_Gateway_Cashpresso extends WC_Payment_Gateway {

		protected $amount;

		public function __construct() {

			if (!WC()->cart->prices_include_tax) {

				$amount = (float) WC()->cart->cart_contents_total + (float) WC()->cart->tax_total + (float) WC()->cart->shipping_total + (float) WC()->cart->shipping_tax_total;
			} else {

				$amount = (float) WC()->cart->cart_contents_total + (float) WC()->cart->shipping_total;
			}

			$this->amount = $amount;

			$this->id = "cashpresso";
			//$this->icon = plugins_url("lnx-cashpresso-woocommerce/assets/rsz_icon.png");
			$this->has_fields = false;
			$this->method_title = __("cashpresso Ratenkauf", "lnx-cashpresso-woocommerce");
			$this->method_description = __("cashpresso ermöglicht dir Einkäufe in Raten zu bezahlen. Deine Ratenhöhe kannst du dir beim Kauf aussuchen und später jederzeit ändern.", "lnx-cashpresso-woocommerce");

			$this->title = $this->get_option('title') . ' <div id="cashpresso-availability-banner"></div>';
			$this->description = $this->get_option('description') . '<p>&nbsp;</p><input type="hidden" id="cashpressoToken" name="cashpressoToken"><div id="cashpresso-checkout"></div><script type="text/javascript"> //document.addEventListener("DOMContentLoaded", function(event) { if (window.C2EcomCheckout) { window.C2EcomCheckout.refresh( ); } //});</script>';

			$this->test_secretkey = $this->get_option('test_secretkey');
			$this->test_apikey = $this->get_option('test_apikey');
			$this->test_url = $this->get_option('test_url');

			$this->live_secretkey = $this->get_option('live_secretkey');
			$this->live_apikey = $this->get_option('live_apikey');
			$this->live_url = $this->get_option('live_url');

			$this->validUntil = $this->get_option('validUntil');
			$this->live_modus = $this->get_option('live_modus');

			$this->direct_checkout = $this->get_option('direct_checkout');

			$this->bankUsage = $this->get_option('bankUsage');

			$this->boost = $this->get_option('boost');

			$this->interestFreeMaxDuration = $this->get_option('interestFreeMaxDuration');

			$this->instructions = $this->get_option('instructions');

			//$this->locale = $this->get_option('locale');

			$this->minPaybackAmount = $this->get_option('minPaybackAmount');
			$this->limitTotal = $this->get_option('limitTotal');

			$this->init_form_fields();
			$this->init_settings();

			add_action('woocommerce_api_wc_gateway_cashpresso', array($this, 'processCallback'));

			add_action('wp_head', array($this, 'wc_cashpresso_js'));
			add_action('wp_footer', array($this, 'wc_cashpresso_js_footer'));

			//add_action('woocommerce_order_details_before_order_table', array($this, 'wc_cashpresso_wizard'));
			add_filter('woocommerce_thankyou_order_received_text', array($this, 'wc_cashpresso_thankyoutext'), 10, 2);

			add_filter('woocommerce_gateway_title', array($this, 'wc_cashpresso_add_banner'));

			add_action('admin_notices', array($this, 'do_ssl_check'));
			add_action('admin_notices', array($this, 'do_eur_check'));

			// Save settings
			if (is_admin()) {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
			}
		}

		public function validate_live_apikey_field($key) {
			$value = $_POST['woocommerce_cashpresso_' . $key];
			if (empty($value)) {
				$value = $this->get_option("live_apikey");
				echo __("<div class=\"error\"><p>" . __("<strong>ApiKey</strong> invalid. Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");
			}

			return $value;
		}

		public function validate_live_secretkey_field($key) {
			$value = $_POST['woocommerce_cashpresso_' . $key];
			if (empty($value)) {
				$value = $this->get_option("live_secretkey");
				echo __("<div class=\"error\"><p>" . __("<strong>SecretKey</strong> invalid. Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");
			}

			return $value;
		}

		public function validate_validUntil_field($key) {
			$value = $_POST['woocommerce_cashpresso_' . $key];
			if (empty($value)) {
				$value = $this->get_option("validUntil");
				echo __("<div class=\"error\"><p>" . __("<strong>Period of Validity</strong> invalid. Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");

			}
			return $value;
		}

		public function validate_interestFreeDaysMerchant_field($key) {
			$value = $_POST['woocommerce_cashpresso_' . $key];
			if (isset($this->settings["interestFreeMaxDuration"]) && intval($value) > intval($this->settings["interestFreeMaxDuration"])) {
				$value = $this->get_option("interestFreeDaysMerchant");
				echo __("<div class=\"error\"><p>" . __("<strong>interest-free days</strong> invalid. Max Duration is set to: " . $this->settings["interestFreeMaxDuration"] . ". Not updated.") . "</p></div>", "lnx-cashpresso-woocommerce");
			}
			return $value;
		}

		public function do_eur_check() {
			if ($this->enabled == "yes") {
				if (get_woocommerce_currency() !== "EUR") {

					echo __("<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> wurde deaktiviert, da WooCommerce nicht EUR als Währung eingestellt hat. Bitte stellen Sie EUR als Währung ein und aktivieren Sie die Zahlungsmethode anschlie&szlig;end <a href=\"%s\">hier wieder.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>", "lnx-cashpresso-woocommerce");
					$this->settings["enabled"] = "no";
					update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings));
				}
			}
		}

		// Check if we are forcing SSL on checkout pages
		// Custom function not required by the Gateway
		public function do_ssl_check() {
			if ($this->enabled == "yes") {
				if (get_option('woocommerce_force_ssl_checkout') == "no" && (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'off')) {
					echo __("<div class=\"error\"><p>" . sprintf(__("<strong>%s</strong> wurde deaktiviert, da WooCommerce kein SSL Zertifikat auf der Bezahlseite verlangt. Bitte erwerben und installieren Sie ein gültiges SSL Zertifikat  und richten Sie es <a href=\"%s\">es hier ein.</a>"), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')) . "</p></div>", "lnx-cashpresso-woocommerce");
					$this->settings["enabled"] = "no";
					update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings));
				}
			}
		}

		public function getCurrentLanguage() {
			if (get_bloginfo("language") == "de-DE") {
				return "de";
			}
			return "en";
		}

		public function processCallback() {
			$json = file_get_contents('php://input');

			$data = json_decode($json);

			//file_put_contents("/var/www/cashpresso/www/logging", $json . "\n\n", FILE_APPEND);

			if ($this->generateReceivingVerificationHash($this->getSecretKey(), $data->status, $data->referenceId, $data->usage) == $data->verificationHash) {
				//file_put_contents("/var/www/cashpresso/www/logging", $data->usage . "\n\n", FILE_APPEND);
				$order_id = intval(substr($data->usage, 6));

				$order = wc_get_order($order_id);

				//file_put_contents("/var/www/cashpresso/www/logging", $order_id . "\n\n", FILE_APPEND);
				//file_put_contents("/var/www/cashpresso/www/logging", $data->status . "\n\n", FILE_APPEND);

				switch ($data->status) {
				case "SUCCESS":
					$order->update_status('processing', $data->referenceId);
					break;
				case "CANCELLED":
					$order->update_status("failed", "cancelled");
					break;
				case "TIMEOUT":
					$order->update_status("failed", "expired");
					break;
				default:
					throw new Exception("Status not valid!");
					break;
				}
				echo "OK";
			} else {

				throw new Exception("Verification not valid!");
			}

			die();
		}

		public function admin_options() {
			echo '<h2>' . esc_html($this->get_method_title()) . '</h2>';

			echo wp_kses_post(wpautop($this->get_method_description()));

			if (isset($this->settings["partnerInfo"]) && $this->settings["partnerInfo"] !== "") {

				$obj = json_decode($this->settings["partnerInfo"], true);

				$content = "<table>";
				foreach ($obj as $key => $value) {
					if (is_array($value)) {
						$content .= "<tr><td>$key</td><td colspan='3'></td></tr>";
						foreach ($value as $k => $v) {
							if (is_array($v)) {
								$content .= "<tr><td></td><td>$k</td><td colspan='2'></td></tr>";
								foreach ($v as $x => $y) {
									$content .= "<tr><td></td><td></td><td>$x</td><td>$y</td></tr>";
								}
							} else {
								$content .= "<tr><td></td><td>$k</td><td colspan='2'>$v</td></tr>";
							}
						}
					} else {
						$content .= "<tr><td>$key</td><td colspan='2'>$value</td></tr>";
					}
				}

				$content .= "</table>";

				echo '<table width="100%"><tr><td style="background:#e0e0e0;border:1px solid #666;padding:20px;vertical-align:top;"><strong>Partner Info (' . $this->settings["partnerInfoTimestamp"] . ')</strong><br/> ' . $content . '</td></tr></table>';
			}

			echo '<table class="form-table">' . $this->generate_settings_html($this->get_form_fields(), false) . '</table>';

		}

		/**
		 * Initialise settings form fields.
		 *
		 * Add an array of fields to be displayed
		 * on the gateway's settings screen.
		 *
		 * @since  1.0.0
		 * @return string
		 */
		public function init_form_fields() {

			if (isset($_POST) && $this->getUrl() !== "") {

				$parameters = [];

				$parameters["partnerApiKey"] = $this->getApiKey();

				$url = $this->getUrl() . "/backend/ecommerce/v2/partnerInfo";

				$data = wp_remote_post($url, array(
					'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
					'body' => json_encode($parameters),
					'method' => 'POST',
				));

				if ($this->wasRequestSuccess($data)) {

					$obj = json_decode($data["body"], true);

					$this->settings["minPaybackAmount"] = $obj["minPaybackAmount"];
					$this->settings["interestFreeEnabled"] = $obj["interestFreeEnabled"];
					$this->settings["limitTotal"] = $obj["limit"]["total"];
					$this->settings["paybackRate"] = $obj["paybackRate"];
					$this->settings["interestFreeMaxDuration"] = $obj["interestFreeMaxDuration"];

					$this->settings["partnerInfo"] = $data["body"];
					$this->settings["partnerInfoTimestamp"] = strftime("%Y-%m-%d %H:%M:%S");

					update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings));
				}
			}

			$fields = array(
				'enabled' => array(
					'title' => __('Aktiviert/Deaktiviert', 'lnx-cashpresso-woocommerce'),
					'type' => 'checkbox',
					'label' => __('Aktiviere Cashpresso Zahlung', 'lnx-cashpresso-woocommerce'),
					'default' => 'yes',
				),
				'title' => array(
					'title' => __('Titel', 'lnx-cashpresso-woocommerce'),
					'type' => 'text',
					'description' => __('Namen der im Shop angezeigt wird', 'lnx-cashpresso-woocommerce'),
					'default' => __('Ratenkauf', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				'description' => array(
					'title' => __('Beschreibung', 'lnx-cashpresso-woocommerce'),
					'type' => 'textarea',
					'description' => __('Beschreibung der Zahlungsart', 'lnx-cashpresso-woocommerce'),
					'default' => __('cashpresso ermöglicht dir Einkäufe in Raten zu bezahlen. Deine Ratenhöhe kannst du dir beim Kauf aussuchen und später jederzeit ändern.', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				'instructions' => array(
					'title' => __('Weitere Schritte', 'lnx-cashpresso-woocommerce'),
					'type' => 'textarea',
					'description' => __('Weitere Schritte für die Danke Seite bzw. E-Mails', 'lnx-cashpresso-woocommerce'),
					'default' => __('Schließe deine Bestellung ab indem du deinen Einkauf mit cashpresso bezahlst. Durch Klick auf „Jetzt bezahlen“ öffnet sich ein Fenster und du kannst den Ratenkauf mit cashpresso abschließen. ', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				/*
					                  'test_secretkey' => array(
					                  'title' => __('TEST SecretKey', 'lnx-cashpresso-woocommerce'),
					                  'type' => 'text',
					                  'description' => __('TEST SecretKey', 'lnx-cashpresso-woocommerce'),
					                  'default' => __('', 'lnx-cashpresso-woocommerce'),
					                  'desc_tip' => true,
					                  ),
					                  'test_apikey' => array(
					                  'title' => __('TEST PartnerApiKey', 'lnx-cashpresso-woocommerce'),
					                  'type' => 'text',
					                  'description' => __('TEST PartnerApiKey', 'lnx-cashpresso-woocommerce'),
					                  'default' => __('', 'lnx-cashpresso-woocommerce'),
					                  'desc_tip' => true,
					                  ),
				*/
				/*
					                  'test_url' => array(
					                  'title' => __('TEST URL', 'lnx-cashpresso-woocommerce'),
					                  'type' => 'text',
					                  'description' => __('TEST URL', 'lnx-cashpresso-woocommerce'),
					                  'default' => __('https://test.cashpresso.com/rest', 'lnx-cashpresso-woocommerce'),
					                  'desc_tip' => true,
					                  ),
				*/
				'live_secretkey' => array(
					'title' => __('Secret Key', 'lnx-cashpresso-woocommerce'),
					'type' => 'text',
					'description' => __('Secret Key', 'lnx-cashpresso-woocommerce'),
					'default' => __('', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				'live_apikey' => array(
					'title' => __('Api Key', 'lnx-cashpresso-woocommerce'),
					'type' => 'text',
					'description' => __('Api Key', 'lnx-cashpresso-woocommerce'),
					'default' => __('', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				/*
					                  'live_url' => array(
					                  'title' => __('LIVE URL', 'lnx-cashpresso-woocommerce'),
					                  'type' => 'text',
					                  'description' => __('LIVE URL', 'lnx-cashpresso-woocommerce'),
					                  'default' => __('https://backend.cashpresso.com/rest', 'lnx-cashpresso-woocommerce'),
					                  'desc_tip' => true,
					                  ),
				*/
				'live_modus' => array(
					'title' => __(__('Modus'), 'lnx-cashpresso-woocommerce'),
					'type' => 'select',
					'options' => [__("live"), __("test")],
					'description' => __('Soll der Live Modus aktiv geschalten werden?', 'lnx-cashpresso-woocommerce'),
					'default' => __('live', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				'direct_checkout' => array(
					'title' => __('Direct checkout', 'lnx-cashpresso-woocommerce'),
					'type' => 'checkbox',
					'description' => __('Ermöglicht das Hinzufügen der Produkte zum Warenkorb sowie die Weiterleitung zum Checkout direkt aus dem cashpresso Overlay.', 'lnx-cashpresso-woocommerce'),
					'default' => __('', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				/*
					                  'bankUsage' => array(
					                  'title' => __('Verwendungszweck', 'lnx-cashpresso-woocommerce'),
					                  'type' => 'text',
					                  'description' => __('Scheint auf der Überweisung auf.', 'lnx-cashpresso-woocommerce'),
					                  'default' => __('', 'lnx-cashpresso-woocommerce'),
					                  'desc_tip' => true,
					                  ),
				*/
				'validUntil' => array(
					'title' => __('Gültigkeitsdauer', 'lnx-cashpresso-woocommerce'),
					'type' => 'number',
					'description' => __('Wie lange kann der Käufer den Prozess bei Cashpresso abschließen. Sie müssen solange die Ware vorhalten. (Angabe in Stunden).', 'lnx-cashpresso-woocommerce'),
					'default' => '336',
					'desc_tip' => true,
				),
				'productLevel' => array(
					'title' => __('Cashpresso auf Produktebene', 'lnx-cashpresso-woocommerce'),
					'type' => 'select',
					'options' => [__("deaktivieren"), __("dynamisch"), __("statisch")],
					'description' => __('Soll die Option der Ratenzahlung auf Produktebene angezeigt werden?', 'lnx-cashpresso-woocommerce'),
					'default' => __('', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),

				'productLabelLocation' => array(
					'title' => __('Platzierung auf Produktebene', 'lnx-cashpresso-woocommerce'),
					'type' => 'select',
					'options' => [__("keine"), __("Produktseite"), __("Produktseite & Katalog")],
					'description' => __('Wo soll es angezeigt werden?', 'lnx-cashpresso-woocommerce'),
					'default' => __('', 'lnx-cashpresso-woocommerce'),
					'desc_tip' => true,
				),
				/*
					'minPaybackAmount' => array(
						'title' => __('Mindestpreis pro Produkt', 'lnx-cashpresso-woocommerce'),
						'type' => 'number',
						'description' => __('Ab welchem Preis wird die Ratenzahlung direkt beim Produkt angezeigt.', 'lnx-cashpresso-woocommerce'),
						'default' => '25',
						'desc_tip' => true,
					),
					'limitTotal' => array(
						'title' => __('Maximalpreis pro Produkt', 'lnx-cashpresso-woocommerce'),
						'type' => 'number',
						'description' => __('Bis zu welchem Preis wird die Ratenzahlung angezeigt.', 'lnx-cashpresso-woocommerce'),
						'default' => '25',
						'desc_tip' => true,
					),
				*/
				/*
					                  'paybackRate' => array(
					                  'title' => __('Rückzahlrate', 'lnx-cashpresso-woocommerce'),
					                  'type' => 'number',
					                  'description' => __('aktuelle Rückzahlrate', 'lnx-cashpresso-woocommerce'),
					                  'default' => __('25', 'lnx-cashpresso-woocommerce'),
					                  'desc_tip' => true,
					                  ),
				*/
				'boost' => array(
					'title' => __('Hervorheben', 'lnx-cashpresso-woocommerce'),
					'type' => 'select',
					'description' => __('Schrift vergrößern', 'lnx-cashpresso-woocommerce'),
					'options' => ["80%", "100%", "120%"],
					'default' => '100% Schriftgröße',
					'desc_tip' => true,
				));

			if ($this->settings["interestFreeEnabled"]) {
				$fields['interestFreeDaysMerchant'] = array(
					'title' => __('zinsfreie Tage', 'lnx-cashpresso-woocommerce'),
					'type' => 'number',
					'description' => __('Zinsfreie Tage. Nur möglich wenn das Feature für diesen Account von cashpresso freigegeben wurde.', 'lnx-cashpresso-woocommerce'),
					'default' => '0',
					'desc_tip' => true,
				);
			}

			$this->form_fields = $fields;
		}

		public function isLive() {
			return ($this->live_modus == 0);
		}

		public function getMode() {
			if ($this->isLive()) {
				return "live";
			}
			return "test";
		}

		public function getSecretKey() {
			if ($this->isLive()) {
				return $this->live_secretkey;
			}
			return $this->live_secretkey;
		}

		public function getApiKey() {
			if ($this->isLive()) {
				return $this->live_apikey;
			}
			return $this->live_apikey;
		}

		public function getUrl() {
			if ($this->isLive()) {
				return "https://backend.cashpresso.com/rest"; // $this->live_url;
			}
			return "https://test.cashpresso.com/rest"; //$this->test_url;
		}

		public function getInterestFreeDaysMerchant() {
			if (!is_numeric($this->settings["interestFreeDaysMerchant"])) {
				return intval($this->settings["interestFreeDaysMerchant"]);
			}
			return $this->settings["interestFreeDaysMerchant"];
		}

		public function process_payment($order_id) {
			//file_put_contents("/var/www/cashpresso/www/logging", "START" . "\n\n", FILE_APPEND);

			$order = wc_get_order($order_id);

			$purchaseId = $this->sendBuyRequest($order);

			//file_put_contents("/var/www/cashpresso/www/logging", $purchaseId . "\n\n", FILE_APPEND);
			// Mark as on-hold (we're awaiting the payment)
			$order->update_status('pending', __('Kunde muss sich noch verifizieren.', 'lnx-cashpresso-woocommerce'));

			// Reduce stock levels
			$order->reduce_order_stock();

			// Remove cart
			WC()->cart->empty_cart();

			// Return thankyou redirect
			return array(
				'result' => 'success',
				'redirect' => $this->get_return_url($order),
			);
		}

		/**
		 * Output for the order received page.
		 */
		public function thankyou_page() {
			if ($this->instructions) {
				echo wpautop(wptexturize($this->instructions));
			}
		}

		public function sendBuyRequest($order) {

			$parameters = [];
//$order->get_total()
			$parameters["partnerApiKey"] = $this->getApiKey();
			$parameters["c2EcomId"] = $_POST["cashpressoToken"];
			$parameters["amount"] = floatval($order->calculate_totals());
			$parameters["verificationHash"] = $this->generateSendingVerificationHash($this->getSecretKey(), floatval($order->calculate_totals()), $this->getInterestFreeDaysMerchant(), "Order-" . $order->get_id(), null);
			$parameters["validUntil"] = date('c', mktime() + $this->validUntil * 3600);
			$parameters["bankUsage"] = "Order-" . $order->get_id();
			$parameters["interestFreeDaysMerchant"] = $this->getInterestFreeDaysMerchant();
			$parameters["callbackUrl"] = get_site_url() . "?wc-api=wc_gateway_cashpresso";

			$parameters["language"] = $this->getCurrentLanguage();

			$url = $this->getUrl() . "/backend/ecommerce/v2/buy";

			//file_put_contents("/var/www/cashpresso/www/loggingnow", serialize($parameters) . "\n\n", FILE_APPEND);

			$data = wp_remote_post($url, array(
				'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
				'body' => json_encode($parameters),
				'method' => 'POST',
			));

			//file_put_contents("/var/www/cashpresso/www/logging", serialize($data) . "\n\n", FILE_APPEND);

			if ($this->wasRequestSuccess($data)) {

				//file_put_contents("/var/www/cashpresso/www/logging", "SUCCESS" . "\n\n", FILE_APPEND);

				$obj = json_decode($data["body"]);
				$purchaseId = $obj->purchaseId;

				$order->add_meta_data("purchaseId", $purchaseId);
				$order->save_meta_data();

				//session_start();
				//$_SESSION["purchaseId"] = $purchaseId;

				return $purchaseId;
			} else {

				//file_put_contents("/var/www/cashpresso/www/logging", "NO SUCCESS" . "\n\n", FILE_APPEND);
				$obj = json_decode($data["body"]);
				wc_add_notice($obj->error->description, 'error');

				return false;
			}
		}

		public function wasRequestSuccess($data, $compareHash = false) {
			if (isset($data["body"])) {
				$obj = json_decode($data["body"]);
				if (is_object($obj)) {
					if (property_exists($obj, "success") && $obj->success === true) {
						return true;
					}
				}
			}
			return false;
		}

		public function generateSendingVerificationHash($secretKey, $amount, $interestFreeDaysMerchant, $bankUsage, $targetAccountId) {
			if (is_null($secretKey)) {
				$secretKey = "";
			}

			if (is_null($amount)) {
				$amount = "";
			}

			if (is_null($interestFreeDaysMerchant)) {
				$interestFreeDaysMerchant = 0;
			}

			if (is_null($bankUsage)) {
				$bankUsage = "";
			}

			if (is_null($targetAccountId)) {
				$targetAccountId = "";
			}

			$key = $secretKey . ";" . intval(intval($amount * 10) * 10) . ";" . $interestFreeDaysMerchant . ";" . $bankUsage . ";" . $targetAccountId;

			//file_put_contents("/var/www/cashpresso/www/logging", $key . "\n\n", FILE_APPEND);
			//file_put_contents("/var/www/cashpresso/www/logging", hash("sha512", $key) . "\n\n", FILE_APPEND);

			return hash("sha512", $key);
		}

		public function generateReceivingVerificationHash($secretKey, $status, $referenceId, $usage) {
			if (is_null($secretKey)) {
				$secretKey = "";
			}

			if (is_null($status)) {
				$status = "";
			}

			if (is_null($referenceId)) {
				$referenceId = "";
			}

			if (is_null($usage)) {
				$usage = "";
			}

			$key = $secretKey . ";" . $status . ";" . $referenceId . ";" . $usage;

			return hash("sha512", $key);
		}

		public function wc_cashpresso_js() {
			global $woocommerce;

			if (is_checkout() && !is_wc_endpoint_url('order-pay') && !is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) {

				echo '<script id="c2CheckoutScript" type="text/javascript"
		      src="https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_checkout.all.min.js"
		        data-c2-partnerApiKey="' . $this->getApiKey() . '"
		        data-c2-interestFreeDaysMerchant="' . $this->getInterestFreeDaysMerchant() . '"
		        data-c2-mode="' . $this->getMode() . '"
		        data-c2-locale="' . $this->getCurrentLanguage() . '"
		        data-c2-amount="' . $this->amount . '">
		    </script>
		    ';
			}
		}

		public function wc_cashpresso_js_footer() {
			global $woocommerce;

			if (is_checkout() && !is_wc_endpoint_url('order-pay') && !is_wc_endpoint_url('order-received') && !is_wc_endpoint_url('view-order')) {

				echo "<script>

			function syncData(){
				var foo = C2EcomCheckout.refreshOptionalData({
				     'email': document.getElementById('billing_email').value,
				     'given': document.getElementById('billing_first_name').value,
				     'family': document.getElementById('billing_last_name').value,
				     'country': document.getElementById('billing_country').value,
				     'city': document.getElementById('billing_city').value,
				     'zip': document.getElementById('billing_postcode').value,
				     'addressline': document.getElementById('billing_address_1').value,
				     'phone': document.getElementById('billing_phone').value
				   });
			}

		      document.getElementById('billing_email').addEventListener('change', syncData);
		      document.getElementById('billing_first_name').addEventListener('change', syncData);
		      document.getElementById('billing_last_name').addEventListener('change', syncData);
		      document.getElementById('billing_country').addEventListener('change', syncData);
		      document.getElementById('billing_city').addEventListener('change', syncData);
		      document.getElementById('billing_postcode').addEventListener('change', syncData);
		      document.getElementById('billing_address_1').addEventListener('change', syncData);
		      document.getElementById('billing_phone').addEventListener('change', syncData);


		    </script>
		    ";
				echo '<script>jQuery( document.body ).on( "updated_checkout", function( e ){

				if( window.location.href  == "' . wc_get_checkout_url() . '" ){
	C2EcomCheckout.refreshOptionalData({
     "email": jQuery("#billing_email").val(),
     "given": jQuery("#billing_first_name").val(),
     "family": jQuery("#billing_last_name").val(),
     "country": jQuery("#billing_country").val(),
     "city": jQuery("#billing_city").val(),
     "zip": jQuery("#billing_postcode").val(),
     "addressline": jQuery("#billing_address_1").val() + " " + jQuery("#billing_address_2").val(),
     "phone": jQuery("#billing_phone").val()
   });
}

if (window.C2EcomCheckout) {
      window.C2EcomCheckout.refresh();
    }


});</script>';
			}
		}

		public function wc_cashpresso_wizard($order) {
			global $woocommerce;

			if ($order->get_payment_method() == "cashpresso" && $order->get_status() == "pending") {

				$purchaseId = $order->get_meta("purchaseId");

				echo '<script id="c2PostCheckoutScript" type="text/javascript"
		    src="https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_post_checkout.all.min.js"
		    defer
		    data-c2-partnerApiKey="' . $this->getApiKey() . '"
		    data-c2-purchaseId="' . $purchaseId . '"
		    data-c2-mode="' . $this->getMode() . '"
		    data-c2-locale="' . $this->getCurrentLanguage() . '">
		  </script>';
			}
		}

		public function wc_cashpresso_thankyoutext($str, $order) {
			global $woocommerce;
			if ($order->get_payment_method() == "cashpresso" && $order->get_status() == "pending") {

				$purchaseId = $order->get_meta("purchaseId");
				$newString = "<div id='instructions'>" . $this->settings['instructions'];
				$newString .= "<br/><br/>";
				$newString .= '<script>function c2SuccessCallback(){ jQuery("#instructions").html("' . __("<p>Herzlichen Dank! Ihre Bezahlung wurde soeben freigegeben.</p>", "lnx-cashpresso-woocommerce") . '"); }</script><script id="c2PostCheckoutScript" type="text/javascript"
		    src="https://my.cashpresso.com/ecommerce/v2/checkout/c2_ecom_post_checkout.all.min.js"
		    defer
		    data-c2-partnerApiKey="' . $this->getApiKey() . '"
		    data-c2-purchaseId="' . $purchaseId . '"
		    data-c2-mode="' . $this->getMode() . '"
		    data-c2-successCallback="true"
		    data-c2-locale="' . $this->getCurrentLanguage() . '">
		  </script></div><br/>';
				return $newString;
			}
			return $str;
		}

		public function wc_cashpresso_add_banner($str) {
			if (is_admin()) {
				return str_replace('<div id="cashpresso-availability-banner"></div>', '', $str);
			} else {
				return $str;
			}
		}

	}

	// end \WC_Gateway_Offline class
}

add_action('plugins_loaded', 'wc_cashpresso_gateway_init', 11);
/*
function sanitizePrice($price) {
$stripped = strip_tags(removeTag($price, "del"));

var_dump( $stripped );
var_dump( preg_replace("/&#?[a-z0-9]+;/i", "", $stripped) );

return preg_replace("/&#?[a-z0-9]+;/i", "", $stripped);
}

function removeTag($str, $tag) {
$str = preg_replace("#\<" . $tag . "(.*)/" . $tag . ">#iUs", "", $str);
return $str;
}
 */

function product_level_integration($price) {

	$product = wc_get_product();

	$pricevalue = wc_get_price_including_tax($product);

	$settings = get_option('woocommerce_cashpresso_settings');

	if ($settings["productLabelLocation"] == 0) {
		return $price;
	}

	if ($settings["productLabelLocation"] == 1 && !is_product()) {
		return $price;
	}

	if ($settings['enabled'] !== "yes") {
		return $price;
	}

	$vat = "";

	if ($settings["productLevel"] == "1") {

		$limitTotal = floatval($settings["limitTotal"]);
		$minPaybackAmount = floatval($settings["minPaybackAmount"]);

		if ($pricevalue <= $limitTotal && $pricevalue >= $minPaybackAmount) {

			$size = "0.8em;";
			$class = "cashpresso_smaller";
			if ($settings["boost"] == "1") {
				$size = "1em;";
				$class = "cashpresso_normal";
			}
			if ($settings["boost"] == "2") {
				$size = "1.2em;";
				$class = "cashpresso_bigger";
			}
			$vat = ' <div id="dynamic' . rand() . '" class="c2-financing-label ' . $class . '" data-c2-financing-amount="' . number_format($pricevalue, 2, ".", "") . '" style="font-size:' . $size . '" onclick="setCheckoutUrl(\'' . wc_get_checkout_url() . '?add-to-cart=' . $product->id . '\');"><a href"#" ></a></div>';
		}
	}

	if ($settings["productLevel"] == "2") {

		$limitTotal = floatval($settings["limitTotal"]);
		$minPaybackAmount = floatval($settings["minPaybackAmount"]);

		if ($pricevalue <= $limitTotal && $pricevalue >= $minPaybackAmount) {

			$size = "0.8em;";
			$class = "cashpresso_smaller";
			if ($settings["boost"] == "1") {
				$size = "1em;";
				$class = "cashpresso_normal";
			}
			if ($settings["boost"] == "2") {
				$size = "1.2em;";
				$class = "cashpresso_bigger";
			}

			$paybackRate = $settings['paybackRate'];
			$minPaybackAmount = $settings['minPaybackAmount'];

			$vat = ' <div class="' . $class . '"><a href="#" style="font-size:' . $size . '" onclick="setCheckoutUrl(\'' . wc_get_checkout_url() . '?add-to-cart=' . $product->id . '\');C2EcomWizard.startOverlayWizard(' . number_format($pricevalue, 2, ".", "") . ')"> ' . __("ab", "lnx-cashpresso-woocommerce") . ' ' . number_format(getStaticRate($pricevalue, $paybackRate, $minPaybackAmount), 2) . ' € / ' . __("Monat", "lnx-cashpresso-woocommerce") . '</a></div>';
			//$vat = ' <div class="c2-financing-label ' . $class . '" data-c2-financing-amount="' . sanitizePrice($price) . '" style="font-size:' . $size . '"></div>';
		}
	}

	return $price . $vat;
}

function getStaticRate($price, $paybackRate, $minPaybackAmount) {
	return min(floatval($price), max(floatval($minPaybackAmount), floatval($price * 0.01 * $paybackRate)));
}

add_filter('woocommerce_get_price_html', 'product_level_integration');

function wc_cashpresso_label_js() {

	if (is_checkout() || is_view_order_page()) {
		return;
	}

	$product = wc_get_product();

	$settings = get_option('woocommerce_cashpresso_settings');
	$modus = "test";

	$locale = "en";
	if (get_bloginfo("language") == "de-DE") {
		$locale = "de";
	}
	$interestFreeDaysMerchant = $settings["interestFreeDaysMerchant"];

	$apiKey = $settings["live_apikey"];
	if ($settings["live_modus"] == "live") {
		$modus = "live";
		$apiKey = $settings["live_apikey"];
	}

	echo '<script>
var checkoutUrl = "' . wc_get_checkout_url() . '?add-to-cart=' . $product->id . '";

function setCheckoutUrl( url ){
	checkoutUrl = url;
}
if( jQuery("div.quantity > input").val() > 1 ){
		checkoutUrl = checkoutUrl + "&quantity=" + jQuery("div.quantity > input").val();
	}
	console.log( jQuery("div.quantity > input") );
	console.log( jQuery("div.quantity > input").val() );
	function c2Checkout( e ){

if( window.location.href  == "' . wc_get_checkout_url() . '" ){ console.log("c2Checkout request on rate change terminated."); } else {  window.location.href= checkoutUrl;} } </script>';

	echo '<script id="c2LabelScript" type="text/javascript"
src="https://my.cashpresso.com/ecommerce/v2/label/c2_ecom_wizard.all.min.js"
defer
data-c2-partnerApiKey="' . $apiKey . '"
data-c2-interestFreeDaysMerchant="' . $interestFreeDaysMerchant . '"
data-c2-mode="' . $modus . '"
data-c2-locale="' . $locale . '"';
	if ( /*is_product() && method_exists($product, "is_type") && $product->is_type('simple')) && */$settings['direct_checkout'] == 'yes') {

		echo ' data-c2-checkoutCallback="true" ';
	}
	echo '></script>';

	if ($settings["productLevel"] == "2") {
		echo '<script id="c2StaticLabelScript" type="text/javascript"
		    src="https://my.cashpresso.com/ecommerce/v2/label/c2_ecom_wizard_static.all.min.js"
		    defer
		    data-c2-partnerApiKey="' . $apiKey . '"
		    data-c2-interestFreeDaysMerchant="' . $interestFreeDaysMerchant . '"
		    data-c2-mode="' . $modus . '"
			data-c2-locale="' . $locale . '"';
		if ( /* is_product() && $product->is_type('simple') && */$settings['direct_checkout'] == 'yes') {
			echo ' data-c2-checkoutCallback="true" ';
		}
		echo '</script>';
	}

	echo '<script>jQuery(document).ready(function() {
		jQuery( ".single_variation_wrap" ).on( "show_variation", function ( event, variation ) {
		    if (window.C2EcomWizard) {
		      window.C2EcomWizard.refreshAmount("dynamic", variation.display_price.toFixed(2) );
		    }
		} )});</script>';
}

add_action('wp_head', 'wc_cashpresso_label_js');

function plugin_init() {
	load_plugin_textdomain('lnx-cashpresso-woocommerce', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

add_action('plugins_loaded', 'plugin_init');