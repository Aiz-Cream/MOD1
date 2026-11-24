// ==========================================================
// KameraPH UI Kit  |  cart.js  (Shopping Cart Module)
// ==========================================================
// Provides KPHCart helper for cart operations:
//   KPHCart.add(product, qty)
//   KPHCart.count()
// ==========================================================

window.KPHCart = {
    /**
     * Add a product to the cart.
     * @param {Object} product - { ProductID, ProductName, UnitPrice, ImageURL }
     * @param {number} qty - Quantity to add (default 1)
     * @returns {Promise<Object>} Updated cart view
     */
    add: async function (product, qty) {
        qty = qty || 1;
        const body = {
            ProductID: product.ProductID || product.id,
            ProductName: product.ProductName || product.name,
            UnitPrice: product.UnitPrice || product.price,
            ImageURL: product.ImageURL || product.image || null,
            Quantity: qty
        };
        return await apiSend("POST", "/cart.php", body);
    },

    /**
     * Get the current cart item count.
     * @returns {Promise<number>}
     */
    count: async function () {
        const res = await apiGet("/cart.php?count=1");
        return res.count || 0;
    },

    /**
     * Get the full cart view.
     * @returns {Promise<Object>} Cart view with items, subtotal, total
     */
    get: async function () {
        return await apiGet("/cart.php");
    },

    /**
     * Update quantity of a cart item.
     * @param {number} productId
     * @param {number} qty
     * @returns {Promise<Object>} Updated cart view
     */
    updateQty: async function (productId, qty) {
        return await apiSend("PUT", "/cart.php", { ProductID: productId, Quantity: qty });
    },

    /**
     * Remove an item from the cart.
     * @param {number} productId
     * @returns {Promise<Object>} Updated cart view
     */
    remove: async function (productId) {
        return await apiSend("DELETE", "/cart.php?item=" + encodeURIComponent(productId));
    },

    /**
     * Clear all items from the cart.
     * @returns {Promise<Object>}
     */
    clear: async function () {
        return await apiSend("DELETE", "/cart.php?all=1");
    }
};