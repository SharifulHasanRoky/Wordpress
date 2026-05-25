(function($) {
    'use strict';

    window.dataLayer = window.dataLayer || [];

    // ============ Add to Cart Tracking ============
    if (jdlWooConfig.track_add_to_cart) {

        // Single Product Page - Add to Cart
        $(document).on('click', '.single_add_to_cart_button', function() {
            var $form = $(this).closest('form.cart');
            var productId = $form.find('input[name="product_id"]').val() || 
                           $form.find('button[name="add-to-cart"]').val() ||
                           $(this).val();
            var quantity = $form.find('input[name="quantity"]').val() || 1;
            var variationId = $form.find('input[name="variation_id"]').val() || 0;

            fetchAndPushCartEvent('add_to_cart', productId, quantity, variationId);
        });

        // Archive/Shop Page - Add to Cart (AJAX)
        $(document.body).on('added_to_cart', function(e, fragments, hash, $button) {
            var productId = $button.data('product_id');
            var quantity = $button.data('quantity') || 1;

            fetchAndPushCartEvent('add_to_cart', productId, quantity, 0);
        });
    }

    // ============ Remove from Cart Tracking ============
    if (jdlWooConfig.track_remove_from_cart) {
        $(document).on('click', '.cart_item .remove, .woocommerce-cart-form .product-remove a', function() {
            var $row = $(this).closest('tr, .cart_item');
            var productName = $row.find('.product-name a').text().trim();
            var productPrice = $row.find('.product-price .amount').text().trim();

            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                'event': 'remove_from_cart',
                'ecommerce': {
                    'currency': jdlWooConfig.currency,
                    'items': [{
                        'item_name': productName,
                        'price': parseFloat(productPrice.replace(/[^0-9.]/g, '')) || 0,
                        'quantity': 1
                    }]
                }
            });
        });
    }

    // ============ Select Item Tracking ============
    if (jdlWooConfig.track_select_item) {
        $(document).on('click', '.products .product a:not(.add_to_cart_button)', function() {
            var $product = $(this).closest('.product');
            var productName = $product.find('.woocommerce-loop-product__title, h2').text().trim();
            var productPrice = $product.find('.price .amount:last').text().trim();
            var productLink = $(this).attr('href');

            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                'event': 'select_item',
                'ecommerce': {
                    'currency': jdlWooConfig.currency,
                    'items': [{
                        'item_name': productName,
                        'price': parseFloat(productPrice.replace(/[^0-9.]/g, '')) || 0,
                        'item_url': productLink
                    }]
                }
            });
        });
    }

    // ============ Wishlist Tracking ============
    $(document).on('click', '.add_to_wishlist, .yith-wcwl-add-to-wishlist a', function() {
        var $product = $(this).closest('.product, .summary');
        var productName = $product.find('.product_title, .woocommerce-loop-product__title, h2').first().text().trim();

        window.dataLayer.push({
            'event': 'add_to_wishlist',
            'ecommerce': {
                'currency': jdlWooConfig.currency,
                'items': [{
                    'item_name': productName
                }]
            }
        });
    });

    // ============ Coupon Applied ============
    $(document.body).on('applied_coupon', function(e, couponCode) {
        window.dataLayer.push({
            'event': 'coupon_applied',
            'coupon_code': couponCode || ''
        });
    });

    // ============ Variation Change ============
    $(document).on('found_variation', '.variations_form', function(e, variation) {
        window.dataLayer.push({ ecommerce: null });
        window.dataLayer.push({
            'event': 'view_item',
            'ecommerce': {
                'currency': jdlWooConfig.currency,
                'value': parseFloat(variation.display_price) || 0,
                'items': [{
                    'item_id': String(variation.variation_id),
                    'item_name': $('h1.product_title').text().trim(),
                    'item_variant': Object.values(variation.attributes).join(' / '),
                    'price': parseFloat(variation.display_price) || 0,
                    'item_stock_status': variation.is_in_stock ? 'instock' : 'outofstock'
                }]
            }
        });
    });

    // ============ Helper Function ============
    function fetchAndPushCartEvent(eventName, productId, quantity, variationId) {
        $.ajax({
            url: jdlWooConfig.ajax_url,
            method: 'POST',
            data: {
                action: 'jdl_get_cart_item',
                nonce: jdlWooConfig.nonce,
                product_id: productId,
                quantity: quantity,
                variation_id: variationId
            },
            success: function(response) {
                if (response.success) {
                    window.dataLayer.push({ ecommerce: null });
                    window.dataLayer.push({
                        'event': eventName,
                        'ecommerce': {
                            'currency': response.data.currency,
                            'value': response.data.value,
                            'items': [response.data.item]
                        }
                    });
                }
            }
        });
    }

})(jQuery);
