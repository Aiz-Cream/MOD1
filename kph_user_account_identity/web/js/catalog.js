// ==========================================================
// KameraPH UI Kit  |  catalog.js  (shared product data)
// ==========================================================
// Single source of truth for the existing homepage products.
// home.html, wishlist.html and search.html all import this file,
// so product names, images, prices, ratings and search results
// never drift between pages. No DB / API / duplicate listing.
//
// Exposes window.KPHProducts:
//   data        { categories, popular, arrivals }  (exact homepage SAMPLE)
//   products    popular.concat(arrivals)
//   byId(id)    product or null
//   search(q)   array of products whose name matches q (empty q -> all)
//   stars(r)    accessible full/half/empty star HTML
//   ratingHtml(p)  stars + numeric rating + review count (or "No reviews yet")
// ==========================================================
(function () {
  "use strict";

  // Exact copy of the homepage SAMPLE object (home.html).
  var DATA = {
    categories: [
      { id: 1, slug: "cameras",     name: "Cameras",     note: "Mirrorless and DSLR" },
      { id: 2, slug: "lenses",      name: "Lenses",      note: "Prime and zoom" },
      { id: 3, slug: "drones",      name: "Drones",      note: "Aerial and action" },
      { id: 4, slug: "accessories", name: "Accessories", note: "Bags, tripods and more" }
    ],
    popular: [
      { id: 101, category: "cameras",     name: "Canon EOS R6 Mark II",       price: 154995, oldPrice: null,   rating: 4.9, image: "images/canon.jpg" },
      { id: 102, category: "cameras",     name: "Sony Alpha a7 IV",           price: 124995, oldPrice: null,   rating: 4.9, image: "images/sony.jpg" },
      { id: 103, category: "lenses",      name: "Canon RF 24-70mm f/2.8L",    price: 89995,  oldPrice: 99995,  rating: 4.8, image: "images/canonrf.jpg" },
      { id: 104, category: "drones",      name: "DJI Mini 3 Pro",             price: 34995,  oldPrice: null,   rating: 4.9, image: "images/minipro.jpg" }
    ],
    arrivals: [
      { id: 201, category: "cameras",     name: "Fujifilm X-T5",              price: 94995,  oldPrice: null,   rating: 4.8, image: "images/fuji.jpg" },
      { id: 202, category: "cameras",     name: "Nikon Z6 III",               price: 119995, oldPrice: 129995, rating: 4.7, image: "images/nikon.jpg" },
      { id: 203, category: "lenses",      name: "Sigma 35mm f/1.4 DG DN",     price: 42995,  oldPrice: null,   rating: 4.9, image: "images/sigma.jpg" },
      { id: 204, category: "accessories", name: "Peak Design Everyday Sling", price: 6495,   oldPrice: null,   rating: 4.6, image: "images/bag.jpg" }
    ]
  };

  var PRODUCTS = DATA.popular.concat(DATA.arrivals);

  function esc(v) {
    return String(v == null ? "" : v)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function byId(id) {
    var s = String(id);
    for (var i = 0; i < PRODUCTS.length; i++) {
      if (String(PRODUCTS[i].id) === s) return PRODUCTS[i];
    }
    return null;
  }

  function search(query) {
    var q = String(query == null ? "" : query).trim().toLowerCase();
    if (!q) return PRODUCTS.slice();
    var out = [];
    for (var i = 0; i < PRODUCTS.length; i++) {
      var name = String(PRODUCTS[i].name || "").toLowerCase();
      if (name.indexOf(q) !== -1) out.push(PRODUCTS[i]);
    }
    return out;
  }

  // Full / half / empty stars. Rounds to the nearest half (clamped 0..5).
  function stars(rating) {
    var r = Number(rating);
    if (!isFinite(r)) r = 0;
    if (r < 0) r = 0;
    if (r > 5) r = 5;
    var rounded = Math.round(r * 2) / 2;
    var full = Math.floor(rounded);
    var half = (rounded - full) >= 0.5 ? 1 : 0;
    var empty = 5 - full - half;

    var html = '<span class="stars" role="img" aria-label="Rated ' + r + ' out of 5">';
    var i;
    for (i = 0; i < full; i++) html += '<span class="full" aria-hidden="true">&#9733;</span>';
    if (half) html += '<span class="half" aria-hidden="true">&#9733;</span>';
    for (i = 0; i < empty; i++) html += '<span class="empty" aria-hidden="true">&#9733;</span>';
    html += "</span>";
    return html;
  }

  // Stars + numeric rating + review count (only if the product has one).
  function ratingHtml(p) {
    var r = (p && p.rating != null) ? p.rating : 0;
    var count = (p && p.reviewCount != null)
      ? ' <span class="rating-count">(' + esc(p.reviewCount) + ")</span>"
      : ' <span class="rating-count muted">&middot; No reviews yet</span>';
    return stars(r) + ' <span class="rating-value">' + esc(r) + "</span>" + count;
  }

  window.KPHProducts = {
    data: DATA,
    products: PRODUCTS,
    byId: byId,
    search: search,
    stars: stars,
    ratingHtml: ratingHtml
  };
})();