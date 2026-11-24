// ==========================================================
// KameraPH UI Kit  |  config.js  (Shopping Cart Module)
// ==========================================================
// Cart module configuration. Points to Module 1 for user auth
// and shared pages, and to the local API for cart operations.
// ==========================================================

window.KPH = {
  // This module's own PHP API folder
  api: "../api",

  // Fallback header when no Bearer token is present.
  // Set to empty string so the browser NEVER sends X-Test-User-Id.
  testUserId: "",

  // Shortcut map
  // "." = this module's own web/ folder
  // "../kph_user_account_identity/web" = Module 1 pages
  // "" = module not present in this repo (links disabled)
  users:      "../kph_user_account_identity/web",
  cart:       ".",
  catalog:    "",
  engagement: "",
  auctions:   "",
  shopping:   "",
  orders:     "",
  shipping:   "",
  reviews:    "",
  messaging:  "",
  sellers:    "",
  admin:      "",
  support:    "",

  // Where each module's web/ folder lives
  modules: {
    users:      "../kph_user_account_identity/web",
    cart:       ".",
    catalog:    "",
    engagement: "",
    auctions:   "",
    shopping:   "",
    orders:     "",
    shipping:   "",
    reviews:    "",
    messaging:  "",
    sellers:    "",
    admin:      "",
    support:    ""
  },

  // Top menu (same as Module 1; cart is active)
  nav: [
    { label: "Home",     module: "users",    page: "home.html" },
    { label: "Browse",   module: "catalog",  page: "browse.html" },
    { label: "Auctions", module: "auctions", page: "auctions.html" },
    { label: "Deals",    module: "shopping", page: "deals.html" },
    { label: "Messages", module: "messaging", page: "inbox.html" }
  ],

  // Header icons + search destination
  links: {
    account:  { module: "users",    page: "profile.html" },
    wishlist: { module: "users",    page: "wishlist.html" },
    cart:     { module: "cart",     page: "cart.html" },
    search:   { module: "catalog",  page: "search.html" }
  }
};

// Build a link to a page in a module. Returns "#" when that module is not present,
// so missing modules never produce root-relative 404 URLs.
window.KPH = window.KPH || {};
KPH.modulePage = function (module, page) {
  var b = (KPH.modules[module] || "").replace(/\/$/, "");
  return b ? b + "/" + page : "#";
};