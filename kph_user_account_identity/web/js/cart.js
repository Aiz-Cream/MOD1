// ==========================================================
// KameraPH UI Kit  |  cart.js  (Shopping Cart Module - Module 1)
// ==========================================================
// Provides KPHCart helper for cart operations:
//   KPHCart.add(product, qty)
//   KPHCart.count()
// Auth: Bearer token only. Never trusts a user ID from the frontend.
//   The cart API lives in kph_shopping_promotions; config.cartApi points there.
// ==========================================================

function cartBase() {
    var k = window.KPH || {};
    return (k.cartApi || k.api || "").replace(/\/$/, "");
}

async function cartFetch(method, path, body) {
    var token = (window.KPH && window.KPHAuth && typeof KPHAuth.getToken === "function" && KPHAuth.getToken()) || "";
    var opts = { method: method, headers: { "Content-Type": "application/json" } };
    if (token) opts.headers.Authorization = "Bearer " + token;
    if (body) opts.body = JSON.stringify(body);
    var res = await fetch(cartBase() + path, opts);
    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok) {
        var msg = (data && (data.error || data.message)) || "Cart request failed (" + res.status + ")";
        var err = new Error(msg);
        err.status = res.status;
        throw err;
    }
    return data;
}

window.KPHCart = {
    /**
     * Add a product to the cart.
     * @param {Object} product - { ProductID, ProductName, UnitPrice, ImageURL }
     * @param {number} qty - Quantity to add (default 1)
     * @returns {Promise<Object>} Updated cart view
     */
    add: async function (product, qty) {
        qty = qty || 1;
        var body = {
            ProductID: product.ProductID || product.id,
            ProductName: product.ProductName || product.name,
            UnitPrice: product.UnitPrice || product.price,
            ImageURL: product.ImageURL || product.image || null,
            Quantity: qty
        };
        return await cartFetch("POST", "/cart.php", body);
    },

    /**
     * Get the current cart item count.
     * @returns {Promise<number>}
     */
    count: async function () {
        var res = await cartFetch("GET", "/cart.php?count=1");
        return (res && res.count) || 0;
    },

    /**
     * Get the full cart view.
     * @returns {Promise<Object>} Cart view with items, subtotal, total
     */
    get: async function () {
        return await cartFetch("GET", "/cart.php");
    },

    /**
     * Update quantity of a cart item.
     * @param {number} productId
     * @param {number} qty
     * @returns {Promise<Object>} Updated cart view
     */
    updateQty: async function (productId, qty) {
        return await cartFetch("PUT", "/cart.php", { ProductID: productId, Quantity: qty });
    },

    /**
     * Remove an item from the cart.
     * @param {number} productId
     * @returns {Promise<Object>} Updated cart view
     */
    remove: async function (productId) {
        return await cartFetch("DELETE", "/cart.php?item=" + encodeURIComponent(productId));
    },

    /**
     * Clear all items from the cart.
     * @returns {Promise<Object>}
     */
    clear: async function () {
        return await cartFetch("DELETE", "/cart.php?all=1");
    },

    /**
     * Add a product and immediately refresh the header cart badge.
     * Returns the updated cart view. Propagates errors to the caller.
     * @param {Object} product - { ProductID, ProductName, UnitPrice, ImageURL }
     * @param {number} [qty=1]
     * @returns {Promise<Object>} Updated cart view
     */
    addAndSync: async function (product, qty) {
        var view = await this.add(product, qty || 1);
        if (window.KPHUI && typeof KPHUI.setBadge === "function") {
            KPHUI.setBadge("cart", (view && view.count) || 0);
        }
        return view;
    }
};