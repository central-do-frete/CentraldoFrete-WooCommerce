<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WooCommerce_CentralDoFrete_Helper')) :

    /**
     * WooCommerce_CentralDoFrete_Helper class.
     */
    class WooCommerce_CentralDoFrete_Helper
    {

        const API_CARGO_TYPE = "v1/cargo-type";
        const API_QUOTATIONS = "v1/quotation";

        private $log;
		/**
		 * @var boolean
		 */
        private $debug = false;

        public function __construct()
        {
            $this->log = wc_get_logger();
        }

        /**
         * @param string $type
         * @param string $message
         */
        public function write_log($type, $message)
        {
            $context = array( 'source' => 'central-do-frete' );
            if (!is_null($this->log)) {
                //if (!($type == 'debug' && !$this->debug)) {
                    $this->log->log('info', $message, $context);
                //}
            }
        }
        /**
         * @param boolean $debug
         */
        public function setDebug($debug)
        {
            $this->debug = $debug;
        }

        /**
         * Retrieve Central do Frete instance ID
         * @return int
         */
        public function getInstanceId()
        {
            global $wpdb;

            $instanceId = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT instance_id
                      FROM {$wpdb->prefix}woocommerce_shipping_zone_methods
                      WHERE is_enabled = %d AND method_id = %s ", 1, 'centraldofrete'
                )
            );
            $instanceId = $instanceId[0]->instance_id;

            return $instanceId;
        }

        /**
         * Retrieve shipping options
         * @return array
         */
        public function getCentralDoFreteOptions()
        {
            $instanceId = $this->getInstanceId();
            return get_option('woocommerce_centraldofrete_' . $instanceId . '_settings');
        }

        /**
         * Retrieve API base URL according to selected env
         * @return string
         */
        public function getBaseURL()
        {
            $options = $this->getCentralDoFreteOptions();
            if ($options['sandbox'] == 'no') {
                return 'https://api.centraldofrete.com/';
            } else {
                return 'https://sandbox.centraldofrete.com/';
            }
        }

        /**
         * Retrieve Central do Frete API Quotes Endpoint
         * If code are passed as argument, this method retrieve quotes by code URL
         * @param string $quotationCode
         * @return string
         */
        public function getQuotesURL($quotationCode = null)
        {
            $baseURL = $this->getBaseURL();
            if ($quotationCode == null) {
                return $baseURL . self::API_QUOTATIONS;
            } else {
                return $baseURL . self::API_QUOTATIONS . '/' . $quotationCode;
            }
        }

        /**
         * Retrieve Central do Frete API Cargo Types Endpoint
         * @return string
         */
        public function getCargoTypesURL()
        {
            $baseURL = $this->getBaseURL();
            return $baseURL . self::API_CARGO_TYPE;
        }


        /**
         * Retrieve Central do Frete API Request Headers
         * @return array
         */
        public function getRequestHeaders()
        {
            $options = $this->getCentralDoFreteOptions();
            return [
                'Authorization' => $options['token'],
                'Content-Type' => 'application/json'
            ];
        }

        /**
         * Retrieve default cargo type (from shipping method config)
         * @return string
         */
        public function getDefaultCargoType()
        {
            $options = $this->getCentralDoFreteOptions();
            return $options['default_cargo_type'];
        }


        /**
         * Check if destination is available on Central do Frete
         * @param array $package
         * @param string|null $from_zipcode
         * @return bool
         */
        public function isValidDestination(array $package, string $from_zipcode = null)
        {
            $destinationPostcode = $package['destination']['postcode'];
            $destinationCountry = $package['destination']['country'];
            if (!is_array($package)) {
                $this->write_log('error', "Nenhum produto selecionado");
                return false;
            }
            // Checks if destination country exists and is BR.
            if (empty($destinationPostcode) && $destinationCountry == 'BR') {
                $this->write_log('error', "CEP destino não informado");
                return false;
            }
            // Checks if zipcode is empty.
            if (is_null($from_zipcode)) {
                $this->write_log('error', "CEP origem não configurado");
                return false;
            }
            return true;
        }

    }
endif;