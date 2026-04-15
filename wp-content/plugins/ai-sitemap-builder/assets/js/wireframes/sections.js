/**
 * Section actions (event delegation) and iframe message handling.
 */
(function (app) {
  if (!app) return;

  // --- Rescale expanded-view iframes on resize ---
  window.addEventListener("resize", () => {
    document.querySelectorAll(".aisb-wf-iframe-wrap").forEach((wrap) => {
      const body = wrap.closest(".aisb-wf-body");
      const iframe = wrap.querySelector("iframe");
      if (body && iframe) {
        const scale = body.offsetWidth / 1200;
        wrap.style.setProperty("--exp-scale", scale.toFixed(4));
        const h = parseInt(iframe.style.height, 10) || 400;
        body.style.height = `${Math.ceil(h * scale)}px`;
      }
    });
  });

  // --- Iframe auto-resize + edit responses ---
  window.addEventListener("message", (e) => {
    if (!e.data?.type) return;

    if (e.data.type === "aisb_iframe_height" && e.data.height) {
      for (const iframe of document.querySelectorAll(
        "iframe.aisb-bricks-iframe",
      )) {
        if (iframe.contentWindow === e.source) {
          iframe.style.height = `${e.data.height}px`;

          // Expanded view: scale wrapper and body
          const wrap = iframe.closest(".aisb-wf-iframe-wrap");
          const body = iframe.closest(".aisb-wf-body");
          if (wrap && body) {
            const scale = body.offsetWidth / 1200;
            wrap.style.setProperty("--exp-scale", scale.toFixed(4));
            body.style.height = `${Math.ceil(e.data.height * scale)}px`;
          }

          // Whiteboard card: update card body height based on total scaled preview height
          const preview = iframe.closest(".aisb-wf-page-card-preview");
          if (preview) {
            const cardBody = preview.parentElement;
            if (cardBody) {
              const scale =
                parseFloat(preview.style.getPropertyValue("--wb-scale")) ||
                cardBody.offsetWidth / 1200;
              let totalH = 0;
              for (const f of preview.querySelectorAll("iframe")) {
                totalH += parseInt(f.style.height, 10) || 600;
              }
              cardBody.style.height = Math.ceil(totalH * scale) + "px";
            }
          }
          break;
        }
      }
    }

    if (e.data.type === "aisb_edited_content") {
      let sectionCard = null;
      for (const iframe of document.querySelectorAll(
        "iframe.aisb-bricks-iframe",
      )) {
        if (iframe.contentWindow === e.source) {
          sectionCard = iframe.closest("[data-uuid]");
          break;
        }
      }
      if (!sectionCard || !app.state.model) return;
      const uuid = sectionCard.getAttribute("data-uuid");
      const section = app.state.model.sections?.find((s) => s.uuid === uuid);
      if (!section || !(section.ai_wireframe_id || section.bricks_template_id))
        return;

      const changes = e.data.changes || [];
      if (!changes.length) {
        app.setStatus("No changes detected.", "ok");
        return;
      }

      app
        .post("aisb_save_section_text", {
          bricks_template_id:
            section.ai_wireframe_id || section.bricks_template_id,
          changes: JSON.stringify(changes),
        })
        .then((out) => {
          if (out?.success) {
            app.setStatus(
              `Saved ${out.data.changed || 0} text change(s). Refreshing...`,
              "ok",
            );
            const iframe = sectionCard.querySelector(
              "iframe.aisb-bricks-iframe",
            );
            if (iframe) iframe.src = iframe.src;
          } else {
            app.setStatus(
              `Save failed: ${out?.data?.message || "Unknown error"}`,
              "err",
            );
          }
        });
    }
  });

  // --- Section actions (click delegation) ---
  app.el.sections.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-act]");
    if (!btn) return;
    const card = e.target.closest("[data-uuid]");
    if (!card || !app.state.model) return;
    const uuid = card.getAttribute("data-uuid");
    const act = btn.getAttribute("data-act");
    const sections = app.state.model.sections || [];
    const idx = sections.findIndex((s) => s.uuid === uuid);
    if (idx < 0) return;

    if (act === "up" && idx > 0) {
      [sections[idx - 1], sections[idx]] = [sections[idx], sections[idx - 1]];
      app.renderSections();
      return;
    }
    if (act === "down" && idx < sections.length - 1) {
      [sections[idx], sections[idx + 1]] = [sections[idx + 1], sections[idx]];
      app.renderSections();
      return;
    }
    if (act === "del") {
      sections.splice(idx, 1);
      app.renderSections();
      return;
    }
    if (act === "dup") {
      const clone = JSON.parse(JSON.stringify(sections[idx]));
      clone.uuid =
        crypto?.randomUUID?.() || `dup_${Math.random().toString(16).slice(2)}`;
      clone.locked = false;
      sections.splice(idx + 1, 0, clone);
      app.renderSections();
      return;
    }
    if (act === "lock") {
      sections[idx].locked = !sections[idx].locked;
      app.renderSections();
      return;
    }

    if (act === "shuffle") {
      app.setStatus("Shuffling section...", "ok");
      const out = await app.post("aisb_shuffle_section_layout", {
        project_id: app.state.projectId,
        sitemap_version_id: app.state.sitemapId,
        page_slug: app.state.pageSlug,
        uuid,
      });
      if (out?.success) {
        app.state.model = out.data.wireframe;
        app.renderSections();
        app.setStatus("Shuffled.", "ok");
      } else app.setStatus(out?.data?.message || "Shuffle failed", "err");
      return;
    }

    if (act === "edit") {
      const section = sections[idx];
      if (!section.bricks_template_id) {
        app.setStatus("Only Bricks sections can be edited inline.", "err");
        return;
      }
      const iframe = card.querySelector("iframe.aisb-bricks-iframe");
      if (!iframe?.contentWindow) {
        app.setStatus("Preview iframe not loaded yet.", "err");
        return;
      }

      if (card.classList.contains("aisb-editing")) {
        card.classList.remove("aisb-editing");
        btn.classList.remove("active");
        iframe.contentWindow.postMessage({ type: "aisb_disable_edit" }, "*");
        iframe.style.overflow = "hidden";
        app.setStatus("Saving changes...", "ok");
        iframe.contentWindow.postMessage(
          { type: "aisb_get_edited_content" },
          "*",
        );
      } else {
        card.classList.add("aisb-editing");
        btn.classList.add("active");
        iframe.style.overflow = "auto";
        iframe.contentWindow.postMessage({ type: "aisb_enable_edit" }, "*");
        app.setStatus(
          "Edit mode: click text in preview to change it. Click ✏️ again to save.",
          "ok",
        );
      }
    }
  });

  // --- Section type change ---
  app.el.sections.addEventListener("change", async (e) => {
    const sel = e.target.closest('[data-act="type"]');
    if (!sel) return;
    const card = e.target.closest("[data-uuid]");
    if (!card) return;
    app.setStatus("Replacing section type...", "ok");
    const out = await app.post("aisb_replace_section_type", {
      project_id: app.state.projectId,
      sitemap_version_id: app.state.sitemapId,
      page_slug: app.state.pageSlug,
      uuid: card.getAttribute("data-uuid"),
      new_type: sel.value,
    });
    if (out?.success) {
      app.state.model = out.data.wireframe;
      app.renderSections();
      app.setStatus("Replaced.", "ok");
    } else app.setStatus(out?.data?.message || "Replace failed", "err");
  });
})(window.AISB_WF_App);
