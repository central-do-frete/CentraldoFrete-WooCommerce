<?php

/**
 * Woo_CentralDoFrete_Helper class.
 */
class Woo_CentralDoFrete_Helper
{
    const API_CARGO = "v1/cargo-type";
    const API_QUOTE = "v1/quotation";

    /**
     * @param $pluginId string
     * @param $writeIt string
     */
    public function write_log($pluginId, $writeIt)
    {
        $this->log->add($pluginId, $writeIt);
    }

    public function __construct()
    {
        // Active logs.
        if (class_exists('WC_Logger')) {
            $this->log = new WC_Logger();
        } else {
            $this->log = $this->woocommerce_method()->logger();
        }
    }

    /**
     * Retrieve Central do Frete instance ID
     * @return int
     */
    public function get_instance_id()
    {
        global $wpdb;
        $instance_id = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT instance_id
                      FROM {$wpdb->prefix}woocommerce_shipping_zone_methods
                      WHERE is_enabled = %d AND method_id = %s ", 1, 'centraldofrete'
            )
        );
        $instance_id = $instance_id[0]->instance_id;
        return $instance_id;
    }

    /**
     * Retrieve shipping options
     * @return array
     */
    public function get_options()
    {
        $instance_id = $this->get_instance_id();
        $options = get_option('woocommerce_centraldofrete_' . $instance_id . '_settings');
        return $options;
    }

    /**
     * Retrieve API base URL
     * @return string
     */
    public function get_base_url()
    {
        $options = $this->get_options();
        if ($options['sandbox'] == 'no') {
            return 'https://api.centraldofrete.com/';
        } else {
            return 'https://sandbox.centraldofrete.com/';
        }
    }

    /**
     * Retrieve Central do Frete API Quotes Endpoint
     * @param null|string $code
     * @return string
     */
    public function get_quotes_endpoint_url($code = null)
    {
        $base_url = $this->get_base_url();
        if ($code == null) {
            return $base_url . self::API_QUOTE;
        } else {
            return $base_url . self::API_QUOTE . '/' . $code;
        }
    }

    /**
     * Retrieve Central do Frete API Cargo Types Endpoint
     * @return string
     */
    public function get_cargo_types_endpoint_url()
    {
        $base_url = $this->get_base_url();
        return $base_url . self::API_CARGO;
    }


    /**
     * Retrieve Central do Frete API Request Headers
     * @return array
     */
    public function get_request_headers()
    {
        $options = $this->get_options();
        $headers = [
            'Authorization' => $options['token'],
            'Content-Type' => 'application/json'
        ];
        return $headers;
    }

    /**
     * Retrieve default cargo type
     * @return string
     */
    public function get_default_cargo_type()
    {
        $options = $this->get_options();
        return $options['default_cargo_type'];
    }


    /**
     * @param $package array
     * @return bool
     */
    public function checkDestinationInfo(array $package, $centraldofrete)
    {
        $destination_postcode = $package['destination']['postcode'];
        $destination_country = $package['destination']['country'];
        if (!is_array($package)) {
            $this->write_log($this->get_instance_id(), "ERRO: Metodo não selecionado");
            return false;
        }
        // Checks if destination country exists and is BR.
        if (empty($destination_postcode) && $destination_country == 'BR') {
            $this->write_log($this->get_instance_id(), "ERRO: CEP destino não informado");
            return false;
        }
        // Checks if zipcode is empty.
        if (empty($centraldofrete->zip_origin)) {
            $this->write_log($this->get_instance_id(), "ERRO: CEP origem não configurado");
            return false;
        }
        return true;
    }

}