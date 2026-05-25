(function ($) {
    'use strict';

    if (typeof jdl === 'undefined') {
        return;
    }

    var JDLEcom = {

        init: function () {
            if (jdl.atc) this.bindAddToCart();
            if (jdl.rfc) this.bindRemoveFromCart();
            if (jdl.si) this.bindSelectItem();
            if (jdl.wl) this.bindAddToWishlist();
            this.bindVariationChange();
        },

        /**
         * Push event to dataLayer with ecommerce:null clear
         */
        push: function (eventName, ecomData) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({ ecommerce: null });
            window.dataLayer.push({
                event: eventName,
                ecommerce: ecomData
            });
        },

        /**
         * Build item object from element data attributes
         */
        buildItem: function ($el) {
            return {
                item_id: $el.data('product_id') || $el.data('id') || '',
                item_name: $el.data('name') || '',
                item_brand: $el.data('brand') || '',
                item_category: $el.data('category') || '',
                item_variant: $el.data('variant') || '',
                price: parseFloat($el.data('price')) || 0,
                quantity: parseInt($el.data('quantity')) || 1,
                currency: jdl.cur || 'USD'
            };
        },

        /**
         * Add to Cart - Single product page
         */
        bindAddToCart: function () {
            var self = this;

            // Single product page - form submission
            $('form.cart').on('submit', function () {
                var $form = $(this);
                var $btn = $form.find('[type="submit"]');
                var qty = $form.find('input[name="quantity"]').val() || 1;

                var item = {
                    item_id: $form.find('input[name="add-to-cart"]').val() || $btn.val() || '',
                    item_name: $form.closest('.product').find('.product_title').text() || '',
                    item_brand: $form.data('brand') || '',
                    item_category: $form.data('category') || '',
                    item_variant: self.getSelectedVariant($form),
                    price: self.getCurrentPrice(),
                    quantity: parseInt(qty),
                    currency: jdl.cur || 'USD'
                };

                self.push('add_to_cart', {
                    currency: jdl.cur || 'USD',
                    value: item.price * item.quantity,
                    items: [item]
                });
            });

            // Archive/loop AJAX add to cart
            $(document.body).on('added_to_cart', function (e, fragments, cart_hash, $btn) {
                if (!$btn || !$btn.length) return;

                var item = self.buildItem($btn);

                self.push('add_to_cart', {
                    currency: jdl.cur || 'USD',
                    value: item.price * item.quantity,
                    items: [item]
                });
            });
        },

        /**
         * Remove from Cart
         */
        bindRemoveFromCart: function () {
            var self = this;

            $(document.body).on('click', '.remove_from_cart_button, .cart_item .remove', function () {
                var $el = $(this);
                var item = {
                    item_id: $el.data('product_id') || '',
                    item_name: $el.data('product_name') || $el.closest('tr').find('.product-name a').text() || '',
                    item_brand: $el.data('brand') || '',
                    item_category: $el.data('category') || '',
                    price: parseFloat($el.data('price')) || 0,
                    quantity: parseInt($el.data('quantity')) || 1,
                    currency: jdl.cur || 'USD'
                };

                self.push('remove_from_cart', {
                    currency: jdl.cur || 'USD',
                    value: item.price * item.quantity,
                    items: [item]
                });
            });
        },

        /**
         * Select Item (product click in lists)
         */
        bindSelectItem: function () {
            var self = this;

            $(document).on('click', '.products .product a:not(.add_to_cart_button), .wc-block-grid__product a', function () {
                var $product = $(this).closest('.product, .wc-block-grid__product');
                var $link = $product.find('a.woocommerce-LoopProduct-link, a.wc-block-grid__product-link').first();

                var item = {
                    item_id: $product.data('product_id') || $product.find('.add_to_cart_button').data('product_id') || '',
                    item_name: $product.find('.woocommerce-loop-product__title, .wc-block-grid__product-title').text().trim() || '',
                    item_brand: $product.data('brand') || '',
                    item_category: $product.data('category') || '',
                    price: parseFloat($product.find('.price ins .amount, .price > .amount').first().text().replace(/[^0-9.]/g, '')) || 0,
                    quantity: 1,
                    currency: jdl.cur || 'USD'
                };

                self.push('select_item', {
                    item_list_name: self.getListName(),
                    items: [item]
                });
            });
        },

        /**
         * Add to Wishlist
         */
        bindAddToWishlist: function () {
            var self = this;

            $(document).on('click', '.add_to_wishlist, .yith-wcwl-add-to-wishlist a, [data-wishlist-action="add"]', function () {
                var $el = $(this);
                var $product = $el.closest('.product, .product-inner, tr');
                var productId = $el.data('product-id') || $el.data('product_id') || $product.data('product_id') || '';

                var item = {
                    item_id: productId,
                    item_name: $product.find('.product_title, .woocommerce-loop-product__title').text().trim() || '',
                    price: parseFloat($product.find('.price ins .amount, .price > .amount').first().text().replace(/[^0-9.]/g, '')) || 0,
                    quantity: 1,
                    currency: jdl.cur || 'USD'
                };

                self.push('add_to_wishlist', {
                    currency: jdl.cur || 'USD',
                    value: item.price,
                    items: [item]
                });
            });
        },

        /**
         * Variation change on single product
         */
        bindVariationChange: function () {
            var self = this;

            $(document).on('found_variation', 'form.variations_form', function (e, variation) {
                if (!variation) return;

                var $form = $(this);
                var variantLabel = [];

                $form.find('.variations select').each(function () {
                    var val = $(this).val();
                    if (val) variantLabel.push(val);
                });

                var item = {
                    item_id: variation.variation_id || '',
                    item_name: $form.closest('.product').find('.product_title').text().trim() || '',
                    item_variant: variantLabel.join(' / '),
                    price: parseFloat(variation.display_price) || 0,
                    quantity: 1,
                    currency: jdl.cur || 'USD'
                };

                self.push('select_item', {
                    item_list_name: 'Product Variations',
                    items: [item]
                });
            });
        },

        /**
         * Helper: Get current price from single product page
         */
        getCurrentPrice: function () {
            var $price = $('.product .price ins .woocommerce-Price-amount, .product .price > .woocommerce-Price-amount').first();
            return parseFloat($price.text().replace(/[^0-9.]/g, '')) || 0;
        },

        /**
         * Helper: Get selected variant text from form
         */
        getSelectedVariant: function ($form) {
            var labels = [];
            $form.find('.variations select').each(function () {
                var val = $(this).val();
                if (val) labels.push(val);
            });
            return labels.join(' / ');
        },

        /**
         * Helper: Determine list name from context
         */
        getListName: function () {
            if ($('.woocommerce-shop').length) return 'Shop';
            if ($('.tax-product_cat').length) return 'Category';
            if ($('.search-results').length) return 'Search Results';
            if ($('.related.products').length) return 'Related Products';
            return 'Product List';
        }
    };

    $(document).ready(function () {
        JDLEcom.init();
    });

})(jQuery);
