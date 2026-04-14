/**
 * UI: rendering, detail panel, node cards, section editing, drag-drop.
 * Depends on: app-init.js, app-utils.js, app-canvas.js
 */
(function () {
  "use strict";

  const app = window.AISBApp;
  if (!app) return;

  const {
    nodesEl,
    edgesSvg,
    summaryEl,
    rawEl,
    detailTitleEl,
    detailSubEl,
    detailBodyEl,
    state,
    view,
  } = app;

  /* ── Summary ────────────────────────────────────────────── */

  const renderSummary = (data) => {
    const pages = Array.isArray(data.sitemap) ? data.sitemap : [];
    const name = data.website_name || "Website";
    const goal = data.website_goal || "";
    const audiences = Array.isArray(data.primary_audiences)
      ? data.primary_audiences
      : [];
    const notes = Array.isArray(data.notes) ? data.notes : [];

    summaryEl.innerHTML = [
      `<div class="aisb-pill"><strong>Current version</strong> <span>v${app.esc(state.version || 0)}</span></div>`,
      `<div class="aisb-pill"><strong>Next save</strong> <span>v${app.esc((state.version || 0) + 1)}</span></div>`,
      `<div class="aisb-pill"><strong>Name</strong> <span>${app.esc(name)}</span></div>`,
      goal
        ? `<div class="aisb-pill"><strong>Goal</strong> <span>${app.esc(goal)}</span></div>`
        : "",
      `<div class="aisb-pill"><strong>Pages</strong> <span>${pages.length}</span></div>`,
      audiences.length
        ? `<div class="aisb-pill"><strong>Audience</strong> <span>${app.esc(audiences.slice(0, 2).join(" · "))}${audiences.length > 2 ? "…" : ""}</span></div>`
        : "",
      notes.length
        ? `<div class="aisb-pill"><strong>Note</strong> <span>${app.esc(notes[0] || "")}</span></div>`
        : "",
    ]
      .filter(Boolean)
      .join("");
  };

  /* ── Active page ────────────────────────────────────────── */

  const clearMiniActive = () => {
    nodesEl
      .querySelectorAll(".aisb-section-mini.is-mini-active")
      .forEach((el) => el.classList.remove("is-mini-active"));
  };

  const setActive = (slug) => {
    state.activeSlug = slug;
    nodesEl.querySelectorAll(".aisb-node-card").forEach((el) => {
      el.classList.toggle("is-active", el.dataset.slug === slug);
    });
    renderDetail(slug);
  };

  const writeBackRaw = () => {
    if (!state.data) return;
    if (Array.isArray(state.data.sitemap)) {
      const idx = state.data.sitemap.findIndex(
        (p) => app.normalizeSlug(p.slug) === state.activeSlug,
      );
      if (idx >= 0)
        state.data.sitemap[idx] = { ...state.bySlug[state.activeSlug] };
    }
    rawEl.textContent = JSON.stringify(state.data, null, 2);
  };

  const persistActivePage = () => {
    const slug = state.activeSlug;
    if (!slug || !state.bySlug[slug]) return;

    const i = state.pages.findIndex((p) => app.normalizeSlug(p.slug) === slug);
    if (i >= 0) state.pages[i] = { ...state.bySlug[slug] };

    if (state.data && Array.isArray(state.data.sitemap)) {
      const j = state.data.sitemap.findIndex(
        (p) => app.normalizeSlug(p.slug) === slug,
      );
      if (j >= 0) state.data.sitemap[j] = { ...state.bySlug[slug] };
    }
    writeBackRaw();
  };

  const refreshCanvasCards = () => {
    renderCanvas({ skipLayout: true });
    requestAnimationFrame(app.drawEdges);
  };

  /* ── Detail panel ───────────────────────────────────────── */

  const renderDetail = (slug) => {
    const page = state.bySlug?.[slug];
    if (!page) {
      detailTitleEl.textContent = "Select a page";
      detailSubEl.textContent = "We'll show sections + SEO for that page.";
      detailBodyEl.innerHTML = "";
      return;
    }

    const sections = Array.isArray(page.sections) ? page.sections : [];
    const seo = page.seo || {};

    detailTitleEl.textContent = page.page_title || page.nav_label || page.slug;
    detailSubEl.textContent = "/" + (page.slug || "");

    const metaPills = [
      `<span class="aisb-meta-item">${app.esc(page.priority || "Support")}</span>`,
      `<span class="aisb-meta-item">${app.esc(page.page_type || "Other")}</span>`,
      page.parent_slug
        ? `<span class="aisb-meta-item">Parent: ${app.esc(page.parent_slug)}</span>`
        : `<span class="aisb-meta-item">Top-level</span>`,
      `<span class="aisb-meta-item">${sections.length} sections</span>`,
    ].join("");

    const optionsHtml = (selected) => {
      const safeSelected = (selected || "").toString();
      const opts = app.SECTION_TYPES.map((t) => {
        const sel = t === safeSelected ? " selected" : "";
        return `<option value="${app.esc(t)}"${sel}>${app.esc(t)}</option>`;
      }).join("");
      const needsFallback =
        safeSelected && !app.SECTION_TYPES.includes(safeSelected);
      const fallbackOpt = needsFallback
        ? `<option value="${app.esc(safeSelected)}" selected>${app.esc(safeSelected)} (legacy)</option>`
        : "";
      return fallbackOpt + opts;
    };

    const sectionsHtml = sections
      .map((sec, idx) => {
        const sName = sec.section_name || "Section";
        const purpose = sec.purpose || "";
        const stype = app.coerceType(sec.section_type || "", sName);
        const kc = Array.isArray(sec.key_content) ? sec.key_content : [];
        const chips = kc
          .slice(0, 12)
          .map((x) => `<span class="aisb-chip">${app.esc(x)}</span>`)
          .join("");

        return `
        <details class="aisb-sec-accordion" data-aisb-sec="${idx}" draggable="true">
            <summary class="aisb-sec-summary">
              <span class="aisb-sec-title">${app.esc(sName)}</span>
            </summary>
        
            <div class="aisb-sec-body">
              <div class="aisb-edit-grid">
                <div class="aisb-edit-row">
                  <label>Section title</label>
                  <input class="aisb-edit-input" type="text" value="${app.esc(sName)}" data-aisb-sec-field="section_name" />
                </div>
        
                <div class="aisb-edit-row">
                  <label>Section type</label>
                  <select class="aisb-edit-select" data-aisb-sec-field="section_type">
                    ${optionsHtml(stype)}
                  </select>
                </div>
        
                <div class="aisb-edit-row">
                  <label>Description</label>
                  <textarea class="aisb-edit-textarea" data-aisb-sec-field="purpose">${app.esc(purpose)}</textarea>
                </div>
              </div>
        
              ${kc.length ? `<div class="aisb-edit-help">Key content:</div><div class="aisb-kc">${chips}</div>` : ""}
            </div>
          </details>
      `;
      })
      .join("");

    const seoHtml = `
      <div class="aisb-seo">
        <div class="aisb-seo-title"><strong>SEO</strong></div>
        <div class="aisb-seo-grid">
          ${seo.primary_keyword ? `<div><strong>Primary:</strong> ${app.esc(seo.primary_keyword)}</div>` : ""}
          ${Array.isArray(seo.secondary_keywords) && seo.secondary_keywords.length ? `<div><strong>Secondary:</strong> ${app.esc(seo.secondary_keywords.slice(0, 8).join(", "))}${seo.secondary_keywords.length > 8 ? "…" : ""}</div>` : ""}
          ${seo.meta_title ? `<div><strong>Meta title:</strong> ${app.esc(seo.meta_title)}</div>` : ""}
          ${seo.meta_description ? `<div><strong>Meta description:</strong> ${app.esc(seo.meta_description)}</div>` : ""}
        </div>
      </div>
    `;

    const pagePurpose = page.page_purpose
      ? `<div style="margin:10px 0 8px 0; padding:10px 12px; background:#f9f9f9; border-radius:10px; font-size:12px; color:#444; line-height:1.5;">${app.esc(page.page_purpose)}</div>`
      : "";

    const structureHint =
      state.structureOnly && sections.length === 0
        ? `<div style="padding:12px; border:1px dashed rgba(0,0,0,.18); border-radius:10px; font-size:12px; color:#666; text-align:center; margin-top:8px;">Secties worden gegenereerd na goedkeuring van de structuur.</div>`
        : "";

    detailBodyEl.innerHTML = `
      <div class="aisb-page-meta">${metaPills}</div>
      ${pagePurpose}
      <div class="aisb-sections" data-aisb-sections-editor>${sectionsHtml}${structureHint}</div>
      ${seoHtml}
    `;
  };

  /* ── Detail panel editing ───────────────────────────────── */

  detailBodyEl.addEventListener("input", (e) => {
    const target = e.target;
    if (!target) return;

    const field = target.getAttribute("data-aisb-sec-field");
    if (!field) return;

    const secLi = target.closest("[data-aisb-sec]");
    if (!secLi) return;

    const idx = parseInt(secLi.getAttribute("data-aisb-sec"), 10);
    if (!Number.isFinite(idx)) return;

    const slug = state.activeSlug;
    const page = state.bySlug?.[slug];
    if (!page || !Array.isArray(page.sections) || !page.sections[idx]) return;

    const val = (target.value ?? "").toString();

    if (field === "section_name") {
      page.sections[idx].section_name = val;
      page.sections[idx].section_type = app.coerceType(
        page.sections[idx].section_type || "",
        val,
      );
    } else if (field === "purpose") {
      page.sections[idx].purpose = val;
    } else if (field === "section_type") {
      page.sections[idx].section_type = app.coerceType(
        val,
        page.sections[idx].section_name || "",
      );
    }

    persistActivePage();

    clearTimeout(app.editDebounce);
    app.editDebounce = setTimeout(() => {
      const h4 = secLi.querySelector("h4");
      if (h4 && field === "section_name")
        h4.textContent = page.sections[idx].section_name || "Section";
      refreshCanvasCards();
    }, 120);
  });

  /* ── Reorder helpers ────────────────────────────────────── */

  const reorderArray = (arr, fromIdx, toIdx) => {
    if (!Array.isArray(arr)) return arr;
    const from = Number(fromIdx),
      to = Number(toIdx);
    if (!Number.isFinite(from) || !Number.isFinite(to)) return arr;
    if (from === to) return arr;
    if (from < 0 || from >= arr.length) return arr;
    if (to < 0 || to >= arr.length) return arr;

    const copy = arr.slice();
    const [moved] = copy.splice(from, 1);
    copy.splice(to, 0, moved);
    return copy;
  };

  const insertSectionUnder = (slug, idx) => {
    const page = state.bySlug?.[slug];
    if (!page || !Array.isArray(page.sections)) return null;

    const insertAt = Math.min(page.sections.length, Math.max(0, idx + 1));

    const newSection = {
      section_name: "New section",
      section_type: "Content Sections",
      purpose: "",
      key_content: [],
    };

    page.sections.splice(insertAt, 0, newSection);
    return insertAt;
  };

  /* ── Section drag/drop (detail panel) ───────────────────── */

  const getSecLi = (t) =>
    t?.closest?.(".aisb-sec-accordion[data-aisb-sec]") || null;
  const getSecIdx = (li) => {
    if (!li) return null;
    const n = parseInt(li.getAttribute("data-aisb-sec"), 10);
    return Number.isFinite(n) ? n : null;
  };

  const clearDropClasses = () => {
    detailBodyEl
      .querySelectorAll(".aisb-section.is-drop-target")
      .forEach((el) => el.classList.remove("is-drop-target"));
    detailBodyEl
      .querySelectorAll(".aisb-section.is-dragging")
      .forEach((el) => el.classList.remove("is-dragging"));
  };

  detailBodyEl.addEventListener("dragover", (e) => {
    if (!app.secDrag.active) return;

    const li = getSecLi(e.target);
    if (!li) return;

    e.preventDefault();
    e.dataTransfer.dropEffect = "move";

    clearDropClasses();
    li.classList.add("is-drop-target");
  });

  detailBodyEl.addEventListener("dragleave", (e) => {
    if (!app.secDrag.active) return;
    const li = getSecLi(e.target);
    if (!li) return;
    li.classList.remove("is-drop-target");
  });

  detailBodyEl.addEventListener("drop", (e) => {
    if (!app.secDrag.active) return;

    e.preventDefault();
    const li = getSecLi(e.target);
    const toIdx = getSecIdx(li);

    const slug = state.activeSlug;
    const page = state.bySlug?.[slug];

    if (
      !page ||
      !Array.isArray(page.sections) ||
      toIdx === null ||
      app.secDrag.fromIdx === null
    ) {
      clearDropClasses();
      app.secDrag = { active: false, fromIdx: null };
      return;
    }

    const nextSections = reorderArray(
      page.sections,
      app.secDrag.fromIdx,
      toIdx,
    );
    page.sections = nextSections;

    persistActivePage();
    renderDetail(slug);
    refreshCanvasCards();
    setActive(slug);

    clearDropClasses();
    app.secDrag = { active: false, fromIdx: null };
  });

  detailBodyEl.addEventListener("dragend", () => {
    if (!app.secDrag.active) return;
    clearDropClasses();
    app.secDrag = { active: false, fromIdx: null };
  });

  /* ── Mini-section helpers ───────────────────────────────── */

  const clearMiniDropClasses = (withinCardEl = null) => {
    const scope = withinCardEl || nodesEl;
    scope
      .querySelectorAll(".aisb-section-mini.is-mini-drop-target")
      .forEach((el) => el.classList.remove("is-mini-drop-target"));
    scope
      .querySelectorAll(".aisb-section-mini.is-mini-dragging")
      .forEach((el) => el.classList.remove("is-mini-dragging"));
  };

  nodesEl.addEventListener("dragend", () => {
    app.miniJustDragged = true;
    setTimeout(() => (app.miniJustDragged = false), 0);
  });

  const openDetailSection = (
    idx,
    { closeOthers = true, scroll = true } = {},
  ) => {
    const container = detailBodyEl.querySelector("[data-aisb-sections-editor]");
    if (!container) return;

    const all = Array.from(
      container.querySelectorAll(".aisb-sec-accordion[data-aisb-sec]"),
    );
    const target = container.querySelector(
      `.aisb-sec-accordion[data-aisb-sec="${idx}"]`,
    );
    if (!target) return;

    if (closeOthers) {
      all.forEach((d) => {
        if (d !== target) d.removeAttribute("open");
      });
    }

    target.setAttribute("open", "open");

    if (scroll) {
      target.scrollIntoView({ behavior: "smooth", block: "nearest" });
    }
  };

  /* ── Mini-section canvas click: add-under ───────────────── */

  nodesEl.addEventListener("click", (e) => {
    const btn = e.target?.closest?.("[data-aisb-add-under]");
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const slug = (btn.getAttribute("data-aisb-mini-slug") || "").trim();
    const idx = parseInt(btn.getAttribute("data-aisb-mini-idx") || "", 10);
    if (!slug || !Number.isFinite(idx)) return;

    const newIdx = insertSectionUnder(slug, idx);
    if (newIdx === null) return;

    if (state.activeSlug !== slug) {
      setActive(slug);
    }

    persistActivePage();
    renderDetail(slug);
    renderCanvas({ skipLayout: true });
    requestAnimationFrame(app.drawEdges);

    requestAnimationFrame(() => {
      clearMiniActive();

      const newMini = nodesEl.querySelector(
        `.aisb-section-mini[data-aisb-mini-slug="${CSS.escape(slug)}"][data-aisb-mini-idx="${newIdx}"]`,
      );
      if (newMini) newMini.classList.add("is-mini-active");

      openDetailSection(newIdx, { closeOthers: true, scroll: true });
    });
  });

  /* ── Mini-section canvas click: select ──────────────────── */

  nodesEl.addEventListener("click", (e) => {
    if (typeof app.miniJustDragged !== "undefined" && app.miniJustDragged)
      return;
    if (e.target?.closest?.("[data-aisb-add-under]")) return;
    if (e.target?.closest?.(".aisb-mini-sec-handle")) return;

    const miniEl = e.target?.closest?.("[data-aisb-mini-sec]");
    if (!miniEl) return;

    const slug = (miniEl.getAttribute("data-aisb-mini-slug") || "").trim();
    const idx = parseInt(miniEl.getAttribute("data-aisb-mini-idx") || "", 10);
    if (!slug || !Number.isFinite(idx)) return;

    if (state.activeSlug !== slug) {
      setActive(slug);
    }

    clearMiniActive();
    miniEl.classList.add("is-mini-active");

    requestAnimationFrame(() => {
      openDetailSection(idx, { closeOthers: true, scroll: true });
    });
  });

  /* ── Mini-section drag: start ───────────────────────────── */

  const getMiniEl = (t) => t?.closest?.("[data-aisb-mini-sec]") || null;
  const getMiniIdx = (el) => {
    if (!el) return null;
    const n = parseInt(el.getAttribute("data-aisb-mini-idx"), 10);
    return Number.isFinite(n) ? n : null;
  };
  const getMiniSlug = (el) =>
    (el?.getAttribute?.("data-aisb-mini-slug") || "").trim() || null;

  nodesEl.addEventListener("dragstart", (e) => {
    const handle = e.target?.closest?.(".aisb-mini-sec-handle");
    if (!handle) {
      e.preventDefault();
      return;
    }

    const miniEl = e.target?.closest?.("[data-aisb-mini-sec]");
    if (!miniEl) {
      e.preventDefault();
      return;
    }

    const slug = getMiniSlug(miniEl);
    const fromIdx = getMiniIdx(miniEl);
    const page = slug ? state.bySlug?.[slug] : null;

    if (!slug || fromIdx === null || !page || !Array.isArray(page.sections)) {
      e.preventDefault();
      return;
    }

    app.miniDrag = { active: true, slug, fromIdx };
    miniEl.classList.add("is-mini-dragging");

    try {
      e.dataTransfer.setData("text/plain", `${slug}:${fromIdx}`);
    } catch (_) {}
    e.dataTransfer.effectAllowed = "move";
  });

  /* ── Node card HTML ─────────────────────────────────────── */

  const nodeHtml = (page) => {
    const sections = Array.isArray(page.sections) ? page.sections : [];
    const slug = "/" + app.esc(page.slug || "");
    const title = app.esc(page.page_title || page.nav_label || page.slug);

    const sectionsHtml = sections
      .map((s, idx) => {
        const t = s.section_type ? ` · ${app.esc(s.section_type)}` : "";
        const title = app.esc(s.section_name || "Section");
        const purpose = app.esc(s.purpose || "");
        return `
          <div class="aisb-section-mini"
               data-aisb-mini-sec
               data-aisb-mini-slug="${app.esc(page.slug)}"
               data-aisb-mini-idx="${idx}">
               
            <div class="aisb-mini-sec-head">
              <span class="aisb-mini-sec-handle" draggable="true" title="Drag to reorder" aria-label="Drag to reorder">⋮⋮</span>
              <div style="min-width:0">
                <h4 style="margin:0; font-size:12px;">${title}${t}</h4>
                <p style="margin:4px 0 0 0; font-size:11px; color:#555; line-height:1.35;">${purpose}</p>
              </div>
            </div>
        
            <button type="button"
                    class="aisb-mini-add-under"
                    title="Add section underneath"
                    aria-label="Add section underneath"
                    data-aisb-add-under
                    data-aisb-mini-slug="${app.esc(page.slug)}"
                    data-aisb-mini-idx="${idx}">+</button>
          </div>
        `;
      })
      .join("");

    // NOTE: These event listeners are registered each time nodeHtml is called
    // (existing behavior preserved from the original monolithic file).

    nodesEl.addEventListener("dragover", (e) => {
      if (!app.miniDrag.active) return;

      const miniEl = getMiniEl(e.target);
      if (!miniEl) return;

      const slug = getMiniSlug(miniEl);
      if (!slug || slug !== app.miniDrag.slug) return;

      e.preventDefault();
      e.dataTransfer.dropEffect = "move";

      const cardEl = miniEl.closest(".aisb-node-card");
      clearMiniDropClasses(cardEl);

      miniEl.classList.add("is-mini-drop-target");
    });

    nodesEl.addEventListener("dragleave", (e) => {
      if (!app.miniDrag.active) return;
      const miniEl = getMiniEl(e.target);
      if (!miniEl) return;
      miniEl.classList.remove("is-mini-drop-target");
    });

    nodesEl.addEventListener("drop", (e) => {
      if (!app.miniDrag.active) return;

      const miniEl = getMiniEl(e.target);
      if (!miniEl) return;

      const slug = getMiniSlug(miniEl);
      const toIdx = getMiniIdx(miniEl);

      if (
        !slug ||
        slug !== app.miniDrag.slug ||
        toIdx === null ||
        app.miniDrag.fromIdx === null
      ) {
        clearMiniDropClasses();
        app.miniDrag = { active: false, slug: null, fromIdx: null };
        return;
      }

      const page = state.bySlug?.[slug];
      if (!page || !Array.isArray(page.sections)) {
        clearMiniDropClasses();
        app.miniDrag = { active: false, slug: null, fromIdx: null };
        return;
      }

      e.preventDefault();

      const nextSections = reorderArray(
        page.sections,
        app.miniDrag.fromIdx,
        toIdx,
      );
      page.sections = nextSections;

      if (slug === state.activeSlug) {
        persistActivePage();
        renderDetail(slug);
      } else {
        const i = state.pages.findIndex(
          (p) => app.normalizeSlug(p.slug) === slug,
        );
        if (i >= 0) state.pages[i] = { ...page };
        if (state.data?.sitemap && Array.isArray(state.data.sitemap)) {
          const j = state.data.sitemap.findIndex(
            (p) => app.normalizeSlug(p.slug) === slug,
          );
          if (j >= 0) state.data.sitemap[j] = { ...page };
        }
        writeBackRaw();
      }

      renderCanvas({ skipLayout: true });
      requestAnimationFrame(app.drawEdges);

      if (state.activeSlug) setActive(state.activeSlug);

      clearMiniDropClasses();
      app.miniDrag = { active: false, slug: null, fromIdx: null };
    });

    app.btnSave?.addEventListener("click", async () => {
      if (!state.projectId || !state.data) {
        app.setStatus('<div class="aisb-error">No project loaded.</div>');
        return;
      }

      if (state.savingVersion) return;
      state.savingVersion = true;
      app.btnSave.disabled = true;

      try {
        const label = app.computeAutoLabel(state.baselineData, state.data);

        const form = new FormData();
        form.append("action", AISB.actionSaveVersion);
        form.append("nonce", AISB.nonce);
        form.append("project_id", state.projectId);
        form.append("label", label);
        form.append("status", "edited");
        form.append("sitemap_json", JSON.stringify(state.data));

        try {
          const res = await fetch(AISB.ajaxUrl, {
            method: "POST",
            credentials: "same-origin",
            body: form,
          });
          const json = await res.json();
          if (!res.ok || !json?.success) {
            app.setStatus(
              '<div class="aisb-error">' +
                app.esc(json?.data?.message || "Save failed") +
                "</div>",
            );
            return;
          }
          state.version = parseInt(json.data.version, 10) || state.version;
          if (json.data && json.data.sitemap_id)
            state.sitemapId =
              parseInt(json.data.sitemap_id, 10) || state.sitemapId;
          state.baselineData = app.deepClone(state.data);
          app.updateWireframesLinks();
          renderSummary(state.data);
          app.setStatus(
            '<div class="aisb-ok">Saved as version v' +
              app.esc(state.version) +
              ' · <a href="' +
              app.esc(app.btnGoWireframes ? app.btnGoWireframes.href : "#") +
              '" style="color:inherit;font-weight:700;">→ Go to Wireframes</a></div>',
          );
        } catch (e) {
          app.setStatus(
            '<div class="aisb-error">' +
              app.esc(e.message || "Save failed") +
              "</div>",
          );
        }
      } finally {
        state.savingVersion = false;
        app.btnSave.disabled = false;
      }
    });

    nodesEl.addEventListener("dragend", () => {
      if (!app.miniDrag.active) return;
      clearMiniDropClasses();
      app.miniDrag = { active: false, slug: null, fromIdx: null };
    });

    const isFormOpen = state.openInlineFormFor === page.slug;

    return `
      <div class="aisb-node-head aisb-node-handle">
        <div>
          <div class="aisb-node-title">${title}</div>
          <div class="aisb-node-slug">${slug}</div>
        </div>
      </div>
      <div class="aisb-node-body">
        ${sectionsHtml}
        <div class="aisb-node-actions">
          <button class="aisb-mini-btn" type="button" data-aisb-select>Open</button>
          <button class="aisb-mini-btn primary" type="button" data-aisb-add-child>Add child +</button>
        </div>

        ${
          isFormOpen
            ? `
          <div class="aisb-inline-form" data-aisb-inline-form>
            <label>New child page</label>
            <input type="text" placeholder="Page title" data-aisb-child-title />
            <div style="height:8px"></div>
            <textarea placeholder="Short description (what this page is for)" data-aisb-child-desc></textarea>
            <div class="aisb-inline-row">
              <button class="aisb-mini-btn primary" type="button" data-aisb-child-create>Create</button>
              <button class="aisb-mini-btn" type="button" data-aisb-child-cancel>Cancel</button>
              <div class="aisb-inline-note">Built with OpenAI</div>
            </div>
          </div>
        `
            : ""
        }
      </div>
    `;
  };

  /* ── Node card interactions ─────────────────────────────── */

  const attachNodeInteractions = (cardEl, slug) => {
    cardEl.addEventListener("mousedown", (e) => {
      e.stopPropagation();
    });

    cardEl
      .querySelector("[data-aisb-select]")
      ?.addEventListener("click", (e) => {
        e.stopPropagation();
        setActive(slug);
      });

    cardEl
      .querySelector("[data-aisb-add-child]")
      ?.addEventListener("click", (e) => {
        e.stopPropagation();
        state.openInlineFormFor =
          state.openInlineFormFor === slug ? null : slug;
        renderCanvas({ skipLayout: true });
        requestAnimationFrame(app.drawEdges);
      });

    const formEl = cardEl.querySelector("[data-aisb-inline-form]");
    if (formEl) {
      formEl
        .querySelector("[data-aisb-child-cancel]")
        ?.addEventListener("click", (e) => {
          e.stopPropagation();
          state.openInlineFormFor = null;
          renderCanvas({ skipLayout: true });
          requestAnimationFrame(app.drawEdges);
        });

      formEl
        .querySelector("[data-aisb-child-create]")
        ?.addEventListener("click", async (e) => {
          e.stopPropagation();
          const title = (
            formEl.querySelector("[data-aisb-child-title]")?.value || ""
          ).trim();
          const desc = (
            formEl.querySelector("[data-aisb-child-desc]")?.value || ""
          ).trim();
          if (title.length < 2) {
            app.setStatus(
              '<div class="aisb-error">Please enter a title.</div>',
            );
            return;
          }
          if (desc.length < 3) {
            app.setStatus(
              '<div class="aisb-error">Please enter a short description.</div>',
            );
            return;
          }
          app.setStatus("Creating child page…");
          await app.addChildPage(slug, title, desc);
        });
    }

    const handle = cardEl.querySelector(".aisb-node-handle");
    let dragging = false;
    let dragStart = { x: 0, y: 0, nx: 0, ny: 0 };

    const onDown = (e) => {
      if (e.button !== 0) return;
      if (!e.target.closest(".aisb-node-handle")) return;
      e.stopPropagation();

      dragging = true;
      cardEl.classList.add("is-dragging");
      setActive(slug);

      const pos = app.getNodePos(slug);
      dragStart = { x: e.clientX, y: e.clientY, nx: pos.x, ny: pos.y };

      window.addEventListener("mousemove", onMove);
      window.addEventListener("mouseup", onUp);
    };

    const onMove = (e) => {
      if (!dragging) return;
      const dx = (e.clientX - dragStart.x) / view.scale;
      const dy = (e.clientY - dragStart.y) / view.scale;
      app.setNodePos(slug, dragStart.nx + dx, dragStart.ny + dy, true);
      app.positionNodeEl(cardEl, slug);
      requestAnimationFrame(app.drawEdges);
    };

    const onUp = () => {
      if (!dragging) return;
      dragging = false;
      cardEl.classList.remove("is-dragging");
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("mouseup", onUp);
    };

    handle?.addEventListener("mousedown", onDown);
  };

  /* ── Render canvas ──────────────────────────────────────── */

  const renderCanvas = ({ skipLayout = false } = {}) => {
    nodesEl.innerHTML = "";

    Object.values(state.bySlug).forEach((p) => {
      const card = document.createElement("div");
      card.className = "aisb-node-card";
      card.dataset.slug = p.slug;
      card.innerHTML = nodeHtml(p);

      if (p._x == null) p._x = 80 + Math.random() * 20;
      if (p._y == null) p._y = 30 + Math.random() * 20;

      app.positionNodeEl(card, p.slug);

      if (state.activeSlug === p.slug) card.classList.add("is-active");

      attachNodeInteractions(card, p.slug);
      nodesEl.appendChild(card);
    });

    state.edges = app.getEdgeList();
    requestAnimationFrame(app.drawEdges);

    if (!skipLayout) app.layoutTreeTidy();
  };

  /* ── Expose on namespace ────────────────────────────────── */

  Object.assign(app, {
    renderSummary,
    clearMiniActive,
    setActive,
    writeBackRaw,
    persistActivePage,
    refreshCanvasCards,
    renderDetail,
    reorderArray,
    insertSectionUnder,
    openDetailSection,
    nodeHtml,
    attachNodeInteractions,
    renderCanvas,
    clearMiniDropClasses,
  });
})();
