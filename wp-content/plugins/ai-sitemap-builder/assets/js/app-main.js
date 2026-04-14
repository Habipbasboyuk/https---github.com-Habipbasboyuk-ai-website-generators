/**
 * Main: AJAX operations, renderAll, event listeners, initialization.
 * Depends on: app-init.js, app-utils.js, app-canvas.js, app-ui.js
 */
(function () {
  "use strict";

  const app = window.AISBApp;
  if (!app) return;

  const {
    promptEl,
    languagesEl,
    pageCountEl,
    btnGen,
    btnCopy,
    btnReset,
    btnApprove,
    btnGoWireframes,
    btnAddPageTop,
    step2TabEl,
    outWrap,
    rawEl,
    nodesEl,
    edgesSvg,
    canvasEl,
    summaryEl,
    detailTitleEl,
    detailSubEl,
    detailBodyEl,
    state,
    view,
  } = app;

  /* ── Add page form on home ──────────────────────────────── */

  const openAddPageFormOnHome = () => {
    state.openInlineFormFor =
      state.openInlineFormFor === "home" ? null : "home";
    app.renderCanvas({ skipLayout: true });
    requestAnimationFrame(app.drawEdges);
  };
  btnAddPageTop?.addEventListener("click", openAddPageFormOnHome);

  /* ── AJAX: add child page ───────────────────────────────── */

  const addChildPage = async (parentSlug, title, desc) => {
    const parent = state.bySlug[parentSlug];
    if (!parent) {
      app.setStatus('<div class="aisb-error">Parent not found.</div>');
      return;
    }

    const form = new FormData();
    form.append("action", AISB.actionAddPage);
    form.append("nonce", AISB.nonce);
    form.append("parent_slug", parentSlug);
    form.append("title", title);
    form.append("desc", desc);

    const siteContext = {
      website_name: state.data?.website_name || "",
      website_goal: state.data?.website_goal || "",
      primary_audiences: state.data?.primary_audiences || [],
      notes: state.data?.notes || [],
      existing_pages: (state.pages || []).map((p) => ({
        slug: p.slug,
        page_title: p.page_title,
        page_type: p.page_type,
        parent_slug: p.parent_slug,
      })),
    };
    form.append("site_context", JSON.stringify(siteContext));

    try {
      const res = await fetch(AISB.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: form,
      });
      const json = await res.json();
      if (!res.ok || !json || json.success !== true) {
        const msg =
          json && json.data && json.data.message
            ? json.data.message
            : "Unexpected error.";
        const raw =
          json && json.data && json.data.raw
            ? '<details><summary>Raw</summary><pre class="aisb-pre">' +
              app.esc(json.data.raw) +
              "</pre></details>"
            : "";
        app.setStatus(
          '<div class="aisb-error">' + app.esc(msg) + "</div>" + raw,
        );
        return;
      }

      const page = json.data.page;
      if (!page || typeof page !== "object") {
        app.setStatus(
          '<div class="aisb-error">Invalid add-page response.</div>',
        );
        return;
      }

      page.slug =
        app.normalizeSlug(page.slug) || app.slugify(page.page_title || title);
      page.parent_slug = parentSlug || "home";
      app.ensureRequiredSections(page);

      if (state.bySlug[page.slug])
        page.slug = page.slug + "-" + Math.random().toString(16).slice(2, 5);

      if (state.data && Array.isArray(state.data.sitemap))
        state.data.sitemap.push(page);

      state.pages = app.ensureHierarchy(state.pages);
      state.bySlug = app.buildIndex(state.pages);
      state.tree = app.buildTree(state.bySlug);
      state.projectId = json.data.project_id || state.projectId;

      const parentEl = nodesEl.querySelector(
        `.aisb-node-card[data-slug="${CSS.escape(parentSlug)}"]`,
      );
      const parentPos = app.getNodePos(parentSlug);
      const approxParentH = parentEl ? parentEl.offsetHeight : 220;

      const newP = state.bySlug[page.slug];
      if (newP && !newP._userMoved) {
        newP._x = parentPos.x;
        newP._y = parentPos.y + approxParentH + 90;
      }

      state.openInlineFormFor = null;

      app.renderSummary(state.data);
      rawEl.textContent = JSON.stringify(state.data, null, 2);
      app.renderCanvas();
      app.fitToView();
      app.setActive(page.slug);
      app.setStatus('<div class="aisb-ok">Child page created.</div>');
    } catch (e) {
      app.setStatus(
        '<div class="aisb-error">' +
          app.esc(e.message || "Request failed") +
          "</div>",
      );
    }
  };

  /* ── Scroll to output ───────────────────────────────────── */

  const scrollToOutput = () => {
    if (!outWrap) return;
    outWrap.scrollIntoView({ behavior: "smooth", block: "start" });
  };

  /* ── Render all ─────────────────────────────────────────── */

  const renderAll = (data) => {
    outWrap.style.display = "flex";

    const pages = app.ensureHierarchy(
      Array.isArray(data.sitemap) ? data.sitemap : [],
    );
    data.sitemap = pages;

    state.data = data;
    state.pages = pages;
    state.bySlug = app.buildIndex(pages);
    state.tree = app.buildTree(state.bySlug);
    state.edges = app.getEdgeList();

    state.baselineData = app.deepClone(data);
    app.renderSummary(data);
    rawEl.textContent = JSON.stringify(data, null, 2);

    view.tx = 0;
    view.ty = 0;
    view.scale = 1;
    app.applyTransform();

    state.activeSlug = "home";
    state.openInlineFormFor = null;

    app.renderCanvas();
    app.setActive("home");

    requestAnimationFrame(() => {
      scrollToOutput();
    });
  };

  /* ── AJAX: load sitemaps ────────────────────────────────── */

  const loadLatestForProject = async (projectId) => {
    const form = new FormData();
    form.append("action", AISB.actionGetLatestSitemap);
    form.append("nonce", AISB.nonce);
    form.append("project_id", projectId);

    const res = await fetch(AISB.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form,
    });
    const json = await res.json();
    if (!res.ok || !json || json.success !== true) {
      const msg =
        json && json.data && json.data.message
          ? json.data.message
          : "Could not load latest sitemap.";
      throw new Error(msg);
    }
    return json.data;
  };

  const loadSitemapById = async (sitemapId) => {
    const form = new FormData();
    form.append("action", AISB.actionGetSitemapById);
    form.append("nonce", AISB.nonce);
    form.append("sitemap_id", sitemapId);

    const res = await fetch(AISB.ajaxUrl, {
      method: "POST",
      credentials: "same-origin",
      body: form,
    });
    const json = await res.json();
    if (!res.ok || !json || json.success !== true) {
      const msg =
        json && json.data && json.data.message
          ? json.data.message
          : "Could not load sitemap version.";
      throw new Error(msg);
    }
    return json.data;
  };

  /* ── Auto-load from URL ─────────────────────────────────── */

  const autoLoadFromUrl = async () => {
    try {
      const params = new URLSearchParams(window.location.search || "");
      const pid = parseInt(params.get("aisb_project") || "", 10);
      const sid = parseInt(params.get("aisb_sitemap") || "", 10);

      if (!Number.isFinite(pid) && !Number.isFinite(sid)) return;

      app.setLoading(true);
      app.setStatus("Loading selected sitemap…");

      let payload = null;
      if (Number.isFinite(sid) && sid > 0) {
        payload = await loadSitemapById(sid);
      } else if (Number.isFinite(pid) && pid > 0) {
        payload = await loadLatestForProject(pid);
      }

      if (!payload || !payload.data) return;

      state.projectId = payload.project_id
        ? parseInt(payload.project_id, 10)
        : Number.isFinite(pid)
          ? pid
          : null;
      state.sitemapId = payload.sitemap_id
        ? parseInt(payload.sitemap_id, 10)
        : Number.isFinite(sid)
          ? sid
          : null;
      state.version = payload.version ? parseInt(payload.version, 10) : 0;

      const isStructureOnly = payload.data.structure_only === true;
      state.structureOnly = isStructureOnly;

      renderAll(payload.data);

      if (isStructureOnly && btnApprove) {
        btnApprove.style.display = "inline-flex";
        app.setStatus(
          '<div class="aisb-ok">Structuur geladen. Klik op <strong>Ziet er goed uit? Genereer sectie-inhoud →</strong> om secties te genereren.</div>',
        );
      } else {
        app.setStatus('<div class="aisb-ok">Loaded.</div>');
      }
    } catch (e) {
      app.setStatus(
        '<div class="aisb-error">' +
          app.esc(e.message || "Failed to load sitemap") +
          "</div>",
      );
    } finally {
      app.setLoading(false);
    }
  };

  /* ── AJAX: generate sitemap ─────────────────────────────── */

  const doGenerate = async () => {
    const prompt = (promptEl.value || "").trim();
    const languages = languagesEl
      ? Array.from(languagesEl.selectedOptions)
          .map((o) => (o.value || "").trim())
          .filter(Boolean)
      : [];

    const pageCount = pageCountEl ? (pageCountEl.value || "").trim() : "";
    if (prompt.length < 10) {
      app.setStatus(
        '<div class="aisb-error">Please add a bit more detail (at least 10 characters).</div>',
      );
      return;
    }
    if (prompt.length > AISB.maxPromptChars) {
      app.setStatus('<div class="aisb-error">Prompt is too long.</div>');
      return;
    }

    app.setStatus(
      "Generating sitemap and canvas layout… Please be patient, this can take up to 5 minutes.",
    );
    app.setLoading(true);

    const form = new FormData();
    form.append("action", AISB.action);
    form.append("nonce", AISB.nonce);
    form.append("prompt", prompt);
    form.append("languages", JSON.stringify(languages));
    form.append("page_count", pageCount);
    form.append("project_id", state.projectId || "");

    try {
      const res = await fetch(AISB.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: form,
      });
      const text = await res.text();

      let json = null;
      try {
        json = JSON.parse(text);
      } catch (e) {
        app.setStatus(
          '<div class="aisb-error">Server did not return JSON. First 300 chars:</div>' +
            '<pre class="aisb-pre">' +
            app.esc(text.slice(0, 300)) +
            "</pre>",
        );
        app.setLoading(false);
        return;
      }

      if (!res.ok || !json || json.success !== true) {
        const msg =
          json && json.data && json.data.message
            ? json.data.message
            : "Unexpected error.";
        const raw =
          json && json.data && json.data.raw
            ? '<details><summary>Raw</summary><pre class="aisb-pre">' +
              app.esc(json.data.raw) +
              "</pre></details>"
            : "";
        app.setStatus(
          '<div class="aisb-error">' + app.esc(msg) + "</div>" + raw,
        );
        app.setLoading(false);
        return;
      }

      if (json.data && typeof json.data.project_id !== "undefined") {
        const pid = parseInt(json.data.project_id, 10);
        state.projectId = Number.isFinite(pid) && pid > 0 ? pid : null;
      }
      if (json.data && typeof json.data.sitemap_id !== "undefined") {
        const sid = parseInt(json.data.sitemap_id, 10);
        state.sitemapId = Number.isFinite(sid) && sid > 0 ? sid : null;
      }
      if (json.data && typeof json.data.version !== "undefined") {
        const v = parseInt(json.data.version, 10);
        state.version = Number.isFinite(v) && v > 0 ? v : 0;
      }
      app.updateWireframesLinks();

      const data = json.data.data;
      const isStructureOnly = json.data.structure_only === true;

      state.structureOnly = isStructureOnly;
      if (btnApprove)
        btnApprove.style.display = isStructureOnly ? "inline-flex" : "none";

      renderAll(data);

      if (isStructureOnly) {
        app.setStatus(
          '<div class="aisb-ok">Paginastructuur gegenereerd. Controleer de pagina\'s en klik op <strong>Ziet er goed uit? Genereer sectie-inhoud →</strong> om verder te gaan.</div>',
        );
      } else {
        app.setStatus('<div class="aisb-ok">Done.</div>');
      }
    } catch (e) {
      app.setStatus(
        '<div class="aisb-error">' +
          app.esc(e.message || "Request failed") +
          "</div>",
      );
    } finally {
      app.setLoading(false);
    }
  };

  /* ── AJAX: fill sections ────────────────────────────────── */

  const doFillSections = async () => {
    if (!state.data || !state.projectId) {
      app.setStatus(
        '<div class="aisb-error">Geen structuur geladen. Genereer eerst een sitemap.</div>',
      );
      return;
    }

    app.setStatus(
      "Sectie-inhoud genereren voor alle pagina's… Dit kan even duren.",
    );
    if (btnApprove) {
      btnApprove.disabled = true;
    }

    const form = new FormData();
    form.append("action", AISB.actionFillSections);
    form.append("nonce", AISB.nonce);
    form.append("project_id", state.projectId || "");
    form.append("sitemap_json", JSON.stringify(state.data));

    try {
      const res = await fetch(AISB.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        body: form,
      });
      const text = await res.text();

      let json = null;
      try {
        json = JSON.parse(text);
      } catch (e) {
        app.setStatus(
          '<div class="aisb-error">Server retourneerde geen JSON.</div>' +
            '<pre class="aisb-pre">' +
            app.esc(text.slice(0, 300)) +
            "</pre>",
        );
        if (btnApprove) {
          btnApprove.disabled = false;
        }
        return;
      }

      if (!res.ok || !json || json.success !== true) {
        const msg =
          json && json.data && json.data.message
            ? json.data.message
            : "Onverwachte fout.";
        app.setStatus('<div class="aisb-error">' + app.esc(msg) + "</div>");
        if (btnApprove) {
          btnApprove.disabled = false;
        }
        return;
      }

      if (json.data && typeof json.data.project_id !== "undefined") {
        const pid = parseInt(json.data.project_id, 10);
        state.projectId = Number.isFinite(pid) && pid > 0 ? pid : null;
      }
      if (json.data && typeof json.data.sitemap_id !== "undefined") {
        const sid = parseInt(json.data.sitemap_id, 10);
        state.sitemapId = Number.isFinite(sid) && sid > 0 ? sid : null;
      }
      if (json.data && typeof json.data.version !== "undefined") {
        const v = parseInt(json.data.version, 10);
        state.version = Number.isFinite(v) && v > 0 ? v : 0;
      }

      state.structureOnly = false;
      app.updateWireframesLinks();
      if (btnApprove) {
        btnApprove.style.display = "none";
        btnApprove.disabled = false;
      }

      renderAll(json.data.data);
      app.setStatus(
        '<div class="aisb-ok">Secties gegenereerd voor alle pagina\'s.</div>',
      );
    } catch (e) {
      app.setStatus(
        '<div class="aisb-error">' +
          app.esc(e.message || "Request mislukt") +
          "</div>",
      );
      if (btnApprove) {
        btnApprove.disabled = false;
      }
    }
  };

  /* ── Button event listeners ─────────────────────────────── */

  btnApprove?.addEventListener("click", doFillSections);

  promptEl.addEventListener("input", () => {
    const len = (promptEl.value || "").length;
    app.counterEl.textContent = len + " / " + AISB.maxPromptChars;
  });

  btnGen.addEventListener("click", doGenerate);

  btnCopy.addEventListener("click", async () => {
    const txt = rawEl.textContent || "";
    try {
      await navigator.clipboard.writeText(txt);
      app.setStatus('<div class="aisb-ok">Copied JSON to clipboard.</div>');
    } catch (e) {
      app.setStatus(
        '<div class="aisb-error">Could not copy. Select and copy manually.</div>',
      );
    }
  });

  btnReset.addEventListener("click", () => {
    outWrap.style.display = "none";
    rawEl.textContent = "";
    summaryEl.innerHTML = "";
    nodesEl.innerHTML = "";
    edgesSvg.innerHTML = "";
    Object.assign(state, {
      projectId: null,
      sitemapId: null,
      version: 0,
      savingVersion: false,
      structureOnly: false,
      baselineData: null,
      data: null,
      pages: [],
      bySlug: {},
      tree: [],
      activeSlug: null,
      edges: [],
      openInlineFormFor: null,
    });
    if (btnGoWireframes) btnGoWireframes.style.display = "none";
    if (step2TabEl) step2TabEl.removeAttribute("href");
    if (btnApprove) {
      btnApprove.style.display = "none";
      btnApprove.disabled = false;
    }
    detailTitleEl.textContent = "Select a page";
    detailSubEl.textContent = "We'll show sections + SEO for that page.";
    detailBodyEl.innerHTML = "";
    app.setStatus("");
  });

  /* ── Initialize ─────────────────────────────────────────── */

  autoLoadFromUrl();

  const ro = new ResizeObserver(() => requestAnimationFrame(app.drawEdges));
  ro.observe(canvasEl);

  /* ── Expose on namespace ────────────────────────────────── */

  Object.assign(app, {
    addChildPage,
    renderAll,
    doGenerate,
    doFillSections,
  });
})();
