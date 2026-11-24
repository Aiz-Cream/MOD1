// ==========================================================
// KameraPH UI Kit  |  config.js
// Change the addresses below when your API or another
// group's pages run on a different computer or folder.
// ==========================================================
window.KPH = {
  // This module's own PHP API folder
  api: "../api",

  // Shopping Cart API (lives in kph_shopping_promotions; cart frontend is here)
  cartApi: "../../kph_shopping_promotions/api",

  // Commerce catalog / orders / sellers / support API (same module as the cart)
  commerceApi: "../../kph_shopping_promotions/api",

  // Fallback header when no Bearer token is present (for standalone endpoint testing)
  testUserId: "1",

  // Shortcut map
  // Relative base "." = this module's own web/ folder (works under any XAMPP path).
  // Modules not present in this repo are "" so the UI disables their links.
  users:      ".",
  cart:       ".",
  catalog:    "",
  engagement: "",
  auctions:   "",
  shopping:   "",
  orders:     ".",
  shipping:   "",
  reviews:    "",
  messaging:  "",
  sellers:    ".",
  admin:      ".",
  support:    ".",

  // Where each module's web/ folder lives (relative or absolute)
  modules: {
    users:      ".",
    cart:       ".",
    catalog:    "",
    engagement: "",
    auctions:   "",
    shopping:   "",
    orders:     ".",
    shipping:   "",
    reviews:    "",
    messaging:  "",
    sellers:    ".",
    admin:      ".",
    support:    "."
  },

  // Top menu (the same on every module's pages)
  nav: [
    { label: "Home",     module: "users",    page: "home.html" },
    { label: "Deals",    module: "shopping", page: "deals.html" },
    { label: "Messages", module: "messaging",page: "inbox.html" }
  ],

  // Header icons + search destination
  links: {
    account:  { module: "users",    page: "profile.html" },
    wishlist: { module: "users",    page: "wishlist.html" },
    cart:     { module: "cart",     page: "cart.html" },
    search:   { module: "users",    page: "search.html" },
    purchases:{ module: "orders",   page: "purchases.html" },
    sellers:  { module: "sellers",  page: "seller.html" },
    support:  { module: "support",  page: "help.html" }
  }
};

// Build a link to a page in a module. Returns "#" when that module is not present,
// so missing modules never produce root-relative 404 URLs.
window.KPH = window.KPH || {};
KPH.modulePage = function (module, page) {
  var b = (KPH.modules[module] || "").replace(/\/$/, "");
  return b ? b + "/" + page : "#";
};
