// ==========================================================
// KameraPH UI Kit  |  activity.js
// ==========================================================
// KPHActivity.track(type, entityType, entityId, description, metadata)
// Posts a behaviour event to activity.php. The server derives the
// UserID from the Bearer token; any client-supplied ID is ignored.
//
// Tracking is best-effort: a failed analytics call never breaks the
// page. Anonymous visitors (no token) are not tracked.
// ==========================================================
(function () {
  "use strict";

  var lastCategoryView = { slug: null, at: 0 };

  async function post(payload) {
    if (!window.apiSend) return;
    await apiSend("POST", "/activity.php", payload);
  }

  window.KPHActivity = {
    track: function (type, entityType, entityId, description, metadata) {
      try {
        if (!window.KPHAuth || typeof KPHAuth.getToken !== "function" || !KPHAuth.getToken()) {
          return;
        }
        var payload = {
          EventType: type,
          EntityType: entityType || null,
          EntityID: (entityId === undefined || entityId === null || entityId === "") ? null : entityId,
          Description: description || null,
          Metadata: metadata || null
        };
        // Fire-and-forget; swallow errors so analytics never breaks UX.
        post(payload).catch(function () {});
      } catch (e) { /* ignore */ }
    },

    // category_viewed is recorded once per distinct category view.
    trackCategoryView: function (slug, name) {
      if (!slug) return;
      var now = Date.now();
      if (lastCategoryView.slug === slug && (now - lastCategoryView.at) < 60000) return;
      lastCategoryView = { slug: slug, at: now };
      this.track("category_viewed", "category", null, "Viewed " + (name || slug), { category_slug: slug });
    }
  };
})();