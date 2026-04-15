/**
 * Expanded page view: open/close, renderSections, createBricksIframe.
 */
(function (app) {
  if (!app) return;

  function createBricksIframe(id) {
    const iframe = document.createElement("iframe");
    iframe.src = `${AISB_WF.previewUrl || ""}${id}`;
    iframe.className = "aisb-bricks-iframe";
    iframe.loading = "lazy";
    iframe.title = "Bricks Preview";
    iframe.scrolling = "no";
    return iframe;
  }
  app.createBricksIframe = createBricksIframe;

  app.renderSections = function () {
    if (!app.tpl.sectionCard) return;
    const sections = app.state.model?.sections || [];
    if (!sections.length) {
      app.el.sections.textContent = "No sections yet.";
      return;
    }

    const frag = document.createDocumentFragment();
    sections.forEach((s, idx) => {
      const card = app.tpl.sectionCard.content.cloneNode(true);
      const wrapper = card.querySelector(".aisb-wf-section");
      wrapper.setAttribute("data-uuid", s.uuid);

      const btnUp = card.querySelector('[data-act="up"]');
      const btnDown = card.querySelector('[data-act="down"]');
      const btnShuffle = card.querySelector('[data-act="shuffle"]');
      const btnLock = card.querySelector('[data-act="lock"]');
      if (idx === 0) btnUp.disabled = true;
      if (idx === sections.length - 1) btnDown.disabled = true;
      if (s.locked) {
        btnShuffle.disabled = true;
        btnLock.classList.add("active");
        btnLock.title = "Unlock";
      }

      const body = card.querySelector(".aisb-wf-body");
      const isBricks = !!(s.bricks_template_id || s.ai_wireframe_id);
      if (isBricks) {
        const wrap = document.createElement("div");
        wrap.className = "aisb-wf-iframe-wrap";
        wrap.appendChild(
          createBricksIframe(s.ai_wireframe_id || s.bricks_template_id),
        );
        body.appendChild(wrap);
      } else {
        const label = document.createElement("div");
        label.className = "aisb-wf-section-type-label";
        label.textContent = `${s.type || "generic"} section`;
        body.appendChild(label);
      }

      frag.appendChild(card);
    });

    app.el.sections.replaceChildren(frag);

    // Set initial desktop scale for all iframe wrappers
    app.el.sections.querySelectorAll(".aisb-wf-iframe-wrap").forEach((wrap) => {
      const body = wrap.closest(".aisb-wf-body");
      if (body) {
        const scale = body.offsetWidth / 1200;
        wrap.style.setProperty("--exp-scale", scale.toFixed(4));
        body.style.height = `${Math.ceil(400 * scale)}px`;
      }
    });
  };

  app.openExpandedPage = async function (slug) {
    app.state.pageSlug = slug;
    app.renderWhiteboard();

    if (app.el.expanded) app.el.expanded.classList.add("is-open");
    if (app.el.whiteboard) app.el.whiteboard.classList.add("is-hidden");

    const page = app.state.pages.find((p) => p.slug === slug);
    if (app.el.title)
      app.el.title.textContent = page ? page.title || slug : slug;
    if (app.el.sub) app.el.sub.textContent = `/${slug}`;

    if (app.state.pageModels[slug]) {
      app.state.model = app.state.pageModels[slug];
      app.renderSections();
    } else {
      if (app.el.sub) app.el.sub.textContent = "Loading wireframe...";
      const out = await app.post("aisb_get_wireframe_page", {
        project_id: app.state.projectId,
        sitemap_version_id: app.state.sitemapId,
        page_slug: slug,
      });
      if (out?.success) {
        app.state.model = out.data.wireframe;
        app.state.pageModels[slug] = app.state.model;
        app.renderSections();
        if (app.el.sub) app.el.sub.textContent = `/${slug}`;
      } else {
        app.setStatus(out?.data?.message || "Failed", "err");
      }
    }
  };

  app.closeExpandedPage = function () {
    if (app.el.expanded) app.el.expanded.classList.remove("is-open");
    if (app.el.whiteboard) app.el.whiteboard.classList.remove("is-hidden");
    if (app.state.pageSlug && app.state.model) {
      app.state.pageModels[app.state.pageSlug] = app.state.model;
    }
    app.renderWhiteboard();
  };

  if (app.btn.closeExpanded) {
    app.btn.closeExpanded.addEventListener("click", app.closeExpandedPage);
  }
})(window.AISB_WF_App);
