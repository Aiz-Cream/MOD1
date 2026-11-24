// KameraPH | analytics.js: client for api/analytics.php (Bearer auth, uses apiGet)
(function () {
  "use strict";
  function qs(o) {
    return Object.keys(o).map(function (k) { return k + "=" + encodeURIComponent(o[k]); }).join("&");
  }
  window.KPHAnalytics = {
    // scope: "admin" | "seller"; report: summary | top_viewed | popular_searches | abandoned_carts
    report: function (scope, report, extra) {
      return apiGet("/analytics.php?" + qs(Object.assign({ scope: scope, report: report }, extra || {})));
    },
    recentlyViewed: function (limit) { return apiGet("/analytics.php?" + qs({ report: "recently_viewed", limit: limit || 10 })); },
    recommendations: function (limit) { return apiGet("/analytics.php?" + qs({ report: "recommendations", limit: limit || 6 })); },
    myInterests: function () { return apiGet("/analytics.php?" + qs({ report: "my_interests" })); },
    mySummary: function () { return apiGet("/analytics.php?" + qs({ report: "my_summary" })); },
    myAbandoned: function (limit) { return apiGet("/analytics.php?" + qs({ report: "my_abandoned", limit: limit || 6 })); },
    trending: function (limit) { return apiGet("/analytics.php?" + qs({ report: "trending", limit: limit || 6 })); },
    alsoViewed: function (productId, limit) { return apiGet("/analytics.php?" + qs({ report: "also_viewed", product_id: productId, limit: limit || 6 })); }
  };
})();
