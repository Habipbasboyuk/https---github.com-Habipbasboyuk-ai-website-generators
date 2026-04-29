/**
 * design/editor-panel.js — Zijpaneel voor het bewerken van geselecteerde elementen.
 * Ondersteunt: tekst, afbeeldingen, en algemene elementen.
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  /* ── Panel aanmaken ─────────────────────────────────────────── */

  D.initEditorPanel = function () {
    if (document.getElementById("aisb-editor-panel")) return;

    const panel = document.createElement("div");
    panel.id = "aisb-editor-panel";
    panel.className = "aisb-editor-panel";
    panel.innerHTML = `
      <div class="aisb-ep-header">
        <span class="aisb-ep-title">Element</span>
        <button class="aisb-ep-close" title="Sluiten">✕</button>
      </div>
      <div class="aisb-ep-body">
        <p class="aisb-ep-empty">Klik op een element in het canvas om het te bewerken.</p>
      </div>
    `;

    panel.querySelector(".aisb-ep-close").addEventListener("click", () => {
      D.closeEditorPanel();
    });

    document.body.appendChild(panel);
    D._editorPanel = panel;
  };

  D.closeEditorPanel = function () {
    const panel = D._editorPanel;
    if (!panel) return;
    panel.classList.remove("is-open");
    D._selectedEl = null;
    D._selectedDoc = null;
  };

  /* ── Element selecteren ─────────────────────────────────────── */

  D.selectElement = function (el, doc, iframe) {
    if (!D._editorPanel) D.initEditorPanel();

    // Geen outline op het geselecteerde element — hover-stijl blijft gewoon actief
    D._selectedEl = el;
    D._selectedDoc = doc;
    D._selectedIframe =
      iframe ||
      (doc && doc.defaultView && doc.defaultView.frameElement) ||
      null;

    D._renderEditorPanel(el);
    D._editorPanel.classList.add("is-open");
  };

  /* ── Sectie selecteren (hele iframe / Bricks template) ─────── */

  D.selectSection = function (iframe, doc) {
    if (!D._editorPanel) D.initEditorPanel();

    D._selectedEl = null;
    D._selectedDoc = doc || (iframe && iframe.contentDocument) || null;
    D._selectedIframe = iframe;

    D._renderSectionPanel(iframe);
    D._editorPanel.classList.add("is-open");
  };

  /* ── Sectie-paneel renderen ─────────────────────────────────── */

  D._renderSectionPanel = function (iframe) {
    const panel = D._editorPanel;
    const type = (iframe && iframe._sectionType) || "section";
    const isMirrored = !!(iframe && iframe._aisbMirrored);
    const currentId = iframe && iframe._sectionPostId;

    panel.querySelector(".aisb-ep-title").textContent = "Sectie · " + type;

    const body = panel.querySelector(".aisb-ep-body");
    body.innerHTML = `
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Layout spiegelen</label>
        <button type="button" class="aisb-ep-upload-btn" id="aisb-ep-mirror-btn">
          ${isMirrored ? "↔ Spiegeling uitzetten" : "↔ Spiegel sectie (links ⇄ rechts)"}
        </button>
      </div>
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Andere layout</label>
        <div class="aisb-ep-row" style="gap:6px;margin-bottom:10px;">
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:#9aa3b2;cursor:pointer;flex-shrink:0;">
            <input type="checkbox" id="aisb-ep-tpl-filter" ${type !== "section" ? "checked" : ""}>
            Alleen "${type}"
          </label>
        </div>
        <input type="text" class="aisb-ep-input" id="aisb-ep-tpl-search" placeholder="Zoek..." style="margin-bottom:10px;">
        <div class="aisb-ep-tpl-grid" id="aisb-ep-tpl-grid" style="display:flex;flex-direction:column;gap:12px;">
          <div style="text-align:center;padding:14px;color:#9aa3b2;font-size:12px;">Templates laden…</div>
        </div>
      </div>
    `;

    const mirrorBtn = body.querySelector("#aisb-ep-mirror-btn");
    if (mirrorBtn) {
      mirrorBtn.addEventListener("click", () => {
        D.toggleSectionMirror(iframe);
        D._trackPatch(iframe, "mirror", null, {
          mirrored: !!iframe._aisbMirrored,
        });
        D._renderSectionPanel(iframe);
      });
    }

    D._populateSectionTemplates(iframe, body, type, currentId);
  };

  /* ── Templates inline laden + renderen in het sectie-paneel ── */

  D._templatesCache = null; // cache zodat we niet bij elke open opnieuw laden

  D._populateSectionTemplates = function (
    iframe,
    body,
    currentType,
    currentId,
  ) {
    const grid = body.querySelector("#aisb-ep-tpl-grid");
    const search = body.querySelector("#aisb-ep-tpl-search");
    const filterChk = body.querySelector("#aisb-ep-tpl-filter");
    if (!grid) return;

    function escapeHtml(s) {
      return String(s == null ? "" : s).replace(
        /[&<>"']/g,
        (c) =>
          ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;",
          })[c],
      );
    }

    function render(items) {
      if (!items.length) {
        grid.innerHTML = `<div style="text-align:center;padding:14px;color:#9aa3b2;font-size:12px;">Geen templates gevonden.</div>`;
        return;
      }
      const previewW = 1200; // viewport breedte van de preview-iframe
      const previewH = 800; // weergeven we max ~800px van de sectie

      grid.innerHTML = items
        .map((t) => {
          const isCurrent = String(t.id) === String(currentId);
          const tag = (t.tags && t.tags[0]) || t.ttype || "";
          const previewSrc =
            (window.AISB_DESIGN && AISB_DESIGN.previewUrl
              ? AISB_DESIGN.previewUrl
              : "") + t.id;
          return `
          <button type="button" class="aisb-ep-tpl-card" data-id="${t.id}" data-src="${previewSrc}" title="${escapeHtml(t.title)}"
            style="padding:0;border:2px solid ${isCurrent ? "#118cf0" : "rgba(255,255,255,0.10)"};
            border-radius:10px;overflow:hidden;background:#fff;cursor:pointer;
            display:flex;flex-direction:column;text-align:left;width:100%;
            transition:border-color 0.15s,transform 0.15s;
            box-shadow:0 4px 14px rgba(0,0,0,0.25);">
            <div class="aisb-ep-tpl-preview" style="position:relative;width:100%;aspect-ratio:${previewW}/${previewH};overflow:hidden;background:#f4f5f7;pointer-events:none;">
              <!-- iframe wordt lazy geladen wanneer in beeld -->
            </div>
            <div style="padding:10px 12px;background:#0a0f17;border-top:1px solid rgba(255,255,255,0.08);">
              <div style="color:#e6e9ef;font-size:13px;font-weight:600;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escapeHtml(t.title)}</div>
              <div style="color:#9aa3b2;font-size:11px;margin-top:3px;display:flex;gap:6px;align-items:center;">
                ${tag ? `<span style="background:rgba(17,140,240,0.15);color:#7cc0ff;padding:2px 6px;border-radius:4px;font-size:10px;">${escapeHtml(tag)}</span>` : ""}
                ${isCurrent ? '<span style="color:#118cf0;font-weight:600;">● Huidig</span>' : ""}
              </div>
            </div>
          </button>
        `;
        })
        .join("");

      function attachIframe(card) {
        const wrap = card.querySelector(".aisb-ep-tpl-preview");
        if (!wrap || wrap.querySelector("iframe")) return;
        // Wacht een frame zodat layout zeker is voltooid
        requestAnimationFrame(() => {
          const wrapW =
            wrap.clientWidth || wrap.getBoundingClientRect().width || 260;
          const realScale = wrapW / previewW;
          const previewIframe = document.createElement("iframe");
          previewIframe.src = card.dataset.src;
          previewIframe.loading = "lazy";
          previewIframe.scrolling = "no";
          // !important om eventuele algemene iframe-regels te overschrijven
          previewIframe.setAttribute(
            "style",
            "border:0 !important;" +
              "width:" +
              previewW +
              "px !important;" +
              "height:" +
              previewH +
              "px !important;" +
              "max-width:none !important;max-height:none !important;" +
              "min-width:" +
              previewW +
              "px !important;" +
              "transform:scale(" +
              realScale +
              ");" +
              "transform-origin:0 0;" +
              "pointer-events:none;" +
              "display:block;",
          );
          wrap.appendChild(previewIframe);
        });
      }

      // Lazy-load iframe-previews bij scrollen in beeld
      const io = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const card = entry.target;
            io.unobserve(card);
            attachIframe(card);
          });
        },
        { root: grid, rootMargin: "200px" },
      );

      grid.querySelectorAll(".aisb-ep-tpl-card").forEach((card) => {
        io.observe(card);
        card.addEventListener("mouseenter", () => {
          card.style.borderColor = "#118cf0";
          card.style.transform = "translateY(-2px)";
        });
        card.addEventListener("mouseleave", () => {
          card.style.transform = "none";
          if (String(card.dataset.id) !== String(currentId))
            card.style.borderColor = "rgba(255,255,255,0.10)";
        });
        card.addEventListener("click", () => {
          const newId = card.dataset.id;
          if (!newId) return;
          D.swapSectionTemplate(iframe, newId);
        });
      });
    }

    function applyFilters() {
      const all = D._templatesCache || [];
      const q = (search.value || "").toLowerCase().trim();
      const onlyType =
        filterChk &&
        filterChk.checked &&
        currentType &&
        currentType !== "section";
      const items = all.filter((t) => {
        if (onlyType && !(t.tags || []).includes(currentType)) return false;
        if (q && !(t.title || "").toLowerCase().includes(q)) return false;
        return true;
      });
      render(items);
    }

    if (search) search.addEventListener("input", applyFilters);
    if (filterChk) filterChk.addEventListener("change", applyFilters);

    if (D._templatesCache) {
      applyFilters();
      return;
    }

    const fd = new FormData();
    fd.append("action", "aisb_design_list_templates");
    fd.append("nonce", (window.AISB_DESIGN && AISB_DESIGN.nonce) || "");
    fetch(
      (window.AISB_DESIGN && AISB_DESIGN.ajaxUrl) || "/wp-admin/admin-ajax.php",
      {
        method: "POST",
        credentials: "same-origin",
        body: fd,
      },
    )
      .then((r) => r.json())
      .then((j) => {
        if (j && j.success && j.data && Array.isArray(j.data.templates)) {
          D._templatesCache = j.data.templates;
          applyFilters();
        } else {
          grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:14px;color:#ff8a8a;font-size:12px;">Fout bij laden.</div>`;
        }
      })
      .catch(() => {
        grid.innerHTML = `<div style="grid-column:1/-1;text-align:center;padding:14px;color:#ff8a8a;font-size:12px;">Netwerkfout.</div>`;
      });
  };

  /* ── Sectie spiegelen (flex-direction omdraaien) ───────────── */

  D.toggleSectionMirror = function (iframe) {
    if (!iframe || !iframe.contentDocument) return;
    const doc = iframe.contentDocument;
    const STYLE_ID = "aisb-section-mirror";
    const existing = doc.getElementById(STYLE_ID);
    if (existing) {
      existing.remove();
      iframe._aisbMirrored = false;
      return;
    }
    const style = doc.createElement("style");
    style.id = STYLE_ID;
    style.textContent = `
      /* Spiegel alle row-flex containers in deze sectie */
      .brxe-section,
      .brxe-section > .brxe-container,
      .brxe-container,
      .brxe-block,
      .brxe-div {
        flex-direction: row-reverse !important;
      }
    `;
    doc.head.appendChild(style);
    iframe._aisbMirrored = true;
  };

  /* ── Iframe-src vervangen door nieuw template ──────────────── */

  D.swapSectionTemplate = async function (iframe, newPostId) {
    if (!iframe || !newPostId) return;
    const previewUrl = (window.AISB_DESIGN && AISB_DESIGN.previewUrl) || "";

    // Toon direct een laad-indicator in het iframe zodat de gebruiker weet dat
    // de AI-fill bezig is (kan ~15-30s duren).
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (doc && doc.body) {
        doc.body.innerHTML =
          '<div style="display:flex;align-items:center;justify-content:center;' +
          'min-height:200px;font-family:sans-serif;font-size:14px;color:#666;">' +
          "<span>⏳ AI text is being generated…</span></div>";
      }
    } catch (e) {
      /* cross-origin — skip */
    }

    // Sluit het paneel zodat de gebruiker de indicator ziet
    if (D.closeEditorPanel) D.closeEditorPanel();

    // Haal project/sitemap/page context op uit de sectie-data van het iframe
    const sectionData = iframe._sectionData || {};
    const pageSlug = iframe._pageSlug || "";
    const uuid = sectionData.uuid || "";

    // Als we project_id en sitemap_version_id hebben, gebruik dan de nieuwe
    // AI-fill endpoint; anders val terug op het ruwe Bricks template.
    if (uuid && pageSlug && D.projectId) {
      try {
        const sitemapVersionId =
          iframe._sitemapVersionId ||
          sectionData.sitemap_version_id ||
          (window.AISB_DESIGN && AISB_DESIGN.sitemapVersionId) ||
          0;

        const out = await D.post("aisb_design_replace_section", {
          project_id: D.projectId,
          sitemap_version_id: sitemapVersionId,
          page_slug: pageSlug,
          uuid: uuid,
          bricks_template_id: newPostId,
        });

        if (out && out.success && out.data.ai_wireframe_id) {
          const aiId = out.data.ai_wireframe_id;
          iframe._sectionPostId = aiId;
          iframe._aisbMirrored = false;
          iframe._loaded = false;
          iframe.src = previewUrl + aiId;

          // sectie-data bijwerken voor hergebruik (bv. volgende swap)
          if (iframe._sectionData) {
            iframe._sectionData.ai_wireframe_id = aiId;
            iframe._sectionData.bricks_template_id =
              out.data.bricks_template_id;
          }
          return;
        } else {
          console.warn("[AISB design] design_replace_section failed:", out);
        }
      } catch (err) {
        console.error("[AISB design] design_replace_section error:", err);
      }
    }

    // Fallback: laad het ruwe Bricks-template als de AI endpoint niet lukte.
    iframe._sectionPostId = newPostId;
    iframe._aisbMirrored = false;
    iframe._loaded = false;
    iframe.src = previewUrl + newPostId;
  };

  /* ── Panel invullen ─────────────────────────────────────────── */

  D._renderEditorPanel = function (el) {
    const panel = D._editorPanel;
    const computed = el.ownerDocument.defaultView.getComputedStyle(el);
    const tag = el.tagName.toLowerCase();

    const isImg = tag === "img";
    const isTextTag = [
      "p",
      "h1",
      "h2",
      "h3",
      "h4",
      "h5",
      "h6",
      "span",
      "a",
      "li",
      "td",
      "th",
      "label",
      "button",
      "strong",
      "em",
      "blockquote",
      "figcaption",
    ].includes(tag);
    // Leaf-node (geen element-children) ook als tekstelement behandelen
    const isTextEl = isTextTag || (!isImg && el.childElementCount === 0);

    panel.querySelector(".aisb-ep-title").textContent =
      "<" +
      tag +
      ">" +
      (el.className ? " ." + String(el.className).trim().split(/\s+/)[0] : "");

    const body = panel.querySelector(".aisb-ep-body");

    /* ── Afbeelding ── */
    if (isImg) {
      body.innerHTML = `
        <div class="aisb-ep-group aisb-ep-img-source-group">
          <label class="aisb-ep-label">Afbeelding wijzigen</label>
          <div class="aisb-ep-align-btns" id="aisb-ep-img-tabs" style="margin-bottom:12px;">
            <button class="aisb-ep-align-btn is-active" data-tab="upload">Upload</button>
            <button class="aisb-ep-align-btn" data-tab="unsplash">Unsplash</button>
          </div>
          
          <!-- Upload Tab -->
          <div id="aisb-ep-tab-upload">
            <label class="aisb-ep-upload-btn" id="aisb-ep-upload-label">
              Kies bestand
              <input type="file" id="aisb-ep-upload-input" accept="image/*" style="display:none;">
            </label>
            <span class="aisb-ep-upload-status" id="aisb-ep-upload-status"></span>
          </div>

          <!-- Unsplash Tab -->
          <div id="aisb-ep-tab-unsplash" style="display:none;">
            <div class="aisb-ep-row" style="margin-bottom:8px;">
              <input type="text" class="aisb-ep-input" id="aisb-ep-unsplash-search" placeholder="Zoek op Unsplash...">
              <button class="aisb-ep-upload-btn" id="aisb-ep-unsplash-go" style="width:auto; padding:12px;">Search</button>
            </div>
            <div class="aisb-ep-unsplash-grid" id="aisb-ep-unsplash-results" style="display:grid; grid-template-columns:1fr 1fr; gap:8px; max-height:260px; overflow-y:auto; padding-right:4px;">
               <!-- Results here -->
            </div>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Breedte</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-imgw"
              min="10" max="1200" step="1"
              value="${parseInt(computed.width) || 300}">
            <input type="number" class="aisb-ep-number" id="aisb-ep-imgw-num"
              value="${parseInt(computed.width) || 300}">
            <span>px</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Border radius</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-radius"
              min="0" max="50" step="1"
              value="${parseInt(computed.borderRadius) || 0}">
            <span id="aisb-ep-radius-val">${parseInt(computed.borderRadius) || 0}px</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Doorzichtigheid</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-opacity"
              min="0" max="1" step="0.05"
              value="${parseFloat(computed.opacity) ?? 1}">
            <span id="aisb-ep-opacity-val">${Math.round((parseFloat(computed.opacity) ?? 1) * 100)}%</span>
          </div>
        </div>
      `;
      D._bindImageControls(el);
      return;
    }

    /* ── Tekstelement ── */
    if (isTextEl) {
      const rawText = (el.innerText || el.textContent || "").trim();
      const preview = rawText.slice(0, 18) || "Voorbeeld";
      body.innerHTML = `
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Tekst</label>
          <textarea class="aisb-ep-textarea" id="aisb-ep-text" rows="3">${rawText}</textarea>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Tekstkleur</label>
          <div class="aisb-ep-row">
            <input type="color" class="aisb-ep-color" id="aisb-ep-color"
              value="${D._rgbToHex(computed.color) || "#000000"}">
            <span class="aisb-ep-color-val">${D._rgbToHex(computed.color) || computed.color}</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Lettertype</label>
          <div class="aisb-ep-font-grid" id="aisb-ep-font">
            ${[
              "Arial",
              "Georgia",
              "Verdana",
              "Trebuchet MS",
              "Times New Roman",
              "Courier New",
              "Inter",
              "Roboto",
              "Open Sans",
              "Lato",
              "Montserrat",
              "Poppins",
              "Raleway",
              "Nunito",
            ]
              .map(
                (f) =>
                  `<button class="aisb-ep-font-btn${computed.fontFamily.includes(f) ? " is-active" : ""}" data-font="${f}" style="font-family:${f}" title="${f}"><span class="aisb-ep-font-aa">Aa</span><span class="aisb-ep-font-name">${f}</span></button>`,
              )
              .join("")}
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Lettergrootte</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-size"
              min="8" max="120" step="1"
              value="${parseInt(computed.fontSize) || 16}">
            <input type="number" class="aisb-ep-number" id="aisb-ep-size-num"
              min="8" max="120" value="${parseInt(computed.fontSize) || 16}">
            <span>px</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Gewicht</label>
          <div class="aisb-ep-weight-grid" id="aisb-ep-weight">
            ${[
              ["100", "Thin"],
              ["300", "Light"],
              ["400", "Regular"],
              ["500", "Medium"],
              ["600", "Semi"],
              ["700", "Bold"],
              ["800", "X-Bold"],
              ["900", "Black"],
            ]
              .map(
                ([v, l]) =>
                  `<button class="aisb-ep-weight-btn${computed.fontWeight === v ? " is-active" : ""}" data-weight="${v}" style="font-weight:${v}" title="${l} (${v})"><span class="aisb-ep-weight-num" style="font-weight:${v}">${v}</span><span class="aisb-ep-weight-name">${l}</span></button>`,
              )
              .join("")}
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Uitlijning</label>
          <div class="aisb-ep-align-btns" id="aisb-ep-align">
            ${[
              ["left", "←"],
              ["center", "↔"],
              ["right", "→"],
              ["justify", "☰"],
            ]
              .map(
                ([v, icon]) =>
                  `<button class="aisb-ep-align-btn${computed.textAlign === v ? " is-active" : ""}" data-align="${v}">${icon} ${v}</button>`,
              )
              .join("")}
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Regelafstand</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-lh"
              min="0.8" max="3" step="0.05"
              value="${(parseFloat(computed.lineHeight) / parseFloat(computed.fontSize)).toFixed(2) || 1.5}">
            <span id="aisb-ep-lh-val">${(parseFloat(computed.lineHeight) / parseFloat(computed.fontSize)).toFixed(2) || "1.50"}</span>
          </div>
        </div>
      `;
      D._bindTextControls(el);
      return;
    }

    /* ── Algemeen element ── */
    body.innerHTML = `
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Achtergrondkleur</label>
        <div class="aisb-ep-row">
          <input type="color" class="aisb-ep-color" id="aisb-ep-bg"
            value="${D._rgbToHex(computed.backgroundColor) || "#ffffff"}">
          <span class="aisb-ep-color-val">${D._rgbToHex(computed.backgroundColor) || computed.backgroundColor}</span>
        </div>
      </div>
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Border radius</label>
        <div class="aisb-ep-row">
          <input type="range" class="aisb-ep-range" id="aisb-ep-radius"
            min="0" max="50" step="1"
            value="${parseInt(computed.borderRadius) || 0}">
          <span id="aisb-ep-radius-val">${parseInt(computed.borderRadius) || 0}px</span>
        </div>
      </div>
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Doorzichtigheid</label>
        <div class="aisb-ep-row">
          <input type="range" class="aisb-ep-range" id="aisb-ep-opacity"
            min="0" max="1" step="0.05"
            value="${parseFloat(computed.opacity) ?? 1}">
          <span id="aisb-ep-opacity-val">${Math.round((parseFloat(computed.opacity) ?? 1) * 100)}%</span>
        </div>
      </div>
    `;
    D._bindGeneralControls(el);
  };

  /* ── Extra Modal voor Unsplash ──────────────────────────────── */

  D.openUnsplashModal = function (keyword, el) {
    let modal = document.getElementById("aisb-ep-unsplash-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "aisb-ep-unsplash-modal";
      modal.style.cssText =
        "position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(17,19,26,0.85); z-index:100000; display:flex; align-items:center; justify-content:center; backdrop-filter:blur(8px); padding:20px; box-sizing:border-box;";
      modal.innerHTML = `
        <div style="background:var(--ep-bg); width:100%; max-width:900px; height:80vh; border-radius:var(--ep-radius); border:1px solid var(--ep-border); display:flex; flex-direction:column; overflow:hidden; box-shadow:var(--ep-shadow);">
          <div class="aisb-ep-header">
            <span class="aisb-ep-title">Uitgebreide Unsplash Bibliotheek</span>
            <button class="aisb-ep-close" id="aisb-ep-unsplash-modal-close">✕</button>
          </div>
          <div style="padding:16px; border-bottom:1px solid var(--ep-border-strong); display:flex; gap:10px;">
            <input type="text" class="aisb-ep-input" id="aisb-ep-unsplash-modal-search" placeholder="Zoek op Unsplash...">
            <button class="aisb-ep-upload-btn" id="aisb-ep-unsplash-modal-go" style="width:auto; padding:0 20px;">Zoek (max 30)</button>
          </div>
          <div id="aisb-ep-unsplash-modal-results" style="flex:1; padding:16px; overflow-y:auto; display:grid; grid-template-columns:repeat(auto-fill, minmax(180px, 1fr)); gap:12px; align-items:start;">
          </div>
        </div>
      `;
      document.body.appendChild(modal);

      modal
        .querySelector("#aisb-ep-unsplash-modal-close")
        .addEventListener("click", () => {
          modal.style.display = "none";
        });

      const goBtn = modal.querySelector("#aisb-ep-unsplash-modal-go");
      const searchInp = modal.querySelector("#aisb-ep-unsplash-modal-search");
      const resDiv = modal.querySelector("#aisb-ep-unsplash-modal-results");

      const performModalSearch = () => {
        const q = searchInp.value.trim();
        if (!q) return;

        resDiv.innerHTML =
          '<div style="grid-column:1/-1; text-align:center; padding:20px; color:var(--ep-text-subtle);">Afbeeldingen laden...</div>';

        const fd = new FormData();
        fd.append("action", "aisb_search_similar_images");
        fd.append("nonce", AISB_DESIGN.nonce);
        fd.append("keyword", q);
        fd.append("page", 1);
        fd.append("per_page", 30); // 30 is max via style guide api

        fetch(AISB_DESIGN.ajaxUrl, { method: "POST", body: fd })
          .then((r) => r.json())
          .then((out) => {
            if (!out || !out.success || !out.data || !out.data.images) {
              resDiv.innerHTML =
                '<div style="grid-column:1/-1; text-align:center; padding:20px; color:#ef4444;">Fout bij zoeken.</div>';
              return;
            }
            if (!out.data.images.length) {
              resDiv.innerHTML =
                '<div style="grid-column:1/-1; text-align:center; padding:20px; color:var(--ep-text-subtle);">Geen resultaten.</div>';
              return;
            }

            resDiv.innerHTML = out.data.images
              .map((img) => {
                if (!img.thumb) return "";
                return `<div style="position:relative; cursor:pointer; overflow:hidden; border-radius:6px; box-shadow:0 2px 8px rgba(0,0,0,0.1); border:1px solid var(--ep-border);" title="${img.alt ? img.alt.replace(/"/g, "&quot;") : "Unsplash"}" class="aisb-ep-unsplash-modal-item">
                <img src="${img.thumb}" data-full="${img.full}" style="width:100%; height:140px; object-fit:cover; display:block; transition:transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='none'" />
              </div>`;
              })
              .join("");
          })
          .catch(() => {
            resDiv.innerHTML =
              '<div style="grid-column:1/-1; text-align:center; padding:20px; color:#ef4444;">Fout bij communicatie met server.</div>';
          });
      };

      goBtn.addEventListener("click", performModalSearch);
      searchInp.addEventListener("keydown", (e) => {
        if (e.key === "Enter") performModalSearch();
      });

      resDiv.addEventListener("click", (e) => {
        const img = e.target.closest("img");
        if (img && D._selectedEl) {
          const fullUrl = img.dataset.full || img.src;
          D._selectedEl.src = fullUrl;
          D._selectedEl.srcset = "";
          D._selectedEl.style.objectFit = "cover";
          D._trackPatch(D._selectedIframe, "img", D._selectedEl, {
            src: fullUrl,
          });
          // Visuele feedback in het image element (als er toevallig nog iets open staat)
          const smGrid = document.getElementById("aisb-ep-unsplash-results");
          if (smGrid)
            smGrid
              .querySelectorAll("img")
              .forEach((i) => (i.style.outline = "none"));
          modal.style.display = "none";
        }
      });
    }

    modal.style.display = "flex";
    const sInput = modal.querySelector("#aisb-ep-unsplash-modal-search");
    sInput.value = keyword || "";
    if (keyword) {
      modal.querySelector("#aisb-ep-unsplash-modal-go").click();
    }
  };

  /* ── Tekst controls ─────────────────────────────────────────── */

  D._bindTextControls = function (el) {
    const panel = D._editorPanel;

    // Tekst inhoud — innerText preserveert line breaks en overschrijft zonder child-elementen te breken
    const textarea = document.getElementById("aisb-ep-text");
    textarea.addEventListener("input", () => {
      el.innerText = textarea.value;
      D._trackPatch(D._selectedIframe, "text", el, { text: textarea.value });
    });

    // Kleur
    const colorInput = document.getElementById("aisb-ep-color");
    const colorVal = panel.querySelector(".aisb-ep-color-val");
    colorInput.addEventListener("input", () => {
      el.style.setProperty("color", colorInput.value, "important");
      colorVal.textContent = colorInput.value;
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "color",
        value: colorInput.value,
      });
    });

    // Lettertype
    document.getElementById("aisb-ep-font").addEventListener("click", (e) => {
      const btn = e.target.closest(".aisb-ep-font-btn");
      if (!btn) return;
      panel
        .querySelectorAll(".aisb-ep-font-btn")
        .forEach((b) => b.classList.remove("is-active"));
      btn.classList.add("is-active");
      el.style.setProperty("font-family", btn.dataset.font, "important");
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "font-family",
        value: btn.dataset.font,
      });
    });

    // Grootte
    const sizeRange = document.getElementById("aisb-ep-size");
    const sizeNum = document.getElementById("aisb-ep-size-num");
    sizeRange.addEventListener("input", () => {
      sizeNum.value = sizeRange.value;
      el.style.setProperty("font-size", sizeRange.value + "px", "important");
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "font-size",
        value: sizeRange.value + "px",
      });
    });
    sizeNum.addEventListener("input", () => {
      sizeRange.value = sizeNum.value;
      el.style.setProperty("font-size", sizeNum.value + "px", "important");
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "font-size",
        value: sizeNum.value + "px",
      });
    });

    // Gewicht
    document.getElementById("aisb-ep-weight").addEventListener("click", (e) => {
      const btn = e.target.closest(".aisb-ep-weight-btn");
      if (!btn) return;
      panel
        .querySelectorAll(".aisb-ep-weight-btn")
        .forEach((b) => b.classList.remove("is-active"));
      btn.classList.add("is-active");
      el.style.setProperty("font-weight", btn.dataset.weight, "important");
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "font-weight",
        value: btn.dataset.weight,
      });
    });

    // Uitlijning
    document.getElementById("aisb-ep-align").addEventListener("click", (e) => {
      const btn = e.target.closest(".aisb-ep-align-btn");
      if (!btn) return;
      panel
        .querySelectorAll(".aisb-ep-align-btn")
        .forEach((b) => b.classList.remove("is-active"));
      btn.classList.add("is-active");
      el.style.setProperty("text-align", btn.dataset.align, "important");
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "text-align",
        value: btn.dataset.align,
      });
    });

    // Regelafstand
    const lhRange = document.getElementById("aisb-ep-lh");
    const lhVal = document.getElementById("aisb-ep-lh-val");
    lhRange.addEventListener("input", () => {
      lhVal.textContent = parseFloat(lhRange.value).toFixed(2);
      el.style.setProperty("line-height", lhRange.value, "important");
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "line-height",
        value: lhRange.value,
      });
    });
  };

  /* ── Afbeelding controls ────────────────────────────────────── */

  D._bindImageControls = function (el) {
    // Tabs logic
    const tabsBtnContainer = document.getElementById("aisb-ep-img-tabs");
    const tabUpload = document.getElementById("aisb-ep-tab-upload");
    const tabUnsplash = document.getElementById("aisb-ep-tab-unsplash");

    if (tabsBtnContainer) {
      tabsBtnContainer.addEventListener("click", (e) => {
        const btn = e.target.closest(".aisb-ep-align-btn");
        if (!btn) return;
        tabsBtnContainer
          .querySelectorAll(".aisb-ep-align-btn")
          .forEach((b) => b.classList.remove("is-active"));
        btn.classList.add("is-active");

        if (btn.dataset.tab === "upload") {
          tabUpload.style.display = "";
          tabUnsplash.style.display = "none";
        } else {
          tabUpload.style.display = "none";
          tabUnsplash.style.display = "";
          // Ooit auto-focus in Unsplash search
          setTimeout(
            () => document.getElementById("aisb-ep-unsplash-search")?.focus(),
            50,
          );
        }
      });
    }

    // Unsplash logic
    const unGo = document.getElementById("aisb-ep-unsplash-go");
    const unSearch = document.getElementById("aisb-ep-unsplash-search");
    const unResults = document.getElementById("aisb-ep-unsplash-results");

    if (unGo && unSearch && unResults) {
      const doSearch = () => {
        const q = unSearch.value.trim();
        if (!q) return;

        unResults.innerHTML =
          '<div style="grid-column:1/-1; text-align:center; padding:10px; color:var(--ep-text-subtle);">Zoeken...</div>';

        const fd = new FormData();
        fd.append("action", "aisb_search_similar_images");
        // We gebruiken wp_create_nonce('aisb_sg_nonce') als AISB_DESIGN.nonce
        fd.append("nonce", AISB_DESIGN.nonce);
        fd.append("keyword", q);
        fd.append("page", 1);
        fd.append("per_page", 20);
        fetch(AISB_DESIGN.ajaxUrl, { method: "POST", body: fd })
          .then((r) => r.json())
          .then((out) => {
            if (!out || !out.success || !out.data || !out.data.images) {
              unResults.innerHTML =
                '<div style="grid-column:1/-1; text-align:center; padding:10px; color:#ef4444;">Fout bij zoeken.</div>';
              return;
            }

            const items = out.data.images;
            if (!items.length) {
              unResults.innerHTML =
                '<div style="grid-column:1/-1; text-align:center; padding:10px; color:var(--ep-text-subtle);">Geen resultaten.</div>';
              return;
            }

            unResults.innerHTML =
              items
                .map((img) => {
                  if (!img.thumb) return "";
                  return `<div style="position:relative; cursor:pointer;" title="${img.alt ? img.alt.replace(/"/g, "&quot;") : "Unsplash image"}" class="aisb-ep-unsplash-item">
                  <img src="${img.thumb}" data-full="${img.full}" style="width:100%; height:80px; object-fit:cover; border-radius:4px; display:block;" />
                </div>`;
                })
                .join("") +
              `<button class="aisb-ep-upload-btn" id="aisb-ep-unsplash-more" style="grid-column:1/-1; margin-top:8px;">Meer laden...</button>`;

            const moreBtn = document.getElementById("aisb-ep-unsplash-more");
            if (moreBtn) {
              moreBtn.addEventListener("click", () => {
                D.openUnsplashModal(q, el);
              });
            }
          })
          .catch(() => {
            unResults.innerHTML =
              '<div style="grid-column:1/-1; text-align:center; padding:10px; color:#ef4444;">Fout bij communicatie met server.</div>';
          });
      };

      unGo.addEventListener("click", doSearch);
      unSearch.addEventListener("keydown", (e) => {
        if (e.key === "Enter") doSearch();
      });

      unResults.addEventListener("click", (e) => {
        const img = e.target.closest("img");
        if (img) {
          const fullUrl = img.dataset.full || img.src;
          el.src = fullUrl;
          el.srcset = "";
          el.style.objectFit = "cover";
          D._trackPatch(D._selectedIframe, "img", el, { src: fullUrl });
          // Update ook direct border als feedback (visueel)
          unResults
            .querySelectorAll("img")
            .forEach((i) => (i.style.outline = "none"));
          img.style.outline = "2px solid var(--ep-accent)";
        }
      });
    }

    // Upload afbeelding
    const uploadInput = document.getElementById("aisb-ep-upload-input");
    const uploadStatus = document.getElementById("aisb-ep-upload-status");
    const srcInput = document.getElementById("aisb-ep-src");
    if (uploadInput) {
      uploadInput.addEventListener("change", () => {
        const file = uploadInput.files && uploadInput.files[0];
        if (!file) return;
        uploadStatus.textContent = "Uploading…";
        const fd = new FormData();
        fd.append("action", "aisb_upload_images");
        fd.append("nonce", AISB_DESIGN.nonce);
        fd.append("images[]", file);
        fetch(AISB_DESIGN.ajaxUrl, { method: "POST", body: fd })
          .then((r) => r.json())
          .then((out) => {
            if (
              !out ||
              !out.success ||
              !out.data.images ||
              !out.data.images[0]
            ) {
              uploadStatus.textContent = "Upload mislukt.";
              return;
            }
            const url =
              out.data.images[0].full || out.data.images[0].thumb || "";
            el.src = url;
            el.srcset = "";
            el.style.objectFit = "cover";
            D._trackPatch(D._selectedIframe, "img", el, { src: url });
            if (srcInput) srcInput.value = url;
            uploadStatus.textContent = "Afbeelding geüpload ✓";
            uploadInput.value = "";
          })
          .catch(() => {
            uploadStatus.textContent = "Upload mislukt.";
          });
      });
    }

    // Src
    if (srcInput) {
      srcInput.addEventListener("change", (e) => {
        el.setAttribute("src", e.target.value);
      });
    }

    // Alt
    const altInput = document.getElementById("aisb-ep-alt");
    if (altInput) {
      altInput.addEventListener("input", (e) => {
        el.setAttribute("alt", e.target.value);
      });
    }

    // Breedte
    const imgwRange = document.getElementById("aisb-ep-imgw");
    const imgwNum = document.getElementById("aisb-ep-imgw-num");
    imgwRange.addEventListener("input", () => {
      imgwNum.value = imgwRange.value;
      el.style.setProperty("width", imgwRange.value + "px", "important");
      el.style.setProperty("height", "auto", "important");
    });
    imgwNum.addEventListener("input", () => {
      imgwRange.value = imgwNum.value;
      el.style.setProperty("width", imgwNum.value + "px", "important");
      el.style.setProperty("height", "auto", "important");
    });

    D._bindSharedControls(el);
  };

  /* ── Algemeen controls ──────────────────────────────────────── */

  D._bindGeneralControls = function (el) {
    const bgInput = document.getElementById("aisb-ep-bg");
    const bgVal = D._editorPanel.querySelector(".aisb-ep-color-val");
    bgInput.addEventListener("input", () => {
      el.style.setProperty("background-color", bgInput.value, "important");
      bgVal.textContent = bgInput.value;
      D._trackPatch(D._selectedIframe, "css", el, {
        prop: "background-color",
        value: bgInput.value,
      });
    });

    D._bindSharedControls(el);
  };

  /* ── Gedeelde controls (radius + opacity) ───────────────────── */

  D._bindSharedControls = function (el) {
    const radiusRange = document.getElementById("aisb-ep-radius");
    const radiusVal = document.getElementById("aisb-ep-radius-val");
    if (radiusRange) {
      radiusRange.addEventListener("input", () => {
        radiusVal.textContent = radiusRange.value + "px";
        el.style.setProperty(
          "border-radius",
          radiusRange.value + "px",
          "important",
        );
        D._trackPatch(D._selectedIframe, "css", el, {
          prop: "border-radius",
          value: radiusRange.value + "px",
        });
      });
    }

    const opacityRange = document.getElementById("aisb-ep-opacity");
    const opacityVal = document.getElementById("aisb-ep-opacity-val");
    if (opacityRange) {
      opacityRange.addEventListener("input", () => {
        opacityVal.textContent =
          Math.round(parseFloat(opacityRange.value) * 100) + "%";
        el.style.setProperty("opacity", opacityRange.value, "important");
        D._trackPatch(D._selectedIframe, "css", el, {
          prop: "opacity",
          value: opacityRange.value,
        });
      });
    }
  };

  /* ── Hulpfunctie: rgb(r,g,b) → #rrggbb ─────────────────────── */

  D._rgbToHex = function (rgb) {
    const m = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
    if (!m) return null;
    return (
      "#" +
      [m[1], m[2], m[3]]
        .map((n) => parseInt(n).toString(16).padStart(2, "0"))
        .join("")
    );
  };

  document.addEventListener("DOMContentLoaded", () => D.initEditorPanel());

  /* ═══════════════════════════════════════════════════════════════
   * Sectie toevoegen — Relume-stijl modal
   * Geopend door de "+Sectie" hover-knop op elk iframe-wrap.
   * ═══════════════════════════════════════════════════════════════ */

  /**
   * Opent de template-kiezer modal voor het toevoegen van een nieuwe sectie
   * na `afterIframe` op de `page`.
   */
  D.openAddSectionModal = function (afterIframe, pageBody, page) {
    let modal = document.getElementById("aisb-add-section-modal");

    if (!modal) {
      modal = document.createElement("div");
      modal.id = "aisb-add-section-modal";
      modal.style.cssText =
        "position:fixed;top:0;left:0;width:100%;height:100%;" +
        "background:rgba(10,15,23,0.88);z-index:199999;" +
        "display:flex;align-items:center;justify-content:center;" +
        "backdrop-filter:blur(6px);padding:24px;box-sizing:border-box;";
      document.body.appendChild(modal);

      modal.addEventListener("click", (e) => {
        if (e.target === modal) modal.style.display = "none";
      });
    }

    modal.style.display = "flex";
    modal.innerHTML = `
      <div style="background:#3f3f46;width:100%;max-width:1100px;height:88vh;
        border-radius:14px;border:1px solid rgba(255,255,255,0.10);
        display:flex;flex-direction:column;overflow:hidden;
        box-shadow:0 24px 64px rgba(0,0,0,0.55);">
        <div class="aisb-ep-header" style="flex-shrink:0;background:#27272a;">
          <span class="aisb-ep-title">Sectie toevoegen</span>
          <button class="aisb-ep-close" id="aisb-add-sec-close">✕</button>
        </div>
        <div style="padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.08);
          display:flex;gap:10px;align-items:center;flex-shrink:0;background:#3f3f46;">
          <input type="text" class="aisb-ep-input" id="aisb-add-sec-search"
            placeholder="Zoek template…" style="flex:1;margin:0;">
        </div>
        <div id="aisb-add-sec-grid"
          style="flex:1;overflow-y:auto;padding:18px;
          display:grid;grid-template-columns:1fr 1fr;gap:18px;align-content:start;background:#52525b;">
          <div style="grid-column:1/-1;text-align:center;padding:24px;color:#e6e9ef;font-size:13px;">
            Templates laden…
          </div>
        </div>
      </div>
    `;

    modal.querySelector("#aisb-add-sec-close").addEventListener("click", () => {
      modal.style.display = "none";
    });

    const grid = modal.querySelector("#aisb-add-sec-grid");
    const search = modal.querySelector("#aisb-add-sec-search");

    function renderGrid(items) {
      if (!items.length) {
        grid.innerHTML =
          '<div style="grid-column:1/-1;text-align:center;padding:24px;color:#9aa3b2;font-size:13px;">Geen templates gevonden.</div>';
        return;
      }
      const previewW = 1200;
      const previewH = 700;
      const previewDisplayH = 480; // vaste hoogte in px voor de preview-box

      grid.innerHTML = items
        .map((t) => {
          const tag = (t.tags && t.tags[0]) || t.ttype || "";
          const src =
            ((window.AISB_DESIGN && AISB_DESIGN.previewUrl) || "") + t.id;
          return `<button type="button" class="aisb-ep-tpl-card"
              data-id="${t.id}" data-src="${src}" data-type="${D.escapeHtml(tag)}"
              style="padding:0 !important;margin:0 !important;border:2px solid rgba(255,255,255,0.10);
              border-radius:10px;overflow:hidden;background:#fff;cursor:pointer;
              display:flex !important;flex-direction:column;width:100%;text-align:left;
              height:auto !important;min-height:${previewDisplayH + 64}px;
              transition:border-color 0.18s,transform 0.15s,box-shadow 0.18s;
              box-shadow:0 4px 14px rgba(0,0,0,0.35);
              font-family:inherit;line-height:1;">
              <div class="aisb-ep-tpl-preview"
                style="position:relative;display:block;width:100%;
                height:${previewDisplayH}px !important;min-height:${previewDisplayH}px !important;
                overflow:hidden;background:#fff;pointer-events:none;flex-shrink:0;"></div>
              <div style="padding:10px 12px;background:#0a0f17;
                border-top:1px solid rgba(255,255,255,0.07);flex-shrink:0;">
                <div style="color:#e6e9ef;font-size:13px;font-weight:600;
                  white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3;">
                  ${D.escapeHtml(t.title)}
                </div>
                <div style="margin-top:5px;display:flex;gap:5px;flex-wrap:wrap;">
                  ${
                    tag
                      ? `<span style="background:rgba(17,140,240,0.18);color:#7cc0ff;
                    padding:2px 7px;border-radius:4px;font-size:10px;font-weight:500;">${D.escapeHtml(tag)}</span>`
                      : ""
                  }
                </div>
              </div>
            </button>`;
        })
        .join("");

      const io = new IntersectionObserver(
        (entries) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const card = entry.target;
            io.unobserve(card);
            const pw = card.querySelector(".aisb-ep-tpl-preview");
            if (!pw || pw.querySelector("iframe")) return;
            requestAnimationFrame(() => {
              const w =
                pw.offsetWidth || pw.getBoundingClientRect().width || 400;
              const scale = w / previewW;
              const scaledH = previewH * scale;
              // Top-uitlijnen zodat de bovenkant van de sectie zichtbaar is
              const offsetY = Math.max(0, (scaledH - previewDisplayH) / 2);
              const pif = document.createElement("iframe");
              pif.src = card.dataset.src;
              pif.loading = "lazy";
              pif.scrolling = "no";
              pif.setAttribute(
                "style",
                `border:0!important;` +
                  `width:${previewW}px!important;` +
                  `height:${previewH}px!important;` +
                  `max-width:none!important;max-height:none!important;` +
                  `min-width:${previewW}px!important;` +
                  `transform:scale(${scale}) translateY(-${offsetY / scale}px);` +
                  `transform-origin:0 0;` +
                  `pointer-events:none;display:block;position:absolute;top:0;left:0;`,
              );
              pw.appendChild(pif);
            });
          });
        },
        { root: grid, rootMargin: "300px" },
      );

      grid.querySelectorAll(".aisb-ep-tpl-card").forEach((card) => {
        io.observe(card);
        card.addEventListener("mouseenter", () => {
          card.style.borderColor = "#118cf0";
          card.style.transform = "translateY(-3px)";
          card.style.boxShadow = "0 8px 28px rgba(17,140,240,0.35)";
        });
        card.addEventListener("mouseleave", () => {
          card.style.borderColor = "rgba(255,255,255,0.10)";
          card.style.transform = "none";
          card.style.boxShadow = "0 4px 14px rgba(0,0,0,0.35)";
        });
        card.addEventListener("click", () => {
          const tplId = card.dataset.id;
          const tplType = card.dataset.type;
          if (!tplId) return;
          modal.style.display = "none";
          D._doInsertSection(afterIframe, pageBody, page, tplId, tplType);
        });
      });
    }

    function applyFilter() {
      const q = (search.value || "").toLowerCase().trim();
      const items = (D._templatesCache || []).filter(
        (t) => !q || (t.title || "").toLowerCase().includes(q),
      );
      renderGrid(items);
    }

    search.addEventListener("input", applyFilter);

    if (D._templatesCache) {
      applyFilter();
    } else {
      const fd = new FormData();
      fd.append("action", "aisb_design_list_templates");
      fd.append("nonce", (window.AISB_DESIGN && AISB_DESIGN.nonce) || "");
      fetch(
        (window.AISB_DESIGN && AISB_DESIGN.ajaxUrl) ||
          "/wp-admin/admin-ajax.php",
        { method: "POST", credentials: "same-origin", body: fd },
      )
        .then((r) => r.json())
        .then((j) => {
          if (j && j.success && Array.isArray(j.data && j.data.templates)) {
            D._templatesCache = j.data.templates;
            applyFilter();
          } else {
            grid.innerHTML =
              '<div style="grid-column:1/-1;text-align:center;padding:24px;color:#ff8a8a;font-size:13px;">Fout bij laden.</div>';
          }
        })
        .catch(() => {
          grid.innerHTML =
            '<div style="grid-column:1/-1;text-align:center;padding:24px;color:#ff8a8a;font-size:13px;">Netwerkfout.</div>';
        });
    }
  };

  /* ── Verwerkt de template-keuze: roept PHP aan en voegt iframe toe ── */

  D._doInsertSection = async function (
    afterIframe,
    pageBody,
    page,
    tplId,
    tplType,
  ) {
    // Korte status-notificatie onderaan het scherm
    const notify = document.createElement("div");
    notify.style.cssText =
      "position:fixed;bottom:28px;left:50%;transform:translateX(-50%);" +
      "background:#1a1f2e;color:#e6e9ef;padding:12px 22px;" +
      "border-radius:10px;font-size:13px;font-weight:500;z-index:299999;" +
      "box-shadow:0 4px 20px rgba(0,0,0,0.45);" +
      "border:1px solid rgba(255,255,255,0.09);white-space:nowrap;";
    notify.textContent = "⏳ Sectie aanmaken…";
    document.body.appendChild(notify);

    try {
      const out = await D.post("aisb_design_insert_section", {
        project_id: D.projectId,
        sitemap_version_id: afterIframe._sitemapVersionId || 0,
        page_slug: afterIframe._pageSlug || (page && page.slug) || "",
        after_uuid:
          (afterIframe._sectionData && afterIframe._sectionData.uuid) || "",
        bricks_template_id: tplId,
      });

      if (!out || !out.success || !out.data.ai_wireframe_id) {
        throw new Error(
          (out && out.data && out.data.message) || "Onbekende fout",
        );
      }

      const newSection = {
        type: out.data.type || tplType || "section",
        uuid: out.data.uuid,
        ai_wireframe_id: out.data.ai_wireframe_id,
        bricks_template_id: parseInt(tplId, 10),
        layout_key: "bricks_" + tplId,
        media_count: 0,
        patch: [],
      };

      // Lokale wireframe-data bijwerken
      if (page && page.sections) {
        const afterUuid =
          afterIframe._sectionData && afterIframe._sectionData.uuid;
        const idx = page.sections.findIndex((s) => s.uuid === afterUuid);
        if (idx >= 0) page.sections.splice(idx + 1, 0, newSection);
        else page.sections.push(newSection);
      }

      // DOM bijwerken
      D._insertSectionAfterWrap(
        afterIframe.parentElement,
        newSection,
        page,
        pageBody,
      );

      notify.textContent = "✓ Sectie toegevoegd";
      notify.style.background = "#16a34a";
      setTimeout(() => notify.remove(), 2200);
    } catch (err) {
      console.error("[AISB] insert section error:", err);
      notify.textContent = "⚠ Mislukt: " + (err.message || "Onbekend");
      notify.style.borderColor = "#ef4444";
      setTimeout(() => notify.remove(), 3500);
    }
  };

  /* ── Maakt de nieuwe iframe-wrap aan en voegt hem in na afterWrap ── */

  D._insertSectionAfterWrap = function (afterWrap, section, page, pageBody) {
    const postId = section.ai_wireframe_id || section.bricks_template_id;
    if (!postId) return;

    const wrap = document.createElement("div");
    wrap.className = "aisb-design-iframe-wrap";

    const iframe = document.createElement("iframe");
    iframe.src =
      ((window.AISB_DESIGN && AISB_DESIGN.previewUrl) || "") + postId;
    iframe.className = "aisb-design-iframe";
    iframe.scrolling = "yes";
    iframe._loaded = false;
    iframe._pageSlug = page ? page.slug : "";
    iframe._sitemapVersionId = page ? page.sitemap_version_id || 0 : 0;
    iframe._sectionIdx = D.allIframes.length;
    iframe._localSectionIdx =
      page && page.sections ? page.sections.length - 1 : 0;
    iframe._sectionType = section.type || "";
    iframe._sectionPostId = postId;
    iframe._sectionData = section;
    iframe.dataset.aisbSection = "1";

    iframe.addEventListener("load", () => {
      iframe._loaded = true;
      if (D.injectOverride) D.injectOverride(iframe);
      if (D.injectImages) D.injectImages(iframe);
      if (D.applyPatch) D.applyPatch(iframe);
      try {
        const h = iframe.contentDocument.documentElement.scrollHeight || 400;
        iframe.style.height = h + "px";
        wrap.style.height = h + "px";
      } catch (e) {
        iframe.style.height = "500px";
        wrap.style.height = "500px";
      }
      if (D._setupIframeInteractivity) D._setupIframeInteractivity(iframe);
    });

    // + Sectie knop op de nieuwe wrap
    const addBtn = document.createElement("button");
    addBtn.className = "aisb-add-section-btn";
    addBtn.type = "button";
    addBtn.innerHTML = "<span>+</span> Sectie";
    addBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      if (D.openAddSectionModal) D.openAddSectionModal(iframe, pageBody, page);
    });

    wrap.appendChild(iframe);
    wrap.appendChild(addBtn);

    // Invoegen na afterWrap
    if (afterWrap && afterWrap.parentNode === pageBody) {
      afterWrap.after(wrap);
    } else if (pageBody) {
      pageBody.appendChild(wrap);
    }

    D.allIframes.push(iframe);
  };
})();
