/**
 * Sitemap loading and page model fetching.
 */
(function (app) {
  if (!app) return;

  function normalizeSlug(v) {
    return (v || "").toString().trim().replace(/^\//, "");
  }

  function pushUnique(list, item) {
    if (item?.slug && !list.some((x) => x.slug === item.slug)) list.push(item);
  }

  function flattenHierarchy(nodes, result) {
    if (!Array.isArray(nodes)) return;
    for (const n of nodes) {
      if (!n || typeof n !== "object") continue;
      const slug = normalizeSlug(
        n.slug || n.page_slug || n.url || n.path || "",
      );
      const title = (n.title || n.name || n.label || slug || "").toString();
      if (slug) pushUnique(result, { slug, title });
      flattenHierarchy(n.children || n.items || n.pages || n.subpages, result);
    }
  }

  function extractPages(arr) {
    const result = [];
    if (!Array.isArray(arr)) return result;
    for (const p of arr) {
      if (!p) continue;
      const slug = normalizeSlug(p.slug || p.page_slug || p.url || p.path);
      const title = (
        p.page_title ||
        p.nav_label ||
        p.title ||
        p.name ||
        p.label ||
        slug ||
        ""
      ).toString();
      if (slug) pushUnique(result, { slug, title });
    }
    return result;
  }

  app.loadAllPageModels = async function () {
    await Promise.all(
      app.state.pages.map(async (p) => {
        try {
          const out = await app.post("aisb_get_wireframe_page", {
            project_id: app.state.projectId,
            sitemap_version_id: app.state.sitemapId,
            page_slug: p.slug,
          });
          if (out?.success) app.state.pageModels[p.slug] = out.data.wireframe;
        } catch (e) {
          /* ignore */
        }
      }),
    );
  };

  app.loadSitemapPages = async function () {
    if (!app.state.projectId || !app.state.sitemapId) {
      app.el.pages.textContent =
        "Open this screen with ?aisb_project=ID&aisb_sitemap=ID";
      return;
    }
    const out = await app.postCore("aisb_get_sitemap_by_id", {
      sitemap_id: app.state.sitemapId,
    });
    if (!out?.success) {
      app.el.pages.textContent = "Failed to load sitemap.";
      return;
    }
    const data = out.data?.data || out.data || {};

    let normalized = extractPages(data.sitemap);
    if (!normalized.length) normalized = extractPages(data.pages);
    if (!normalized.length) {
      normalized = [];
      flattenHierarchy(
        data.hierarchy ||
          data.tree ||
          data.structure ||
          data.sitemap ||
          data.navigation,
        normalized,
      );
    }

    app.state.pages = normalized;
    await app.loadAllPageModels();
    app.renderWhiteboard();
  };
})(window.AISB_WF_App);
