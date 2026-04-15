/**
 * Generate wireframe for single page and all pages.
 */
(function (app) {
  if (!app) return;

  app.btn.generate.addEventListener("click", async () => {
    if (!app.state.pageSlug) return;
    app.showCanvasLoader("Generating wireframe…");
    app.setStatus("Generating wireframe...", "ok");
    const out = await app.post("aisb_generate_wireframe_page", {
      project_id: app.state.projectId,
      sitemap_version_id: app.state.sitemapId,
      page_slug: app.state.pageSlug,
    });
    if (out?.success) {
      app.state.model = out.data.wireframe;
      app.state.pageModels[app.state.pageSlug] = app.state.model;
      app.renderSections();
      app.setStatus("Generated.", "ok");
    } else {
      app.setStatus(out?.data?.message || "Generate failed", "err");
    }
    app.renderWhiteboard();
    app.hideCanvasLoader();
  });

  app.btn.generateAll?.addEventListener("click", async () => {
    if (!app.state.pages?.length) {
      app.setStatus("No pages found in sitemap.", "err");
      return;
    }
    if (
      !confirm(
        "This will generate wireframes for ALL pages. Existing un-saved edits could be overridden. Continue?",
      )
    )
      return;

    app.btn.generateAll.disabled = true;
    if (app.btn.generate) app.btn.generate.disabled = true;
    app.showCanvasLoader("Generating wireframes…");
    let ok = 0,
      fail = 0;

    for (const p of app.state.pages) {
      app.showCanvasLoader(
        `Generating ${p.title} (${ok + fail + 1}/${app.state.pages.length})…`,
      );
      app.setStatus(
        `Generating wireframe for ${p.title} (${ok + fail + 1}/${app.state.pages.length})...`,
        "ok",
      );
      try {
        const out = await app.post("aisb_generate_wireframe_page", {
          project_id: app.state.projectId,
          sitemap_version_id: app.state.sitemapId,
          page_slug: p.slug,
        });
        if (out?.success) {
          app.state.pageModels[p.slug] = out.data.wireframe;
          await app.post("aisb_update_wireframe_page", {
            project_id: app.state.projectId,
            sitemap_version_id: app.state.sitemapId,
            page_slug: p.slug,
            model_json: JSON.stringify(out.data.wireframe),
          });
          ok++;
        } else {
          fail++;
        }
      } catch (e) {
        fail++;
      }
      app.renderWhiteboard();
    }

    app.btn.generateAll.disabled = false;
    if (app.btn.generate) app.btn.generate.disabled = false;
    if (app.state.pageSlug && app.state.pageModels[app.state.pageSlug]) {
      app.state.model = app.state.pageModels[app.state.pageSlug];
      app.renderSections();
    }
    app.renderWhiteboard();
    app.hideCanvasLoader();
    app.setStatus(
      `Finished! Generated: ${ok}. Failed: ${fail}.`,
      fail > 0 ? "err" : "ok",
    );
  });
})(window.AISB_WF_App);
