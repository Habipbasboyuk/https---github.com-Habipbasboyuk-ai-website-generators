/**
 * Save, save-all, shuffle page, compile actions.
 */
(function (app) {
  if (!app) return;

  // --- Save all ---
  if (app.btn.saveAll) {
    app.btn.saveAll.addEventListener("click", async () => {
      app.setStatus("Saving all pages...", "ok");
      let saved = 0,
        failed = 0;
      for (const p of app.state.pages) {
        const model = app.state.pageModels[p.slug];
        if (!model?.sections?.length) continue;
        try {
          const out = await app.post("aisb_update_wireframe_page", {
            project_id: app.state.projectId,
            sitemap_version_id: app.state.sitemapId,
            page_slug: p.slug,
            model_json: JSON.stringify(model),
          });
          if (out?.success) saved++;
          else failed++;
        } catch (e) {
          failed++;
        }
      }
      app.setStatus(
        `Saved ${saved} pages.${failed ? ` Failed: ${failed}.` : ""}`,
        failed ? "err" : "ok",
      );
    });
  }

  // --- Shuffle page ---
  app.btn.shufflePage.addEventListener("click", async () => {
    if (!app.state.model?.sections) return;
    for (const s of app.state.model.sections) {
      if (s.locked) continue;
      const out = await app.post("aisb_shuffle_section_layout", {
        project_id: app.state.projectId,
        sitemap_version_id: app.state.sitemapId,
        page_slug: app.state.pageSlug,
        uuid: s.uuid,
      });
      if (out?.success) app.state.model = out.data.wireframe;
    }
    app.state.pageModels[app.state.pageSlug] = app.state.model;
    app.renderSections();
    app.setStatus("Shuffled unlocked sections.", "ok");
  });

  // --- Save ---
  app.btn.save.addEventListener("click", async () => {
    if (!app.state.model) return;
    app.setStatus("Saving...", "ok");
    app.state.pageModels[app.state.pageSlug] = app.state.model;
    const out = await app.post("aisb_update_wireframe_page", {
      project_id: app.state.projectId,
      sitemap_version_id: app.state.sitemapId,
      page_slug: app.state.pageSlug,
      model_json: JSON.stringify(app.state.model),
    });
    if (out?.success) app.setStatus("Saved.", "ok");
    else app.setStatus(out?.data?.message || "Save failed", "err");
  });

  // --- Compile ---
  app.btn.compile.addEventListener("click", async () => {
    if (!app.state.pageSlug) return;
    app.setStatus("Compiling...", "ok");
    const out = await app.post("aisb_compile_wireframe_page", {
      project_id: app.state.projectId,
      sitemap_version_id: app.state.sitemapId,
      page_slug: app.state.pageSlug,
    });
    if (out?.success) {
      app.el.compiled.textContent = JSON.stringify(out.data.compiled, null, 2);
      app.setStatus("Compiled.", "ok");
    } else app.setStatus(out?.data?.message || "Compile failed", "err");
  });
})(window.AISB_WF_App);
