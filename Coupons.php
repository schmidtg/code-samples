<?php
/**
 * Coupons
 *
 * @author      Graham Schmidt
 * @created     @2012-05-10
 */

class Coupons {

    /**
     * Get the discount type for the coupon
     *
     * @author      Graham Schmidt
     * @param       coupon_code - string
     * @return      mixed (string/boolean) - type of discount (or boolean false)
     */
    public static function getDiscountDetails($coupon_code = '')
    {
        global $finder_db;

        if ($coupon_code == '') {
            return false;
        }

        $sql =
           "SELECT
                        coupon_id
                        , coupon_code
                        , description
                        , value
                        , type
                        , req_skus
                        , can_recur
                        , count
                        , expiration
                        , active
                        , is_giftcard
                        , coupon_source
                        , order_type
                        , crm_class
            FROM        coupons
            WHERE       coupon_code = '$coupon_code'
            ";
        try {
            $query = $finder_db->query($sql);
            $query->tossIfNoRows();
            return $query->fetchRow();
        } catch (fNoRowsException $e) {
            return false;
        }
    }

    /**
     * Get the discount type
     */
    public static function getDiscountType($coupon_code = '')
    {
        $details = self::getDiscountDetails($coupon_code);
        return $details['type'];
    }

    /**
     * Get the discount description
     */
    public static function getDiscountDescription($coupon_code = '', $custom_message = '')
    {
        if ($coupon_code == '') {
            return false;
        }

        $details = self::getDiscountDetails($coupon_code);
        if ($details['type'] == 'Percent Discount') {
            $percent_val = $details['value'] * 100;
            if ($details['req_skus'] != '') {
                $coupon_response = $details['description'];
            } else {
                $coupon_response = "Save $percent_val% with your promo code: ".$coupon_code;
            }
        }
        if ($details['type'] == 'Free Shipping') {
            $coupon_response = "You'll receive free shipping with your promo code: ".$coupon_code;
        }
        if ($details['type'] == 'Shipping Discount') {
            $discount = number_format($details['value'], 2);
            $coupon_response = "Save $$discount off shipping with your promo code: ".$coupon_code;
        }
        if ($details['type'] == 'Buy 1 Get 1 Free') {
            $coupon_response = $details['description'];
        }
        if ($details['type'] == 'Flat Discount') {
            $discount = number_format($details['value'], 2);
            if ($details['req_skus'] != '') {
                $coupon_response = $details['description'];
            } else {
                $coupon_response = "Save $$discount off the total order with your promo code: ".$coupon_code;
            }
        }

        if ($custom_message != '') {
            //replace all variables (coupon_code, discount, percent_val)
            (isset($coupon_code) ? $coupon_response = str_replace('{coupon_code}', $coupon_code, $custom_message) : '');
            (isset($discount) ? $coupon_response = str_replace('{discount}', $discount, $custom_message) : '');
            (isset($percent_val) ? $coupon_response = str_replace('{percent_val}', $percent_val, $custom_message) : '');
        }
        return $coupon_response;
    }

    /**
     * Apply the discount to a price
     *
     * @author      Graham Schmidt
     * @param       coupon_code - string
     * @return      mixed (string/boolean) - type of discount (or boolean false)
     */
    public static function getDiscountOnPrice($coupon_code = '', $price_in_dollars = 0, $custom_message = '')
    {
        if ($coupon_code == '' || $price_in_dollars == 0) {
            return false;
        }

        $details = self::getDiscountDetails($coupon_code);

        $free_shipping = false;
        if ($details['type'] == 'Percent Discount') {
            $discount        = round($price_in_dollars * $details['value'], 2);
            $percent_val     = $details['value'] * 100;
            $discount        = number_format($discount, 2);
            $coupon_response = "Save $percent_val% with your promo code: ".$coupon_code;
        }
        if ($details['type'] == 'Free Shipping') {
            $free_shipping   = true;
            $discount        = 4.99; // default shipping
            $coupon_response = "You'll receive free shipping with your promo code: ".$coupon_code;
        }
        if ($details['type'] == 'Shipping Discount') {
            $discount        = number_format($details['value'], 2);
            $coupon_response = "Save $$discount off shipping with your promo code: ".$coupon_code;
        }
        if ($details['type'] == 'Buy 1 Get 1 Free') {
            // @todo calculate price based on basket
            $discount        = 0;
            $coupon_response = $details['description'];
        }
        if ($details['type'] == 'Flat Discount') {
            $discount        = number_format($details['value'], 2);
            $coupon_response = "Save $$discount off the total order with your promo code: ".$coupon_code;
        }

        if ($custom_message != '') {
            //replace all variables (coupon_code, discount, percent_val)
            $coupon_response = (isset($coupon_code) ? str_replace('{coupon_code}', $coupon_code, $custom_message) : $coupon_response);
            $coupon_response = (isset($discount)    ? str_replace('{discount}', $discount, $coupon_response) : $coupon_response);
            $coupon_response = (isset($percent_val) ? str_replace('{percent_val}', $percent_val, $coupon_response) : $coupon_response);
        }

        return array(
            'discount'      => $discount,
            'type'          => $details['type'],
            'free_shipping' => $free_shipping,
            'value'         => $details['value'],
            'description'   => $details['description'],
            'response'      => $coupon_response,
        );
    }

