<?php

/**
 * Woo_CentralDoFrete class.
 */
class Woo_CentralDoFrete extends WC_Shipping_Method
{
    const CARGO_TYPES_OPTION_KEY = 'centraldofrete_cargotypes';
    private $quoteByProduct = false;

    /**
     * Initialize the Central do Frete shipping method.
     *
     * @return void
     */
    public function __construct($instance_id = 0)
    {
        $this->id = 'centraldofrete';
        $this->instance_id = absint($instance_id);
        $this->method_title = __('Central do Frete', 'woo-central-do-frete');
        $this->supports = array(
            'shipping-zones',
            'instance-settings'
        );
        $this->helper = new Woo_CentralDoFrete_Helper();
        $this->init();
    }

    /**
     * Initializes the method.
     *
     * @return void
     */
    public function init()
    {
        // Load the form fields.
        $this->init_form_fields();
        // Load the settings.
        $this->init_settings();
        // Define user set variables.
        $this->enabled = $this->get_option('enabled');
        $this->title = $this->get_option('title');
        $this->zip_origin = $this->fix_zip_code(get_option('woocommerce_store_postcode'));
        $this->default_height = $this->get_option('default_height');
        $this->default_length = $this->get_option('default_length');
        $this->default_width = $this->get_option('default_width');
        $this->default_weight = $this->get_option('default_weight');
        $this->sandbox = $this->get_option('sandbox');
        $this->display_date = $this->get_option('display_date');
        $this->additional_time = $this->get_option('additional_time');
        $this->debug = $this->get_option('debug');
        $this->token = $this->get_option('token');
        // Actions.
        add_action('woocommerce_update_options_shipping_' . $this->id, array(
            $this,
            'process_admin_options'
        ));
        if (!$this->has_cargo_types()) {
            if ($this->has_valid_credentials()) {
                $this->fill_cargo_types();
            }
        }
    }

    private static function isParent($cargo)
    {
        return is_null($cargo->cargo_type_id) || $cargo->cargo_type_id == 0;
    }

    /**
     * @return array
     */
    private static function getCargoTypesOptions()
    {
        $options = array_replace(
            array(
                '' => __('Select a cargo type', 'woo-central-do-frete'),
            ),
            self::get_cargo_types()
        );
        return $options;
    }

