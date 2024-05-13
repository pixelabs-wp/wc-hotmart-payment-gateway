<?php
/*
Plugin Name: Hotmart WooCommerce Addon
Description: Let users pay using Hotmart, automate orders WC - Hotmart
Version: 1.0
Author: Pixelabs
*/

define("hotmart_addon_base", __DIR__);
include_once ABSPATH . "wp-admin/includes/plugin.php";
require_once hotmart_addon_base . "/hotmart-wc-gateway.php";

// Add Hotmart URL field to simple product general settings
function hotmart_add_simple_product_field()
{
    woocommerce_wp_text_input([
        "id" => "hotmart_url",
        "label" => __("Hotmart URL", "hotmart-addon"),
        "description" => __(
            "Enter the Hotmart URL for this product.",
            "hotmart-addon"
        ),
        "desc_tip" => "true",
    ]);
}

add_action(
    "woocommerce_product_options_general_product_data",
    "hotmart_add_simple_product_field"
);

// Save Hotmart URL for simple products
function hotmart_save_simple_product_field($post_id)
{
    $hotmart_url = sanitize_text_field($_POST["hotmart_url"]);
    update_post_meta($post_id, "hotmart_url", $hotmart_url);
}

add_action(
    "woocommerce_process_product_meta",
    "hotmart_save_simple_product_field"
);

// Add Hotmart URL field to variable product and variations
function hotmart_add_variable_product_field($loop, $variation_data, $variation)
{
    woocommerce_wp_text_input([
        "id" => "hotmart_url",
        "label" => __("Hotmart URL", "hotmart-addon"),
        "description" => __(
            "Enter the Hotmart URL for this variation.",
            "hotmart-addon"
        ),
        "desc_tip" => "true",
        "value" => get_post_meta($variation->ID, "hotmart_url", true),
    ]);
}

add_action(
    "woocommerce_variation_options_pricing",
    "hotmart_add_variable_product_field",
    10,
    3
);



// Save Hotmart URL for variable products and variations
function hotmart_save_variable_product_field($variation_id, $loop)
{
    $hotmart_url = sanitize_text_field($_POST["hotmart_url"]);
    update_post_meta($variation_id, "hotmart_url", $hotmart_url);
}

add_action(
    "woocommerce_save_product_variation",
    "hotmart_save_variable_product_field",
    10,
    2
);

function get_hotmart_access_token()
{
    global $woocommerce;

    $payment_gateways = $woocommerce->payment_gateways->get_available_payment_gateways();

    // Find the Hotmart gateway
    $hotmart_gateway = $payment_gateways["hotmart"];

    // Retrieve the "client_id" option
    $client_id = $hotmart_gateway->get_option("client_id");
    $client_secret = $hotmart_gateway->get_option("client_secret");

    $url = "https://api-sec-vlc.hotmart.com/security/oauth/token?grant_type=client_credentials&client_id=".$client_id."&client_secret=".$client_secret;

    $headers = [
        "Content-Type" => "application/json",
        "Authorization" =>
            "Basic " . base64_encode($client_id . ":" . $client_secret),
    ];

    $body = [
        "grant_type" => "client_credentials",
    ];

    $response = wp_remote_post($url, [
        "headers" => $headers,
        "body" => json_encode($body),
    ]);

    if (is_wp_error($response)) {
        // Handle error
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    // Access Token is in $data['access_token']
    return $data["access_token"];
}

function fetch_sale_by_src($transactionId)
{
    $access_token = get_hotmart_access_token();

    $api_url =
        "https://developers.hotmart.com/payments/api/v1/sales/history?transaction=" .
        $transactionId;
    $headers = [
        "Content-Type" => "application/json",
        "Authorization" => "Bearer " . $access_token,
    ];

    $response = wp_remote_get($api_url, ["headers" => $headers]);

    if (is_wp_error($response)) {
        // Handle error
        echo "Error: " . $response->get_error_message();
    } else {
        // Process response
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data;
    }
}

function hotmart_woocommerce_check_notice()
{
    ?>
    <div class="alert alert-danger notice is-dismissible">
        <p>Sorry, WooCommerce plugin sholud be installed and activated.
        </p>
    </div>
    <?php
}

function hotmart_woocommerce_auth_notice()
{
    ?>
    <div class="alert alert-danger notice is-dismissible">
        <p>Your Hotmart Client Id & Secret is not correct
        </p>
    </div>
    <?php
}
function hide_hotmart_payment_gateway() {
    // Check if we are on the checkout page
    if (is_checkout()) {
        $hotmart_products_count = 0;

        // Loop through cart items to count variations with 'hotmart_url' meta
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product_id = $cart_item['product_id'];

            
            // Check if the product has variations
            if ($cart_item['variation_id'] > 0) {
                $variation_id = $cart_item['variation_id'];

                // Check if the variation has 'hotmart_url' meta
                if (get_post_meta($variation_id, 'hotmart_url', true)) {
                    $hotmart_products_count++;
                }
            } else {
            if (get_post_meta($product_id, 'hotmart_url', true)) {
                $hotmart_products_count++;
            }
            }
        }

        // Disable the Hotmart payment gateway if more than one 'hotmart_url' variation is found in the cart
        if ($hotmart_products_count > 1) {
            
            add_action('wp_footer', 'disable_hotmart_gateway_script');
            add_filter('woocommerce_available_payment_gateways', 'disable_hotmart_gateway');
            add_action('woocommerce_review_order_after_payment', 'add_hotmart_disabled_notice');
        }
    }
}

function disable_hotmart_gateway_script(){

    ?>
    <script>
         
       document.addEventListener('DOMContentLoaded', function() {
    // Function to disable the Hotmart gateway
    function disableHotmartGateway() {
        var hotmartElement = document.getElementById('payment_method_hotmart');
        
        if (hotmartElement !== null) {
            hotmartElement.disabled = true;
            console.log('Hotmart element found and disabled');
        } else {
            console.log('Hotmart element not found');
        }
    }

    // Initial check and disable
    disableHotmartGateway();

    // Set up a MutationObserver to check for changes in the DOM
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            // Check if the 'payment_method_hotmart' element has been added to the DOM
            if (mutation.addedNodes && mutation.addedNodes.length > 0) {
                disableHotmartGateway();
            }
        });
    });

    // Configure and start the observer
    var observerConfig = { childList: true, subtree: true };
    observer.observe(document.body, observerConfig);
});

    </script>
    <?php

}

function add_hotmart_disabled_notice() {
    echo '<p>You have more than 1 Hotmart products in cart, only one product can be checked out with hotmart</p>';
}



function disable_hotmart_gateway($available_gateways) {
    // Replace 'hotmart' with the actual ID of your Hotmart payment gateway
    $gateway_id = 'hotmart';

    // Remove the Hotmart payment gateway from the available gateways
    if (isset($available_gateways[$gateway_id])) {
        unset($available_gateways[$gateway_id]);
    }

    return $available_gateways;
}

// Hook to run the function on the checkout page
add_action('wp', 'hide_hotmart_payment_gateway');

