<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooCommerce_CentralDoFrete_Method')) :

    /**
     * WooCommerce_CentralDoFrete_Method class.
     */
    class WooCommerce_CentralDoFrete_Method extends WC_Shipping_Method
    {
        const CARGO_TYPES_OPTION_NAME = 'centraldofrete_cargotypes';

        /**
         * @var WooCommerce_CentralDoFrete_Helper
         */
        private $helper;
        /**
         * @var string
         */
        private $zip_origin;
        /**
         * @var int
         */
        private $default_height;
        /**
         * @var int
         */
        private $default_length;
        /**
         * @var int
         */
        private $default_width;
        /**
         * @var double
         */
        private $default_weight;
        /**
         * @var boolean
         */
        private $sandbox;
        /**
         * @var boolean
         */
        private $display_date;
        /**
         * @var int
         */
        private $additional_time;
        /**
         * @var boolean
         */
        private $debug;
        /**
         * @var string
         */
        private $token;

        /**
         * Initialize the Central do Frete shipping method.
         *
         * @param int $instance_id
         */
        public function __construct($instance_id = 0)
        {
            $this->id = 'centraldofrete';
            $this->instance_id = absint($instance_id);
            $this->method_title = __('Central do Frete', 'woo-central-do-frete');
            $this->supports = array('shipping-zones', 'instance-settings');
            $this->helper = new WooCommerce_CentralDoFrete_Helper();
            $this->init();
        }

        /**
         * Initializes the method.
         * Fill fields with stored values
         * @return void
         */
        public function init()
        {
            // Load the form fields.
            $this->init_form_fields();
            // Load the settings.
            $this->init_settings();
            // Define user set variables.
            $this->fillPropertiesWithStoredValues();
            // Actions.
            add_action(
                'woocommerce_update_options_shipping_' . $this->id,
                array(
                    $this,
                    'process_admin_options'
                )
            );
            $this->needRecordCargoTypes();
        }

        /**
         * Admin options fields.
         *
         * @return void
         */
        public function init_form_fields()
        {
            $this->instance_form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woo-central-do-frete'),
                    'type' => 'checkbox',
                    'label' => __('Enable this shipping method', 'woo-central-do-frete'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woo-central-do-frete'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'default' => __('Central do Frete', 'woo-central-do-frete')
                ),
                'display_date' => array(
                    'title' => __('Estimated delivery', 'woo-central-do-frete'),
                    'type' => 'checkbox',
                    'label' => __('Enable', 'woo-central-do-frete'),
                    'description' => __('Display date of estimated delivery.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'default' => 'yes'
                ),
                'additional_time' => array(
                    'title' => __('Additional days', 'woo-central-do-frete'),
                    'type' => 'text',
                    'description' => __('Additional days to the estimated delivery.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'default' => '0',
                    'placeholder' => '0'
                ),
                'token' => array(
                    'title' => __('Token', 'woo-central-do-frete'),
                    'type' => 'password',
                    'description' => __('Your Central do Frete access token.', 'woo-central-do-frete'),
                    'desc_tip' => true
                ),
                'package_standard' => array(
                    'title' => __('Default Measures', 'woo-central-do-frete'),
                    'type' => 'title',
                    'description' => __('Sets a default measure for the fields when it does not have value.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                ),
                'default_height' => array(
                    'title' => __('Default Height', 'woo-central-do-frete'),
                    'type' => 'text',
                    'description' => __('Default height of the package. Central do Frete needs at least 2 cm.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'default' => '2'
                ),
                'default_width' => array(
                    'title' => __('Default Width', 'woo-central-do-frete'),
                    'type' => 'text',
                    'description' => __('Default width of the package. Central do Frete needs at least 11 cm.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'default' => '11'
                ),
                'default_length' => array(
                    'title' => __('Default Length', 'woo-central-do-frete'),
                    'type' => 'text',
                    'description' => __('Default length of the package. Central do Frete needs at least 16 cm.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'default' => '16'
                ),
                'default_weight' => array(
                    'title' => __('Default Weight', 'woo-central-do-frete'),
                    'type' => 'text',
                    'description' => __('Default weight of the package. Central do Frete needs at least 0.3 kg (300g).', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'default' => '0.3'
                ),
                'default_cargo_type' => array(
                    'title' => __('Default Cargo Type', 'woocommerce'),
                    'type' => 'select',
                    'description' => __('Select default shipping cargo type.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                    'options' => self::getCargoTypesOptions()
                ),
                'developer' => array(
                    'title' => __('Developers', 'woo-central-do-frete'),
                    'type' => 'title',
                    'description' => __('Control logs and requests environment.', 'woo-central-do-frete'),
                    'desc_tip' => true,
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'woo-central-do-frete'),
                    'type' => 'checkbox',
                    'label' => __('Enable logging', 'woo-central-do-frete'),
                    'default' => 'no',
                    'description' => sprintf(__('Log Central do Frete events, such as requests inside %s.', 'woo-central-do-frete'), '<code>wc-logs/centraldofrete-' . sanitize_file_name(date('Y-m-d') . wp_hash('centraldofrete')) . '.log</code>')
                ),
                'sandbox' => array(
                    'title' => __('Sandbox', 'woo-central-do-frete'),
                    'type' => 'checkbox',
                    'label' => __('Enable sandbox', 'woo-central-do-frete'),
                    'default' => 'no',
                    'description' => __('Make requests to sandbox endpoint', 'woo-central-do-frete')
                )
            );
            $this->form_fields = $this->instance_form_fields;
        }

        /**
         * Get the database properties and fill in the form fields with their respective values
         */
        private function fillPropertiesWithStoredValues()
        {
            $this->enabled = $this->get_option('enabled');
            $this->title = $this->get_option('title');
            $this->zip_origin = $this->fixZipCode(get_option('woocommerce_store_postcode'));
            $this->default_height = $this->get_option('default_height');
            $this->default_length = $this->get_option('default_length');
            $this->default_width = $this->get_option('default_width');
            $this->default_weight = $this->get_option('default_weight');
            $this->sandbox = $this->get_option('sandbox');
            $this->display_date = $this->get_option('display_date');
            $this->additional_time = $this->get_option('additional_time');
            $this->debug = $this->get_option('debug');
            $this->token = $this->get_option('token');
        }

        /**
         * Check if is necessary save cargo types in db
         */
        private function needRecordCargoTypes()
        {
            if (!$this->hasCargoTypes()) {
                if ($this->hasValidCredentials()) {
                    $this->getAndSaveCargoTypes();
                }
            }
        }

        /**
         * Check if has cargo types stored in db
         * @return bool
         */
        private function hasCargoTypes()
        {
            $cargo_types = get_option(self::CARGO_TYPES_OPTION_NAME);
            if ($cargo_types == false) {
                return false;
            }
            if (count($cargo_types)) {
                return true;
            }
            return false;
        }

        /**
         * Check if API token is valid
         * @return bool
         */
        private function hasValidCredentials()
        {
            $requestsArgument = ['method' => 'GET', 'headers' => $this->helper->getRequestHeaders()];
            $quote_endpoint = $this->helper->getQuotesURL();
            $quote_response = wp_remote_get($quote_endpoint, $requestsArgument);
            if (isset($quote_response['response']) && $quote_response['response']['code'] == 200) {
                return true;
            }
            return false;
        }

        /**
         * Check if cargo type has parent (cargo_type_id)
         * @param $cargoType
         * @return bool
         */
        private static function cargoTypeIsParent($cargoType)
        {
            return is_null($cargoType->cargo_type_id) || $cargoType->cargo_type_id == 0;
        }

        /**
         * Provide cargo type array, used on product form and method configuration
         * @return array
         */
        private static function getCargoTypesOptions()
        {
            return array_replace(
                array(
                    '' => __('Select a cargo type', 'woo-central-do-frete'),
                ),
                self::getCargoTypes()
            );
        }

        /**
         * Central do Frete options page.
         *
         * @return void
         */
        public function admin_options()
        {
            echo '<h3>' . $this->method_title . '</h3>';
            echo '<p>' . __('Central do Frete is a brazilian delivery method.', 'woo-central-do-frete') . '</p>';
            echo '<table class="form-table">';
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         * Checks if the method is available.
         *
         * @param array $package Order package.
         *
         * @return bool
         */
        public function is_available($package)
        {
            $is_available = true;
            if ('no' == $this->enabled) {
                $is_available = false;
            }
            return apply_filters('woocommerce_shipping_' . $this->id . '_is_available', $is_available, $package);
        }

        /**
         * Replace comma by dot.
         * @param mixed $value
         * @return mixed
         */
        private function fixFormat($value)
        {
            $value = str_replace(',', '.', $value);
            return $value;
        }

        /**
         * Fix Zip Code format.
         * @param mixed $zip
         * @return int
         */
        protected function fixZipCode($zip)
        {
            return preg_replace('([^0-9])', "", $zip);
        }

        /**
         * Calculates the shipping rate.
         * @param array $package Order package.
         * @return void
         */
        public function calculate_shipping($package = array())
        {
            $rates = array();
            if (isset($this->token) && $this->token != "") {
                $shipping_values = $this->collectRates($package);
            } else {
                return false;
            }
            if (!empty($shipping_values)) {
                foreach ($shipping_values as $code => $shipping) {
                    if (!isset($shipping->price)) {
                        continue;
                    }
                    $label = "";
                    if (isset($shipping->shipping_carrier)) {
                        $label = $shipping->shipping_carrier;
                    }
                    $date = 0;
                    if (isset($shipping->delivery_time)) {
                        $date = $shipping->delivery_time;
                    }
                    if (('yes' == $this->display_date)) {
                        $label = $this->estimationDelivery($label, $date, $this->additional_time);
                    }
                    $cost = floatval(str_replace(",", ".", (string)$shipping->price));
                    $cdf_quotation_code = $shipping->cdf_quotation_code;
                    array_push($rates, array(
                        'id' => 'CDF_' . $shipping->id,
                        'label' => $label,
                        'cost' => $cost,
                        'meta_data' => array(
                            'CDF_ID' => 'CDF_' . $shipping->id,
                            'CDF_QUOTATION' => $cdf_quotation_code
                        )
                    ));
                }
                foreach ($rates as $rate) {
                    $this->add_rate($rate);
                }
            }
        }

        /**
         * Estimating Delivery.
         * @param string $label
         * @param string $date
         * @param int $additional_time
         * @return string
         */
        protected function estimationDelivery($label, $date, $additional_time = 0)
        {
            $name = $label;
            $additional_time = intval($additional_time);
            if ($additional_time > 0) {
                $date += intval($additional_time);
            }
            if ($date > 0) {
                $name .= ' (' . sprintf(_n('Delivery in %d day', 'Delivery in %d days', $date, 'woo-central-do-frete'), $date) . ')';
            }
            return $name;
        }

        /**
         * Calculate shipping at Central do Frete API
         * @param array $package
         * @return array
         */
        protected function collectRates($package)
        {
            $methods = array();
            $quotationData = [];
            try {
                if (!$this->helper->isValidDestination($package, $this) == false) {
                    return $methods;
                }
                $shipmentInvoiceValue = 0;
                // Shipping per item.
                foreach ($package['contents'] as $item_id => $values) {
                    $product = $values['data'];

                    $lastCargoType = null;
                    $productCargoType = $this->getCargoTypeByProductId($product->get_id());
                    if ($productCargoType) {
                        $lastCargoType = $productCargoType;
                    } else {
                        $lastCargoType = $this->helper->getDefaultCargoType();
                    }
                    $quotationData['cargo_types'][] = $lastCargoType;

                    $qty = $values['quantity'];
                    if ($qty > 0 && $product->needs_shipping()) {
                        if (version_compare(WOOCOMMERCE_VERSION, '3.0', '>=')) {
                            $_height = wc_get_dimension($this->fixFormat($product->get_height()), 'cm');
                            $_width = wc_get_dimension($this->fixFormat($product->get_width()), 'cm');
                            $_length = wc_get_dimension($this->fixFormat($product->get_length()), 'cm');
                            $_weight = wc_get_weight($this->fixFormat($product->get_weight()), 'kg');
                        } else if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>=')) {
                            $_height = wc_get_dimension($this->fixFormat($product->height), 'cm');
                            $_width = wc_get_dimension($this->fixFormat($product->width), 'cm');
                            $_length = wc_get_dimension($this->fixFormat($product->length), 'cm');
                            $_weight = wc_get_weight($this->fixFormat($product->weight), 'kg');
                        } else {
                            $_height = woocommerce_get_dimension($this->fixFormat($product->height), 'cm');
                            $_width = woocommerce_get_dimension($this->fixFormat($product->width), 'cm');
                            $_length = woocommerce_get_dimension($this->fixFormat($product->length), 'cm');
                            $_weight = woocommerce_get_weight($this->fixFormat($product->weight), 'kg');
                        }
                        if (empty($_height)) $_height = $this->default_height;
                        if (empty($_width)) $_width = $this->default_width;
                        if (empty($_length)) $_length = $this->default_length;
                        if (empty($_weight)) $_weight = $this->default_weight;
                        $shipmentInvoiceValue += $product->get_price() * $qty;
                        $quotationData['volumes'][] = ["quantity" => $qty, "width" => $_width, "height" => $_height, "length" => $_length, "weight" => $_weight];
                        if (version_compare(WOOCOMMERCE_VERSION, '3.0', '>=')) {
                            if ($product->get_parent_id()) {
                                $terms = wp_get_post_terms($product->get_parent_id(), 'product_cat');
                            } else {
                                $terms = wp_get_post_terms($product->get_id(), 'product_cat');
                            }
                        } else {
                            if ($product->parent_id) {
                                $terms = wp_get_post_terms($product->parent_id, 'product_cat');
                            } else {
                                $terms = wp_get_post_terms($product->id, 'product_cat');
                            }
                        }
                        $categories = "";
                        foreach ($terms as $term) {
                            $categories = $categories . $term->slug . '|';
                        }
                    }
                }
                $shipmentInvoiceValue = WC()->cart->cart_contents_total;
                $quotationData['invoice_amount'] = $shipmentInvoiceValue;
                $quotationData['from'] = $this->fixZipCode($this->zip_origin);
                $quotationData['to'] = $this->fixZipCode($package['destination']['postcode']);
                $quotationData['cargo_types'] = array_unique($quotationData['cargo_types']);
                $requestsArgument = ['method' => 'POST', 'headers' => $this->helper->getRequestHeaders(), 'body' => json_encode($quotationData)];
                $quote_endpoint = $this->helper->getQuotesURL();
                $quote_response = wp_remote_post($quote_endpoint, $requestsArgument);
                if (!is_wp_error($quote_response)) {
                    if (isset($quote_response['body'])) {
                        $requestsArgument = ['method' => 'GET', 'headers' => $this->helper->getRequestHeaders(),];
                        $current_quote = json_decode($quote_response['body']);
                        $current_quote_code = $current_quote->code;
                        $quote_details_endpoint = $this->helper->getQuotesURL($current_quote_code);
                        $quote_details_response = wp_remote_get($quote_details_endpoint, $requestsArgument);
                        if (is_wp_error($quote_details_response)) {
                        } else {
                            $quote_details_response_data = json_decode($quote_details_response['body']);
                            if (!empty($quote_details_response_data->prices)) {
                                $services = $quote_details_response_data->prices;
                                foreach ($services as $service) {
                                    if (!isset($service->id) || !isset($service->price)) {
                                        continue;
                                    }
                                    $code = (string)$service->id;
                                    $service->cdf_quotation_code = $current_quote_code;
                                    $methods[$code] = $service;
                                }
                            }
                        }
                    }
                } else {
                }
            } catch (Exception $e) {
            }
            return $methods;
        }

        /**
         * Get stored cargo types, formatted like PARENT >> CHILD, ordered by parent name asc
         * @return array
         */
        private static function getCargoTypes()
        {
            $cargo_types = get_option(self::CARGO_TYPES_OPTION_NAME);
            if ($cargo_types == false) {
                return [];
            }
            $cargos = [];
            $parents = [];
            foreach ($cargo_types as $cargo) {
                if (self::cargoTypeIsParent($cargo)) {
                    $parents[$cargo->id] = $cargo->name;
                }
            }

            foreach ($cargo_types as $cargo) {
                if (!self::cargoTypeIsParent($cargo)) {
                    $parent = $parents[$cargo->cargo_type_id];
                    $cargos[$cargo->id] = $parent . " >> " . $cargo->name;
                }
            }
            asort($cargos);
            return $cargos;
        }

        /**
         * Get cargo types from API and save in db
         * @return bool
         */
        private function getAndSaveCargoTypes()
        {
            $request_data = ['method' => 'GET', 'headers' => $this->helper->getRequestHeaders()];
            $cargo_types_endpoint = $this->helper->getCargoTypesURL();
            $quote_response = wp_remote_get($cargo_types_endpoint, $request_data);
            $response_data = json_decode($quote_response['body']);
            if (is_array($response_data)) {
                update_option(self::CARGO_TYPES_OPTION_NAME, $response_data);
                return true;
            }
            return false;
        }

        /**
         * Provide the cargo_type field to product form
         */
        public static function addCustomShippingOptionToProductForm()
        {
            global $product_object;
            $product_id = method_exists($product_object, 'get_id') ? $product_object->get_id() : $product_object->id;
            echo '</div><div class="options_group">';

            $product_cargo_type = self::getCargoTypeByProductId($product_id);

            woocommerce_wp_select(
                array(
                    'id' => 'cargo_type',
                    'label' => __('Cargo Type', 'woo-central-do-frete'),
                    'options' => self::getCargoTypesOptions(),
                    'desc_tip' => true,
                    'description' => __('Este campo é necessário para o cálculo de frete usando a Central do Frete', 'woo-central-do-frete'),
                    'value' => $product_cargo_type,
                )
            );
        }

        /**
         * Save the custom field
         * @since 1.0.0
         */
        public static function saveCustomField($post_id)
        {
            $product = wc_get_product($post_id);
            $title = isset($_POST['cargo_type']) ? $_POST['cargo_type'] : '';
            $product->update_meta_data('cargo_type', sanitize_text_field($title));
            $product->save();
        }

	    /**
	     * Get cargo type id by product id
	     * @param $productId
	     * @return mixed
	     */
	    public static function getCargoTypeByProductId($productId)
	    {
		    return get_post_meta($productId, 'cargo_type', true);
	    }

    }
endif;