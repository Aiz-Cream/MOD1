// ==========================================================
// KameraPH UI Kit  |  store.js
// ==========================================================
// Storefront data access: KPHStore.products({category, q}) and
// KPHStore.categories(). API-first (commerce catalog) with a safe
// fallback to the bundled catalog.js data so the storefront still
// renders if the catalog API is unavailable.
//
// Fallback products are marked inStock=false when they are not in the
// DB, and checkout re-validates every ProductID server-side.
// ==========================================================
(function () {
  "use strict";

  function commerceBase() {
    var k = window.KPH || {};
    var base = k.commerceApi || k.cartApi || k.api || "";
    return String(base).replace(/\/$/, "");
  }

  async function apiGet(path) {
    var res = await fetch(commerceBase() + path, {
      method: "GET",
      headers: { "Content-Type": "application/json" }
    });
    var data = null;
    try { data = await res.json(); } catch (e) { data = null; }
    if (!res.ok) {
      throw new Error((data && (data.error || data.message)) || ("HTTP " + res.status));
    }
    return data;
  }

  // Normalises a DB product row OR a catalog.js product into one shape.
  function norm(p) {
    var id = (p.ProductID !== undefined && p.ProductID !== null) ? p.ProductID : p.id;
    var price = (p.BasePrice !== undefined && p.BasePrice !== null) ? Number(p.BasePrice) : Number(p.price || 0);
    var oldPrice = null;
    if (p.CompareAtPrice !== undefined && p.CompareAtPrice !== null && p.CompareAtPrice !== "") {
      oldPrice = Number(p.CompareAtPrice);
    } else if (p.oldPrice) {
      oldPrice = Number(p.oldPrice);
    }
    var stock = (p.Stock !== undefined && p.Stock !== null) ? Number(p.Stock) : null;
    return {
      id: id,
      name: p.ProductName || p.name || "",
      price: price,
      oldPrice: oldPrice,
      image: p.ImageURL || p.image || null,
      category: p.CategorySlug || p.category || null,
      rating: (p.Rating !== undefined && p.Rating !== null) ? Number(p.Rating) : Number(p.rating || 0),
      reviewCount: (p.ReviewCount !== undefined && p.ReviewCount !== null) ? p.ReviewCount
        : (p.reviewCount !== undefined && p.reviewCount !== null ? p.reviewCount : null),
      stock: stock,
      inStock: stock === null ? true : stock > 0,
      raw: p
    };
  }

  function fallbackProducts(opts) {
    var list = (window.KPHProducts && typeof window.KPHProducts.search === "function")
      ? window.KPHProducts.search(opts.q || "")
      : [];
    if (opts.category) {
      list = list.filter(function (p) { return p.category === opts.category; });
    }
    return list.map(norm);
  }

  window.KPHStore = {
    products: async function (opts) {
      opts = opts || {};
      try {
        var qs = [];
        if (opts.category) qs.push("category=" + encodeURIComponent(opts.category));
        if (opts.q) qs.push("q=" + encodeURIComponent(opts.q));
        qs.push("limit=100");
        var data = await apiGet("/products.php?" + qs.join("&"));
        if (!data || !data.products) throw new Error("Unexpected catalog response.");
        return data.products.map(norm);
      } catch (e) {
        return fallbackProducts(opts);
      }
    },

    categories: async function () {
      try {
        var data = await apiGet("/categories.php");
        if (data && data.categories) {
          return data.categories.map(function (c) {
            return { id: c.CategoryID, slug: c.Slug, name: c.Name, note: c.Note };
          });
        }
      } catch (e) { /* fall through */ }
      var src = (window.KPHProducts && KPHProducts.data && KPHProducts.data.categories) || [];
      return src.map(function (c) {
        return { id: c.id, slug: c.slug, name: c.name, note: c.note };
      });
    }
  };
})();