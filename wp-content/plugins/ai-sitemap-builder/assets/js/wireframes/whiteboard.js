/**
 * Whiteboard rendering: page cards with scaled iframe previews.
 */
(function (app) {
  if (!app) return;
  const inner = app.el.canvasInner;
  if (!inner) return;

  app.renderWhiteboard = function () {
    const frag = document.createDocumentFragment();

    for (const p of app.state.pages) {
      const model = app.state.pageModels[p.slug];
      const sections = model?.sections || [];

      const card = document.createElement("div");
      card.className =
        "aisb-wf-page-card" +
        (p.slug === app.state.pageSlug ? " is-active" : "");
      card.setAttribute("data-wb-page", p.slug);

      // Header
      const head = document.createElement("div");
      head.className = "aisb-wf-page-card-head";

      const titleWrap = document.createElement("div");
      const titleEl = document.createElement("div");
      titleEl.className = "aisb-wf-page-card-title";
      titleEl.textContent = p.title || p.slug;
      const slugEl = document.createElement("div");
      slugEl.className = "aisb-wf-page-card-slug";
      slugEl.textContent = `/${p.slug}`;
      titleWrap.appendChild(titleEl);
      titleWrap.appendChild(slugEl);

      const badge = document.createElement("span");
      badge.className =
        "aisb-wf-page-card-badge" + (sections.length ? " has-sections" : "");
      badge.textContent = sections.length
        ? `${sections.length} sections`
        : "Empty";

      head.appendChild(titleWrap);
      head.appendChild(badge);
      card.appendChild(head);

      // Scaled-down wireframe preview (real iframes)
      const body = document.createElement("div");
      body.className = "aisb-wf-page-card-body";

      if (sections.length) {
        const preview = document.createElement("div");
        preview.className = "aisb-wf-page-card-preview";

        for (const s of sections) {
          const isBricks = !!(s.bricks_template_id || s.ai_wireframe_id);
          if (isBricks) {
            const iframe = document.createElement("iframe");
            iframe.src = `${AISB_WF.previewUrl || ""}${s.ai_wireframe_id || s.bricks_template_id}`;
            iframe.className = "aisb-bricks-iframe aisb-wb-iframe";
            iframe.loading = "lazy";
            iframe.title = `${s.type || "section"} preview`;
            iframe.scrolling = "no";
            iframe.tabIndex = -1;
            preview.appendChild(iframe);
          } else {
            const label = document.createElement("div");
            label.className = "aisb-wf-section-type-label";
            label.textContent = `${s.type || "generic"} section`;
            preview.appendChild(label);
          }
        }

        body.appendChild(preview);
      } else {
        const empty = document.createElement("div");
        empty.className = "aisb-wf-page-card-empty";
        empty.textContent = "No wireframe yet.";
        body.appendChild(empty);
      }

      card.appendChild(body);
      frag.appendChild(card);
    }

    inner.replaceChildren(frag);

    // Set scale + fit to view
    requestAnimationFrame(() => {
      for (const preview of inner.querySelectorAll(
        ".aisb-wf-page-card-preview",
      )) {
        const cardBody = preview.parentElement;
        if (!cardBody) continue;
        const scale = cardBody.offsetWidth / 1200;
        preview.style.setProperty("--wb-scale", scale.toFixed(4));
        // With transform: scale(), layout size stays 1200px.
        // Set body height to the scaled preview height.
        const iframeCount = preview.querySelectorAll("iframe").length || 1;
        cardBody.style.height = Math.ceil(iframeCount * 600 * scale) + "px";
      }
      app.fitToView();
    });
  };

  // Click → open expanded view
  app.el.whiteboard.addEventListener("click", (e) => {
    if (app.isPanning) return;
    const card = e.target.closest("[data-wb-page]");
    if (card) app.openExpandedPage(card.getAttribute("data-wb-page"));
  });
})(window.AISB_WF_App);
