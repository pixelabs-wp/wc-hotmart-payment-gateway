<?php

/*
 * Register the custom payment gateway
 */
add_filter("woocommerce_payment_gateways", "add_hotmart_gateway");
function add_hotmart_gateway($gateways)
{
    $gateways[] = "WC_Gateway_Hotmart";
    return $gateways;
}

add_action("plugins_loaded", "init_hotmart_payment_gateway");

function init_hotmart_payment_gateway()
{
    // check woocommerce is installed and activated

    if (
        is_admin() &&
        current_user_can("activate_plugins") &&
        !is_plugin_active("woocommerce/woocommerce.php")
    ) {
        // Show dismissible error notice
        add_action("admin_notices", "hotmart_woocommerce_check_notice");

        // Deactivate this plugin
        deactivate_plugins(plugin_basename(__FILE__));
        if (isset($_GET["activate"])) {
            unset($_GET["activate"]);
        }
        return;
    }

    // Start custom plugin functionality to process

    class WC_Gateway_Hotmart extends WC_Payment_Gateway
    {
        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct()
        {
            $this->id = "hotmart"; // payment gateway plugin ID
            $this->icon =
                "https://branditechture.agency/brand-logos/wp-content/uploads/wpdm-cache/Hotmart-900x0.png"; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = "Hotmart Payment Gateway";
            $this->method_description =
                "Let users pay using Hotmart, automate orders WC - Hotmart"; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = ["products"];

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            $this->title = $this->get_option("title");
            $this->description = $this->get_option("description");
            $this->enabled = $this->get_option("enabled");
            $this->testmode = "yes" === $this->get_option("testmode");
            $this->private_key = $this->testmode
                ? $this->get_option("test_private_key")
                : $this->get_option("private_key");
            $this->publishable_key = $this->testmode
                ? $this->get_option("test_publishable_key")
                : $this->get_option("publishable_key");

            // This action hook saves the settings
            add_action(
                "woocommerce_update_options_payment_gateways_" . $this->id,
                [$this, "process_admin_options"]
            );

            // We need custom JavaScript to obtain a token
            add_action("wp_enqueue_scripts", [$this, "payment_scripts"]);

            // You can also register a webhook here
            add_action("woocommerce_api_hotmart_payment_callback", [
                $this,
                "webhook",
            ]);
        }

        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields()
        {
            $d = $this->method_title;
            $this->form_fields = [
                "enabled" => [
                    "title" => "Enable/Disable",
                    "label" => "Enable " . $d,
                    "type" => "checkbox",
                    "description" => "",
                    "default" => "no",
                ],
                "title" => [
                    "title" => "Title",
                    "type" => "text",
                    "description" =>
                        "Enter the title which you want to display during checkout.",
                    "default" => $d,
                    "desc_tip" => true,
                ],
                "client_id" => [
                    "title" => "Client Id",
                    "type" => "text",
                    "description" => "Enter the Hotmart Client Id",
                    "default" => "",
                ],
                "client_secret" => [
                    "title" => "Client Secret",
                    "type" => "password",
                    "description" => "Enter the Hotmart Client Secret",
                    "default" => "",
                ],
            ];
        }

        public function payment_scripts()
        {
        }
        // /*
        //  * Fields validation, more in Step 5
        //  */
        public function validate_fields()
        {
            return true;
        }

        // /*
        //  * We're processing the payments here, everything about it is in Step 5
        //  */

    public function process_payment($order_id)
    {
    global $woocommerce;

    // Get the order
    $order = wc_get_order($order_id);

    if (!empty($order)) {
        $items = $order->get_items();
        $hotmart_urls = [];

        if (!empty($items)) {
            foreach ($items as $item) {
                $product_id = $item->get_product_id();
                $product = wc_get_product($product_id);


            if ($item['variation_id'] > 0) {
                $variation_id = $item['variation_id'];

                // Check if the variation has 'hotmart_url' meta
                if (get_post_meta($variation_id, 'hotmart_url', true)) {
                    $hotmart_urls[] = get_post_meta($variation_id, 'hotmart_url', true);
                }
            } else {
                    // Assuming 'hotmart_url' is a custom field for simple products
                    $hotmart_url = $product->get_meta("hotmart_url");

                    // Check if 'hotmart_url' is present for the current product
                    if (!empty($hotmart_url)) {
                        $hotmart_urls[] = $hotmart_url;
                    }
                }
            }

            // If 'hotmart_url' is not found in any product or found in more than one, set to empty string
            if (count($hotmart_urls) !== 1) {
                $hotmart_url = "";
                return [
                "result" => "failure",
                "message"=>"You cannot use hotmart payment gateway for products not listed on hotmart",
                "redirect" => wc_get_cart_url(),
            ];
            } else {
                // Extract the single 'hotmart_url' value
                $hotmart_url = $hotmart_urls[0];
            }

            // Set the order status to "on hold"
            $order->update_status(
                "on-hold",
                __("Awaiting payment confirmation", "your-text-domain")
            );

            // Empty cart
            $woocommerce->cart->empty_cart();

            // Redirect to the thank you page
            return [
                "result" => "success",
                "redirect" => $hotmart_url . "?src=" . $order_id,
            ];
        }
    }
    }


        // /*
        //  * In case you need a webhook, like PayPal IPN etc
        //  */
        public function webhook()
        {
            $raw_data = file_get_contents("php://input");
            $webhookData = json_decode($raw_data, true);
            // Access the transaction information
            if ($webhookData["data"]["purchase"]["transaction"]) {
                $transactionId = $webhookData["data"]["purchase"]["transaction"];
                $transactionStatus = $webhookData["data"]["purchase"]["status"];

                $saleData = fetch_sale_by_src($transactionId);
                error_log(print_r(json_encode($saleData)));
                $orderId = $saleData["items"][0]["purchase"]["tracking"][
                    "source"
                ]
                    ? $saleData["items"][0]["purchase"]["tracking"]["source"]
                    : 0;

                if ($orderId !== 0) {
                    $orderStatus = $saleData["items"][0]["purchase"]["status"]
                        ? $saleData["items"][0]["purchase"]["status"]
                        : "CANCELLED";
                    $order = wc_get_order($orderId);

                    if (
                        $orderStatus == "APPROVED" ||
                        $orderStatus == "COMPLETE"
                    ) {
                        // Update the order status
                        $order->update_status("completed");
                        $order->add_order_note(
                            sprintf(
                                "Order status updated to %s Hotmart Webhook.",
                                "completed"
                            )
                        );
                    } else {
                        $order->update_status("cancelled");
                        $order->add_order_note(
                            sprintf(
                                "Order status updated to %s Hotmart Webhook.",
                                "cancelled"
                            )
                        );
                    }
                }
            }
        }
    }
}

function get_variation_data_from_variation_id( $item_id ) {
    $_product = new WC_Product_Variation( $item_id );
    $variation_data = $_product->get_variation_attributes();
    $variation_detail = woocommerce_get_formatted_variation( $variation_data, true );  // this will give all variation detail in one line
    // $variation_detail = woocommerce_get_formatted_variation( $variation_data, false);  // this will give all variation detail one by one
    return $variation_detail; // $variation_detail will return string containing variation detail which can be used to print on website
    // return $variation_data; // $variation_data will return only the data which can be used to store variation data
}