    /**
     * Backwards compatibility with version prior to 2.1.
     *
     * @return object Returns the main instance of WooCommerce class.
     */
    protected function woocommerce_method()
    {
        if (function_exists('WC')) {
            return WC();
        } else {
            global $woocommerce;
            return $woocommerce;
        }
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
                'default' => 'flat_rate',
                'desc_tip' => true,
                'options' => self::getCargoTypesOptions()
            ),
            'debug' => array(
                'title' => __('Debug Log', 'woo-central-do-frete'),
                'type' => 'checkbox',
                'label' => __('Enable logging', 'woo-central-do-frete'),
                'default' => 'no',
                'description' => sprintf(__('Log Central do Frete events, such as WebServices requests, inside %s.', 'woo-central-do-frete'), '<code>woocommerce/logs/shipping-' . sanitize_file_name(wp_hash('centraldofrete')) . '.txt</code>')
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
     *
     * @param mixed $value Value to fix.
     *
     * @return mixed
     */
    private function fix_format($value)
    {
        $value = str_replace(',', '.', $value);
        return $value;
    }

    /**
     * Fix Zip Code format.
     *
     * @param mixed $zip Zip Code.
     *
     * @return int
     */
    protected function fix_zip_code($zip)
    {
        return preg_replace('([^0-9])', "", $zip);
    }

    /**
     * Calculates the shipping rate.
     *
     * @param array $package Order package.
     *
     * @return void
     */
    public function calculate_shipping($package = array())
    {
        $rates = array();
        if (isset($this->token) && $this->token != "") {
            $shipping_values = $this->collect_rates($package);
        } else {
            return false;
        }
        $this->helper->write_log($this->id, json_encode($shipping_values) . __LINE__);
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
                    $label = $this->estimating_delivery($label, $date, $this->additional_time);
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
                $this->helper->write_log($this->id, print_r($rates, true) . " " . __LINE__);
            }
            foreach ($rates as $rate) {
                $this->add_rate($rate);
            }
        }
    }

    /**
     * Estimating Delivery.
     *
     * @param string $label
     * @param string $date
     * @param int $additional_time
     *
     * @return string
     */
    protected function estimating_delivery($label, $date, $additional_time = 0)
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
    protected function collect_rates($package)
    {
        $methods = array();
        $quotationData = [];
        try {
            $destination_postcode = $package['destination']['postcode'];
            $destination_country = $package['destination']['country'];
            if (!$this->helper->checkDestinationInfo($package, $this)) {
                return $methods;
            }
            $shipmentInvoiceValue = 0;
            // Shipping per item.
            foreach ($package['contents'] as $item_id => $values) {
                $product = $values['data'];

                $lastCargoType = null;
                $productCargoType = self::get_cargo_type_by_product_id($product->get_id());
                if ($productCargoType) {
                    $lastCargoType = $productCargoType;
                    $this->helper->write_log($this->get_instance_id(), 'Central do Frete:: cargo type do produto ' . $lastCargoType . " " . __LINE__);
                } else {
                    $lastCargoType = $this->helper->get_default_cargo_type();
                    $this->helper->write_log($this->get_instance_id(), 'Central do Frete:: cargo type padrão ' . $lastCargoType . " " . __LINE__);
                }
                $quotationData['cargo_types'][] = $lastCargoType;

                $qty = $values['quantity'];
                if ($qty > 0 && $product->needs_shipping()) {
                    if (version_compare(WOOCOMMERCE_VERSION, '3.0', '>=')) {
                        $_height = wc_get_dimension($this->fix_format($product->get_height()), 'cm');
                        $_width = wc_get_dimension($this->fix_format($product->get_width()), 'cm');
                        $_length = wc_get_dimension($this->fix_format($product->get_length()), 'cm');
                        $_weight = wc_get_weight($this->fix_format($product->get_weight()), 'kg');
                    } else if (version_compare(WOOCOMMERCE_VERSION, '2.1', '>=')) {
                        $_height = wc_get_dimension($this->fix_format($product->height), 'cm');
                        $_width = wc_get_dimension($this->fix_format($product->width), 'cm');
                        $_length = wc_get_dimension($this->fix_format($product->length), 'cm');
                        $_weight = wc_get_weight($this->fix_format($product->weight), 'kg');
                    } else {
                        $_height = woocommerce_get_dimension($this->fix_format($product->height), 'cm');
                        $_width = woocommerce_get_dimension($this->fix_format($product->width), 'cm');
                        $_length = woocommerce_get_dimension($this->fix_format($product->length), 'cm');
                        $_weight = woocommerce_get_weight($this->fix_format($product->weight), 'kg');
                    }
                    if (empty($_height)) $_height = $this->default_height;
                    if (empty($_width)) $_width = $this->default_width;
                    if (empty($_length)) $_length = $this->default_length;
                    if (empty($_weight)) $_weight = $this->default_weight;
                    $shipmentInvoiceValue += $product->get_price() * $qty;
                    $quotationData['volumes'][] = ["quantity" => $qty, "width" => $_width, "height" => $_height, "length" => $_length, "weight" => $_weight];
                    // wp_get_post_terms( your_id, 'product_cat' );
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
            $this->helper->write_log($this->id, 'CEP ' . $package['destination']['postcode'] . " " . __LINE__);
            if (!$this->quoteByProduct) {
                $shipmentInvoiceValue = WC()->cart->cart_contents_total;
            }
            $quotationData['invoice_amount'] = $shipmentInvoiceValue;
            $quotationData['from'] = $this->fix_zip_code($this->zip_origin);
            $quotationData['to'] = $this->fix_zip_code($destination_postcode);
            $quotationData['cargo_types'] = array_unique($quotationData['cargo_types']);
            $this->helper->write_log($this->id, 'Requesting the Central do Frete WebServices...' . " " . __LINE__);
            $this->helper->write_log($this->id, print_r($quotationData, true) . " " . __LINE__);
            $requestsArgument = ['method' => 'POST', 'headers' => $this->helper->get_request_headers(), 'body' => json_encode($quotationData)];
            $quote_endpoint = $this->helper->get_quotes_endpoint_url();
            $quote_response = wp_remote_post($quote_endpoint, $requestsArgument);
            $this->helper->write_log($this->id, $quote_endpoint . " " . __LINE__);
            $this->helper->write_log($this->id, json_encode($requestsArgument) . " " . __LINE__);
            if (!is_wp_error($quote_response)) {
                if (isset($quote_response['body'])) {
                    $requestsArgument = ['method' => 'GET', 'headers' => $this->helper->get_request_headers(),];
                    $current_quote = json_decode($quote_response['body']);
                    $current_quote_code = $current_quote->code;
                    $quote_details_endpoint = $this->helper->get_quotes_endpoint_url($current_quote_code);
                    $quote_details_response = wp_remote_get($quote_details_endpoint, $requestsArgument);
                    $this->helper->write_log($this->id, "Quotation code " . $current_quote_code . " " . __LINE__);
                    $this->helper->write_log($this->id, $quote_details_endpoint . " " . __LINE__);
                    $this->helper->write_log($this->id, json_encode($requestsArgument) . " " . __LINE__);
                    if (is_wp_error($quote_details_response)) {
                        if ('yes' == $this->debug) {
                            $this->helper->write_log($this->id, 'WP_Error: ' . $quote_details_response->get_error_message() . " " . __LINE__);
                        }
                    } else {
                        $quote_details_response_data = json_decode($quote_details_response['body']);
                        if (!empty($quote_details_response_data->prices)) {
                            $services = $quote_details_response_data->prices;
                            foreach ($services as $service) {
                                $this->helper->write_log($this->id, 'Serviços encontrados:' . " " . __LINE__);
                                if (!isset($service->id) || !isset($service->price)) {
                                    continue;
                                }
                                $code = (string)$service->id;
                                $this->helper->write_log($this->id, 'API Results [' . $service->shipping_carrier . ']: ' . print_r($service, true) . " " . __LINE__);
                                $service->cdf_quotation_code = $current_quote_code;
                                $methods[$code] = $service;
                            }
                        }
                    }
                }
            } else {
                $this->helper->write_log($this->id, 'WP_Error: ' . $quote_response->get_error_message() . " " . __LINE__);
            }
        } catch (Exception $e) {
            $this->helper->write_log($this->id, var_dump($e->getMessage()) . " " . __LINE__);
        }
        $this->helper->write_log($this->id, print_r("Métodos de envio encontrados", true) . " " . __LINE__);
        $this->helper->write_log($this->id, print_r($methods, true) . " " . __LINE__);
        return $methods;
    }

    private function has_valid_credentials()
    {
        $requestsArgument = ['method' => 'GET', 'headers' => $this->helper->get_request_headers()];
        $quote_endpoint = $this->helper->get_quotes_endpoint_url();
        $quote_response = wp_remote_get($quote_endpoint, $requestsArgument);
        if (!is_wp_error($quote_response)) {
            return true;
        }
    }

    private function has_cargo_types()
    {
        $cargo_types = get_option(self::CARGO_TYPES_OPTION_KEY);
        if ($cargo_types && count($cargo_types)) {
            return true;
        }
        return false;
    }

    private static function get_cargo_types()
    {
        $cargo_types = get_option(self::CARGO_TYPES_OPTION_KEY);
        $cargos = [];
        $parents = [];
        foreach ($cargo_types as $cargo) {
            if (self::isParent($cargo)) {
                $parents[$cargo->id] = $cargo->name;
            }
        }

        foreach ($cargo_types as $cargo) {
            if (!self::isParent($cargo)) {
                $parent = $parents[$cargo->cargo_type_id];
                $cargos[$cargo->id] = $parent . " >> " . $cargo->name;
            }
        }
        asort($cargos);
        return $cargos;
    }

    private function fill_cargo_types()
    {
        $request_data = ['method' => 'GET', 'headers' => $this->helper->get_request_headers()];
        $cargo_types_endpoint = $this->helper->get_cargo_types_endpoint_url();
        $quote_response = wp_remote_get($cargo_types_endpoint, $request_data);
        $this->helper->write_log($this->id, $cargo_types_endpoint . " " . __LINE__);
        $this->helper->write_log($this->id, json_encode($request_data) . " " . __LINE__);
        if (!is_wp_error($quote_response)) {
            if (isset($quote_response['body'])) {
                update_option(self::CARGO_TYPES_OPTION_KEY, json_decode($quote_response['body']));
                return true;
            }
        }
        return false;
    }

    public static function add_custom_shipping_option_to_products()
    {
        global $product_object;
        $product_id = method_exists($product_object, 'get_id') ? $product_object->get_id() : $product_object->id;
        echo '</div><div class="options_group">';

        $product_cargo_type = self::get_cargo_type_by_product_id($product_id);

        woocommerce_wp_select(
            array(
                'id' => 'cargo_type',
                'label' => __('Cargo Type', 'woocommerce'),
                'options' => self::getCargoTypesOptions(),
                'desc_tip' => true,
                'description' => __('Este campo é necessário para o cálculo de frete usando a Central do Frete', 'woocommerce'),
                'value' => $product_cargo_type,
            )
        );

    }

    /**
     * Save the custom field
     * @since 1.0.0
     */
    public static function save_custom_field($post_id)
    {
        $product = wc_get_product($post_id);
        $title = isset($_POST['cargo_type']) ? $_POST['cargo_type'] : '';
        $product->update_meta_data('cargo_type', sanitize_text_field($title));
        $product->save();
    }

    public static function get_cargo_type_by_product_id($product_id){
        $product_cargo_type = get_post_meta($product_id, 'cargo_type', true);
        return $product_cargo_type;
    }

}
