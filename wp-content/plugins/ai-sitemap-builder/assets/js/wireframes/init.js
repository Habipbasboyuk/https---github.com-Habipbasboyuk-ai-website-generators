/**
 * Entry point — kicks off sitemap loading after all modules are registered.
 */
(function (app) {
  if (!app) return;
  app.loadSitemapPages();
})(window.AISB_WF_App);
