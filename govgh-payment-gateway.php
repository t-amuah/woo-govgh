<?php
/**
 * Plugin Name: GovGH Payment Gateway
 * Plugin URI: https://centrifuj.com
 * Description: A WooCommerce payment gateway for GovGH.
 * Version: 1.0.0
 * Author: Theophilus Amuah
 * Author URI: https://centrifuj.com
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    add_action('plugins_loaded', 'govgh_payment_gateway_init', 0);

    function govgh_payment_gateway_init()
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return; // If WooCommerce is not installed, exit
        }

        class WC_GovGH_Payment_Gateway extends WC_Payment_Gateway
        {
            public function __construct()
            {
                $this->id = 'govgh';
                $this->icon = '';
                $this->has_fields = false;
                $this->method_title = 'GovGH';
                $this->method_description = 'A WooCommerce payment gateway for GovGH.';

                $this->init_form_fields();
                $this->init_settings();

                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->api_key = $this->get_option('api_key');

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            }

            public function init_form_fields()
            {
                $webhook_url = get_site_url(null, '/wc-api/govgh_payment_webhook', 'https');

                $this->form_fields = array(
                    'enabled' => array(
                        'title' => 'Enable/Disable',
                        'type' => 'checkbox',
                        'label' => 'Enable GovGH Payment Gateway',
                        'default' => 'yes',
                    ),
                    'title' => array(
                        'title' => 'Title',
                        'type' => 'text',
                        'description' => 'The title displayed to users during checkout.',
                        'default' => 'GovGH',
                    ),
                    'description' => array(
                        'title' => 'Description',
                        'type' => 'textarea',
                        'description' => 'The description displayed to users during checkout.',
                        'default' => 'Pay securely using GovGH.',
                    ),
                    'api_key' => array(
                        'title' => 'API Key',
                        'type' => 'text',
                        'description' => 'The API key provided by GovGH.',
                    ),
                    'webhook_url' => array(
                        'title' => 'Webhook URL',
                        'type' => 'text',
                        'description' => 'The URL GovGH should send webhook events to.',
                        'default' => $webhook_url,
                        'custom_attributes' => array('readonly' => 'readonly'),
                    ),
                );
            }


            public function process_payment($order_id)
            {
                global $woocommerce;
                $order = new WC_Order($order_id);

                // Prepare the data for the API request
                $data = array(
                    'request' => 'create',
                    'api_key' => $this->api_key,
                    'mda_branch_code' => 'PMMC_HQ',
                    'firstname' => $order->get_billing_first_name(),
                    'lastname' => $order->get_billing_last_name(),
                    'phonenumber' => $order->get_billing_phone(),
                    'email' => $order->get_billing_email(),
                    'application_id' => $order_id,
                    'invoice_items' => array(
                        array(
                            'service_code' => 'PPMC02',
                            'amount' => $order->get_total(),
                            'currency' => 'GHS',
                            'memo' => 'Gold Jewellery',
                            'account_number' => 'pmmc00022',
                        ),
                    ),
                    'redirect_url' => $this->get_return_url($order),
                    'post_url' => get_site_url(null, '/wc-api/govgh_payment_webhook', 'https'),
                );

                // Send the API request
                $response = wp_remote_post('https://www.govgh.org/api/v1.1/checkout/service.php', array(
                    'method' => 'POST',
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => json_encode($data),
                    'timeout' => 45,
                ));

                if (!is_wp_error($response)) {
                    $response_body = json_decode(wp_remote_retrieve_body($response), true);
                    if ($response_body['status'] == 0) {
                        // Successful response
                        $checkout_url = $response_body['checkout_url'];
                        $order->update_status('pending', 'Awaiting GovGH payment.');

                        // Reduce stock levels
                        $order->reduce_order_stock();

                        // Remove cart
                        $woocommerce->cart->empty_cart();

                        // Redirect to the checkout URL
                        return array(
                            'result' => 'success',
                            'redirect' => $checkout_url,
                        );
                    } else {
                        // Handle the error in the response
                        wc_add_notice('Error: ' . $response_body['message'], 'error');
                        return;
                    }
                } else {
                    // Handle the error in the request
                    wc_add_notice('Error: ' . $response->get_error_message(), 'error');
                    return;
                }
            }

        }
    }

    add_filter('wc_order_statuses', 'add_govgh_order_statuses');

    function add_govgh_order_statuses($order_statuses)
    {
        $order_statuses['wc-govgh-pending'] = _x('GovGH Pending', 'Order status', 'govgh-payment-gateway');
        return $order_statuses;
    }

    add_action('init', 'register_govgh_order_status');

    function register_govgh_order_status()
    {
        register_post_status('wc-govgh-pending', array(
            'label' => 'GovGH Pending',
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            'label_count' => _n_noop('GovGH Pending <span class="count">(%s)</span>', 'GovGH Pending <span class="count">(%s)</span>'),
        ));
    }


    function add_govgh_payment_gateway($methods)
    {
        $methods[] = 'WC_GovGH_Payment_Gateway';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_govgh_payment_gateway');

    add_action('woocommerce_api_govgh_payment_webhook', 'govgh_payment_webhook_handler');

    function govgh_payment_webhook_handler()
    {
        // Retrieve the request body and parse it as JSON
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        // Validate the webhook data and update the order status
        if (isset($data['invoice_number']) && isset($data['status'])) {
            $invoice_number = $data['invoice_number'];
            $status = $data['status'];

            // Retrieve the order by the invoice number
            $order = wc_get_orders(array(
                'meta_key' => '_govgh_invoice_number',
                'meta_value' => $invoice_number,
                'limit' => 1,
            ));

            if (!empty($order)) {
                $order = $order[0];

                // Update the order status based on the payment status
                switch ($status) {
                    case 'success':
                        $order->update_status('completed', 'GovGH payment completed.');
                        break;
                    case 'failed':
                        $order->update_status('failed', 'GovGH payment failed.');
                        break;
                    case 'pending':
                        $order->update_status('govgh-pending', 'GovGH payment pending.');
                        break;
                    default:
                        // Handle other payment status cases if necessary
                        break;
                }

                // Optionally, send a response to the webhook
                header('HTTP/1.1 200 OK');
                echo json_encode(array('message' => 'Webhook processed successfully.'));
                exit;
            }
        }

        // Send an error response if the webhook data is not valid
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(array('error' => 'Invalid webhook data.'));
        exit;
    }

    function govgh_checkout_banner() {
        $banner_url = plugin_dir_url(__FILE__) . 'images/gov-logo.png'; // Replace 'images/banner.jpg' with the path to your banner image
        echo '<div class="govgh-checkout-banner" style="text-align:center; margin-bottom:20px;"><img src="' . esc_url($banner_url) . '" alt="GovGH Banner" /></div>';
    }

    add_action('woocommerce_review_order_before_submit', 'govgh_checkout_banner', 10);



}
