(function($) {
    'use strict';

    window.dataLayer = window.dataLayer || [];

    // ================================================================
    // ADD TO CART (GA4: add_to_cart)
    // ================================================================
    if (jdlWoo.add_to_cart) {

        // Single product page - button click
        $(document).on('click', '.single_add_to_cart_button', function() {
            var $form = $(this).closest('form.cart');
            var productId = $form.find('input[name="product_id"]').val() ||
                           $form.find('button[name="add-to-cart"]').val() ||
                           $(this).val();
            var quantity = parseInt($form.find('input[name="quantity"]').val()) || 1;
            var variationId = parseInt($form.find('input[name="variation_id"]').val()) || 0;

            if (productId) {
                fetchItemAndPush('add_to_cart', productId, quantity, variationId);
            }
        });

        // Shop/archive page - AJAX add to cart
        $(document.body).on('added_to_cart', function(e, fragments, hash, $button) {
            var productId = $button.data('product_id');
            var quantity = parseInt($button.data('quantity')) || 1;

            if (productId) {
                fetchItemAndPush('add_to_cart', productId, quantity, 0);
            }
        });
    }

    // ================================================================
    // REMOVE FROM CART (GA4: remove_from_cart)
    // ================================================================
    if (jdlWoo.remove_from_cart) {

        $(document).on('click', '.woocommerce-cart-form .product-remove a, .cart_item .remove, .mini_cart_item .remove', function() {
            var $row = $(this).closest('tr, .cart_item, .mini_cart_item');
            var productName = $row.find('.product-name a, .product-name').first().text().trim();
            var priceText = $row.find('.product-price .amount, .quantity .amount').first().text();
            var price = parseFloat(priceText.replace(/[^0-9.]/g, '')) || 0;

            window.dataLayer.push({ecommerce: null});
            window.dataLayer.push({
                'event': 'remove_from_cart',
                'ecommerce': {
                    'currency': jdlWoo.currency,
                    'value': price,
                    'items': [{
                        'item_name': productName,
                        'price': price,
                        'quantity': 1,
                        'content_type': 'product'
                    }]
                }
            });
        });
    }

    // ================================================================
    // SELECT ITEM (GA4: select_item)
    // ================================================================
    if (jdlWoo.select_item) {

        $(document).on('click', '.products .product a:not(.add_to_cart_button):not(.added_to_cart)', function(e) {
            var $product = $(this).closest('.product');
            if (!$product.length) return;

            // Don't fire on add-to-cart buttons
            if ($(this).hasClass('add_to_cart_button')) return;

            var productName = $product.find('.woocommerce-loop-product__title, h2, h3').first().text().trim();
            var priceText = $product.find('.price .amount:last, .price ins .amount').first().text();
            var price = parseFloat(priceText.replace(/[^0-9.]/g, '')) || 0;
            var productLink = $(this).attr('href') || '';
            var productId = $product.find('.add_to_cart_button').data('product_id') || '';
            var listName = getListName();
            var listId = getListId();
            var index = $product.index();

            window.dataLayer.push({ecommerce: null});
            window.dataLayer.push({
                'event': 'select_item',
                'ecommerce': {
                    'currency': jdlWoo.currency,
                    'item_list_name': listName,
                    'item_list_id': listId,
                    'items': [{
                        'item_id': String(productId),
                        'item_name': productName,
                        'price': price,
                        'index': index,
                        'item_list_name': listName,
                        'item_list_id': listId,
                        'item_url': productLink,
                        'content_id': String(productId),
                        'content_type': 'product',
                        'content_name': productName,
                        'quantity': 1
                    }]
                }
            });
        });
    }

    // ================================================================
    // ADD TO WISHLIST (GA4: add_to_wishlist)
    // ================================================================
    $(document).on('click', '.add_to_wishlist, .yith-wcwl-add-to-wishlist a, .tinvwl_add_to_wishlist_button', function() {
        var $product = $(this).closest('.product, .summary, .single-product');
        var productName = $product.find('.product_title, .woocommerce-loop-product__title, h1, h2').first().text().trim();
        var priceText = $product.find('.price .amount:last, .price ins .amount').first().text();
        var price = parseFloat(priceText.replace(/[^0-9.]/g, '')) || 0;
        var productId = $(this).data('product-id') || $(this).data('product_id') || '';

        window.dataLayer.push({ecommerce: null});
        window.dataLayer.push({
            'event': 'add_to_wishlist',
            'ecommerce': {
                'currency': jdlWoo.currency,
                'value': price,
                'items': [{
                    'item_id': String(productId),
                    'item_name': productName,
                    'price': price,
                    'quantity': 1,
                    'content_id': String(productId),
                    'content_type': 'product',
                    'content_name': productName
                }]
            }
        });
    });

    // ================================================================
    // VIEW ITEM on variation change (GA4: view_item - dynamic)
    // ================================================================
    $(document).on('found_variation', '.variations_form', function(e, variation) {
        var productTitle = $('h1.product_title, .product_title').first().text().trim();
        var variantText = [];

        // Collect selected variation attributes
        $(this).find('.variations select').each(function() {
            var val = $(this).find('option:selected').text().trim();
            if (val && val !== '') variantText.push(val);
        });

        window.dataLayer.push({ecommerce: null});
        window.dataLayer.push({
            'event': 'view_item',
            'ecommerce': {
                'currency': jdlWoo.currency,
                'value': parseFloat(variation.display_price) || 0,
                'items': [{
                    'item_id': String(variation.variation_id),
                    'item_name': productTitle,
                    'item_variant': variantText.join(' / '),
                    'price': parseFloat(variation.display_price) || 0,
                    'item_regular_price': parseFloat(variation.display_regular_price) || 0,
                    'item_sale_price': variation.display_price < variation.display_regular_price ? parseFloat(variation.display_price) : 0,
                    'discount': Math.max(0, parseFloat(variation.display_regular_price) - parseFloat(variation.display_price)),
                    'item_availability': variation.is_in_stock ? 'in_stock' : 'out_of_stock',
                    'item_sku': variation.sku || '',
                    'item_image_url': variation.image && variation.image.full_src ? variation.image.full_src : '',
                    'item_stock_status': variation.is_in_stock ? 'instock' : 'outofstock',
                    'quantity': 1,
                    'content_id': String(variation.variation_id),
                    'content_type': 'product',
                    'content_name': productTitle
                }]
            }
        });
    });

    // ================================================================
    // COUPON APPLIED (custom event)
    // ================================================================
    $(document.body).on('applied_coupon', function(e, couponCode) {
        window.dataLayer.push({
            'event': 'coupon_applied',
            'coupon': couponCode || '',
            'page_location': window.location.href
        });
    });

    // Also detect via AJAX success on cart/checkout
    $(document).on('click', '.woocommerce-cart-form .coupon button, .checkout_coupon button', function() {
        var coupon = $(this).closest('form, .coupon').find('input[name="coupon_code"]').val();
        if (coupon) {
            setTimeout(function() {
                window.dataLayer.push({
                    'event': 'coupon_applied',
                    'coupon': coupon,
                    'page_location': window.location.href
                });
            }, 1500);
        }
    });

    // ================================================================
    // QUANTITY CHANGE (for analytics)
    // ================================================================
    $(document).on('change', '.woocommerce-cart-form .qty, form.cart .qty', function() {
        var qty = parseInt($(this).val()) || 1;
        var $row = $(this).closest('tr, .cart_item');
        var productName = $row.find('.product-name a').text().trim() ||
                         $('h1.product_title').text().trim();

        window.dataLayer.push({
            'event': 'set_quantity',
            'item_name': productName,
            'quantity': qty,
            'page_location': window.location.href
        });
    });

    // ================================================================
    // HELPER: Fetch full item data from server and push event
    // ================================================================
    function fetchItemAndPush(eventName, productId, quantity, variationId) {
        $.ajax({
            url: jdlWoo.ajax_url,
            method: 'POST',
            data: {
                action: 'jdl_get_cart_item',
                nonce: jdlWoo.nonce,
                product_id: productId,
                quantity: quantity,
                variation_id: variationId
            },
            success: function(response) {
                if (response.success && response.data) {
                    window.dataLayer.push({ecommerce: null});
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

    // ================================================================
    // HELPER: Determine list name/id from current page context
    // ================================================================
    function getListName() {
        var breadcrumb = $('.woocommerce-breadcrumb').text().trim();
        if ($('body').hasClass('post-type-archive-product') && !$('body').hasClass('tax-product_cat')) {
            return 'Shop';
        }
        if ($('body').hasClass('tax-product_cat')) {
            var cat = $('.woocommerce-products-header__title, .page-title').first().text().trim();
            return 'Category: ' + cat;
        }
        if ($('body').hasClass('tax-product_tag')) {
            var tag = $('.woocommerce-products-header__title, .page-title').first().text().trim();
            return 'Tag: ' + tag;
        }
        if ($('body').hasClass('search-results')) {
            return 'Search Results';
        }
        return 'Product List';
    }

    function getListId() {
        if ($('body').hasClass('post-type-archive-product') && !$('body').hasClass('tax-product_cat')) {
            return 'shop';
        }
        if ($('body').hasClass('tax-product_cat')) {
            var classes = $('body').attr('class').match(/term-(\S+)/);
            return classes ? 'category_' + classes[1] : 'category';
        }
        if ($('body').hasClass('tax-product_tag')) {
            return 'tag';
        }
        if ($('body').hasClass('search-results')) {
            return 'search_results';
        }
        return 'product_list';
    }

})(jQuery);