    /**
     * Determines if coupon is valid or not
     *
     * @author      Graham Schmidt
     * @param       coupon_code - string
     * @return      boolean
     */
    public static function isValidCoupon($coupon_code = '')
    {
        $details = self::getDiscountDetails($coupon_code);
        return $details['coupon_code'] == $coupon_code;
    }

    /**
     * Check if the coupon code is valid to use by a type
     *
     * @author      Graham Schmidt
     * @param       coupon_code - string
     * @return      boolean
     */
    public static function isType($coupon_code = '', $type = '')
    {
        $details = self::getDiscountDetails($coupon_code);
        $types = explode("|", $details['coupon_source']);
        foreach ($types as $coupon_type) {
            if ($type == $coupon_type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the coupon code applies to a particular SKU based on req_skus
     * on the coupon_code
     *
     * @author      Graham Schmidt
     * @param       coupon_code - string
     * @param       sku - string
     * @return      boolean
     */
    public static function appliesToSku($coupon_code = '', $sku = '')
    {
        $details = self::getDiscountDetails($coupon_code);
        $types = explode("|", $details['coupon_source']);

        $requirment_met = false;
        if ($details['req_skus'] != '') {
            $required_skus = explode('|', $details['req_skus']);
            foreach ($required_skus as $req_sku) {
                if ($details['type'] == 'Buy 1 Get 1 Free'
                    && $req_sku != ''
                    && preg_match($req_sku, $sku)
                ) {
                    $requirment_met = true;
                } else if ($req_sku === $sku) {
                    $requirment_met = true;
                }
            }
        } else {
            $requirment_met = true;
        }

        return $requirment_met;
    }

    /**
     * Check if the requirements are met for a coupon
     *
     * @author      Graham Schmidt
     * @param
     * @return      booelan
     */
    public static function checkRequirementsMet($shopping_cart = array(), $req_skus_string = '', $coupon_type = '')
    {
        if ($req_skus_string != '') {
            $required_skus = explode('|', $req_skus_string);
            foreach ($required_skus as $req_sku) {
                // Skip blanks
                if ($req_sku != '') {

                    // Special Type
                    if ($coupon_type == 'Buy 1 Get 1 Free') {
                        // go through each item in cart...check if SKU matches
                        // RegEx and there are an even number of items in cart
                        if (self::checkItemsMatchInCart($shopping_cart, $req_sku) > 1) {
                            foreach ($shopping_cart as $sku => $quantity) {
                                if (preg_match($req_sku, $sku)) {
                                    return true;
                                }
                            }
                        }

                    } else {
                        // Req_sku must be in basket
                        if (   is_array($shopping_cart)
                            && array_key_exists($req_sku, $shopping_cart)
                        ) {
                            return true;
                        }
                    }
                }
            }
        } else {
            return true;
        }

        // Not met
        return false;
    }

    /**
     * Check how many items in cart match a RegEx pattern
     *
     * @author      Graham Schmidt
     * @created     @2012-06-22
     */
    public static function checkItemsMatchInCart($shopping_cart, $regex)
    {
        unset($items_matched);
        $items_matched = 0;
        foreach ($shopping_cart as $sku => $quantity) {
            if (preg_match($regex, $sku)) {
                $items_matched += $quantity;
            }
        }

        return $items_matched;
    }

}
