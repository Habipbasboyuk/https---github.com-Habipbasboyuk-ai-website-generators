/**
 * design/editor-panel.js - Zijpaneel voor het bewerken van geselecteerde elementen.
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

  D.openElementEditor = function (el, doc, iframe) {
    if (!D._editorPanel) D.initEditorPanel();

    // Geen outline op het geselecteerde element — hover-stijl blijft gewoon actief
    D._selectedEl = el;
    D._selectedDoc = doc;
    D._selectedIframe =
      iframe ||
      (doc && doc.defaultView && doc.defaultView.frameElement) ||
      null;

    D._showElementEditor(el);
    D._editorPanel.classList.add("is-open");
  };

  /* ── Sectie selecteren (hele iframe / Bricks template) ─────── */

  D.openSectionEditor = function (iframe, doc) {
    if (!D._editorPanel) D.initEditorPanel();

    D._selectedEl = null;
    D._selectedDoc = doc || (iframe && iframe.contentDocument) || null;
    D._selectedIframe = iframe;

    D._showSectionEditor(iframe);
    D._editorPanel.classList.add("is-open");
  };

  /* ── Sectie-paneel renderen ─────────────────────────────────── */

  D._showSectionEditor = function (iframe) {
    const panel = D._editorPanel;
    const type = (iframe && iframe._sectionType) || "section";
    const isMirrored = !!(iframe && iframe._aisbMirrored);
    const currentId = iframe && iframe._sectionPostId;

    panel.querySelector(".aisb-ep-title").textContent = "Sectie · " + type;

    // Huidige achtergrondkleur van de root-sectie ophalen voor de picker.
    // Belangrijk: pak de OUTERMOST Bricks-element. Sommige templates wrappen
    // de sectie in een `.brxe-container` waarbinnen pas een `.brxe-section`
    // staat — dan zou een naïeve `querySelector('.brxe-section')` de inner
    // sectie kiezen ipv de echte root, en dan kleurt alleen de binnenste mee.
    let secRoot = null;
    let secBgHex = "#ffffff";
    try {
      const doc = iframe && iframe.contentDocument;
      if (doc && doc.body) {
        // Eerste echte content-wrapper: in onze preview is dat
        // `.aisb-bricks-preview-wrap`. Pak diens eerste Bricks-child.
        const previewWrap =
          doc.body.querySelector(".aisb-bricks-preview-wrap") || doc.body;
        const candidates = Array.from(previewWrap.children).filter((c) => {
          const cls = String(c.className || "");
          return (
            /\bbrxe-/.test(cls) ||
            c.tagName === "SECTION" ||
            c.tagName === "HEADER" ||
            c.tagName === "FOOTER"
          );
        });
        secRoot =
          candidates[0] ||
          previewWrap.querySelector(".brxe-section") ||
          previewWrap.querySelector("section") ||
          previewWrap.firstElementChild;
        if (secRoot) {
          const cs = doc.defaultView.getComputedStyle(secRoot);
          secBgHex = D._rgbToHex(cs.backgroundColor) || "#ffffff";
        }
      }
    } catch (e) {
      /* cross-origin – skip */
    }

    let secPadTop = 0;
    let secPadBottom = 0;
    try {
      if (secRoot) {
        const doc = iframe && iframe.contentDocument;
        const cs = doc.defaultView.getComputedStyle(secRoot);
        secPadTop = parseInt(cs.paddingTop) || 0;
        secPadBottom = parseInt(cs.paddingBottom) || 0;
      }
    } catch (e) {
      /* cross-origin */
    }

    const body = panel.querySelector(".aisb-ep-body");
    body.innerHTML = `
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Achtergrondkleur sectie</label>
        <div class="aisb-ep-row">
          <input type="color" class="aisb-ep-color" id="aisb-ep-sec-bg"
            value="${secBgHex}">
          <span class="aisb-ep-color-val" id="aisb-ep-sec-bg-val">${secBgHex}</span>
          <button type="button" class="aisb-ep-upload-btn aisb-ep-reset-btn" id="aisb-ep-sec-bg-reset">
            Reset
          </button>
        </div>
      </div>
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Padding boven</label>
        <div class="aisb-ep-row">
          <input type="range" class="aisb-ep-range" id="aisb-ep-sec-pad-top"
            min="0" max="200" step="1" value="${secPadTop}">
          <input type="number" class="aisb-ep-number" id="aisb-ep-sec-pad-top-num"
            min="0" max="200" value="${secPadTop}">
          <span>px</span>
        </div>
      </div>
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Padding onder</label>
        <div class="aisb-ep-row">
          <input type="range" class="aisb-ep-range" id="aisb-ep-sec-pad-bottom"
            min="0" max="200" step="1" value="${secPadBottom}">
          <input type="number" class="aisb-ep-number" id="aisb-ep-sec-pad-bottom-num"
            min="0" max="200" value="${secPadBottom}">
          <span>px</span>
        </div>
      </div>
      <div class="aisb-ep-group">
        <label class="aisb-ep-label">Layout spiegelen</label>
        <button type="button" class="aisb-ep-upload-btn" id="aisb-ep-mirror-btn">
          ${isMirrored ? "↔ Spiegeling uitzetten" : "↔ Spiegel sectie (links ⇄ rechts)"}
        </button>
      </div>
      <details class="aisb-ep-accordion" id="aisb-ep-layout-accordion">
        <summary>Andere layout</summary>
        <div class="aisb-ep-accordion-body">
          <div class="aisb-ep-tpl-categories" id="aisb-ep-tpl-categories"></div>
          <input type="text" class="aisb-ep-input aisb-ep-tpl-search" id="aisb-ep-tpl-search" placeholder="Zoek layout…" style="margin-top:8px">
          <div class="aisb-ep-tpl-grid" id="aisb-ep-tpl-grid">
            <div class="aisb-ep-tpl-loading">Templates laden…</div>
          </div>
        </div>
      </details>
    `;

    // Bind achtergrondkleur picker (root-sectie)
    const bgInput = body.querySelector("#aisb-ep-sec-bg");
    const bgValEl = body.querySelector("#aisb-ep-sec-bg-val");
    const bgReset = body.querySelector("#aisb-ep-sec-bg-reset");

    // Helper: zet achtergrond op ALLE Bricks-elementen in het iframe via
    // inline style.backgroundColor met !important. Dit is nodig omdat Bricks
    // per-element CSS met ID-selector genereert (#brxe-xyz{...!important})
    // die elke class-selector verslaat — alleen inline !important wint.
    // We itereren vanaf doc.body (niet vanaf secRoot) zodat het ook werkt
    // wanneer het outermost element verandert na een drag/reload.
    // Elementen waar de gebruiker zelf een eigen bg-kleur of bg-image op
    // zette worden overgeslagen.
    const SEL = ".brxe-section,.brxe-container,.brxe-block,.brxe-div,section";
    const isUserBg = (el) => {
      if (el.dataset.aisbSecBg === "1") return false;
      const inline = el.getAttribute("style") || "";
      if (/background-image\s*:/i.test(inline)) return true;
      if (/background-color\s*:/i.test(inline)) return true;
      return false;
    };
    const applySectionBg = (color) => {
      const doc = (iframe && iframe.contentDocument) || null;
      if (!doc || !doc.body) return;
      const targets = Array.from(doc.body.querySelectorAll(SEL));
      if (!color) {
        targets.forEach((el) => {
          if (el.dataset.aisbSecBg === "1") {
            el.style.removeProperty("background-color");
            delete el.dataset.aisbSecBg;
          }
        });
        if (doc.body && doc.body.dataset.aisbSecBg === "1") {
          doc.body.style.removeProperty("background-color");
          delete doc.body.dataset.aisbSecBg;
        }
        return;
      }
      targets.forEach((el) => {
        if (isUserBg(el)) return;
        el.style.setProperty("background-color", color, "important");
        el.dataset.aisbSecBg = "1";
      });
      // Body ook (anders zie je de oude bg rond de sectie)
      doc.body.style.setProperty("background-color", color, "important");
      doc.body.dataset.aisbSecBg = "1";
    };

    if (bgInput && secRoot) {
      bgInput.addEventListener("input", () => {
        applySectionBg(bgInput.value);
        if (bgValEl) bgValEl.textContent = bgInput.value;
        D._registerEdit(iframe, "css", secRoot, {
          prop: "background-color",
          value: bgInput.value,
          // Marker zodat applyStoredEdits ook de cascade kan reproduceren
          cascade: "section",
        });
      });
    }
    if (bgReset && secRoot) {
      bgReset.addEventListener("click", () => {
        applySectionBg("");
        D._registerEdit(iframe, "css", secRoot, {
          prop: "background-color",
          value: "",
          cascade: "section",
        });
        // Hertekenen om de nieuwe (overgenomen) computed kleur te tonen.
        D._showSectionEditor(iframe);
      });
    }

    const mirrorBtn = body.querySelector("#aisb-ep-mirror-btn");
    if (mirrorBtn) {
      mirrorBtn.addEventListener("click", () => {
        D.toggleMirrorLayout(iframe);
        D._registerEdit(iframe, "mirror", null, {
          mirrored: !!iframe._aisbMirrored,
        });
        D._showSectionEditor(iframe);
      });
    }

    // Padding boven
    const padTopRange = body.querySelector("#aisb-ep-sec-pad-top");
    const padTopNum = body.querySelector("#aisb-ep-sec-pad-top-num");
    const applyPadTop = (val) => {
      if (!secRoot) return;
      const targets = [
        secRoot,
        ...Array.from(
          secRoot.querySelectorAll(".brxe-container,.brxe-block,.brxe-div"),
        ),
      ];
      targets.forEach((t) =>
        t.style.setProperty("padding-top", val + "px", "important"),
      );
      D._registerEdit(iframe, "css", secRoot, {
        prop: "padding-top",
        value: val + "px",
      });
      if (iframe._fitHeight) iframe._fitHeight();
    };
    if (padTopRange) {
      padTopRange.addEventListener("input", () => {
        if (padTopNum) padTopNum.value = padTopRange.value;
        applyPadTop(padTopRange.value);
      });
    }
    if (padTopNum) {
      padTopNum.addEventListener("input", () => {
        if (padTopRange) padTopRange.value = padTopNum.value;
        applyPadTop(padTopNum.value);
      });
    }

    // Padding onder
    const padBottomRange = body.querySelector("#aisb-ep-sec-pad-bottom");
    const padBottomNum = body.querySelector("#aisb-ep-sec-pad-bottom-num");
    const applyPadBottom = (val) => {
      if (!secRoot) return;
      const targets = [
        secRoot,
        ...Array.from(
          secRoot.querySelectorAll(".brxe-container,.brxe-block,.brxe-div"),
        ),
      ];
      targets.forEach((t) =>
        t.style.setProperty("padding-bottom", val + "px", "important"),
      );
      D._registerEdit(iframe, "css", secRoot, {
        prop: "padding-bottom",
        value: val + "px",
      });
      if (iframe._fitHeight) iframe._fitHeight();
    };
    if (padBottomRange) {
      padBottomRange.addEventListener("input", () => {
        if (padBottomNum) padBottomNum.value = padBottomRange.value;
        applyPadBottom(padBottomRange.value);
      });
    }
    if (padBottomNum) {
      padBottomNum.addEventListener("input", () => {
        if (padBottomRange) padBottomRange.value = padBottomNum.value;
        applyPadBottom(padBottomNum.value);
      });
    }

    D._loadTemplatePicker(iframe, body, type, currentId);
  };

  /* ── Templates inline laden + renderen in het sectie-paneel ── */

  D._templatesCache = null; // cache zodat we niet bij elke open opnieuw laden

  D._loadTemplatePicker = function (iframe, body, currentType, currentId) {
    const grid = body.querySelector("#aisb-ep-tpl-grid");
    const search = body.querySelector("#aisb-ep-tpl-search");
    const catBar = body.querySelector("#aisb-ep-tpl-categories");
    if (!grid) return;

    // Actieve categorie: start op het huidige sectie-type (of 'all')
    let activeCategory =
      currentType && currentType !== "section" ? currentType : "all";

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
        grid.innerHTML = `<div class="aisb-add-section-modal__msg aisb-add-section-modal__msg--muted">Geen templates gevonden.</div>`;
        return;
      }
      const previewW = 1200; // viewport breedte van de preview-iframe
      const previewH = 500; // weergeven we max ~500px van de sectie

      grid.innerHTML = items
        .map((t) => {
          const isCurrent = String(t.id) === String(currentId);
          const tag = (t.tags && t.tags[0]) || t.ttype || "";
          const previewSrc =
            (window.AISB_DESIGN && AISB_DESIGN.previewUrl
              ? AISB_DESIGN.previewUrl
              : "") + t.id;
          return `
          <button type="button" class="aisb-ep-tpl-card${isCurrent ? " is-current" : ""}" data-id="${t.id}" data-src="${previewSrc}" title="${escapeHtml(t.title)}">
            <div class="aisb-ep-tpl-preview" style="aspect-ratio:${previewW}/${previewH};">
              <!-- iframe wordt lazy geladen wanneer in beeld -->
            </div>
            <div class="aisb-ep-tpl-card__footer">
              <div class="aisb-ep-tpl-card__title">${escapeHtml(t.title)}</div>
              <div class="aisb-ep-tpl-card__meta">
                ${tag ? `<span class="aisb-ep-tpl-card__tag">${escapeHtml(tag)}</span>` : ""}
                ${isCurrent ? '<span class="aisb-ep-tpl-card__current">● Huidig</span>' : ""}
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
          // Na load: pas de wrapper-aspect-ratio aan zodat korte secties
          // (bv. een contactformulier) geen grote witruimte krijgen.
          previewIframe.addEventListener("load", () => {
            try {
              const doc = previewIframe.contentDocument;
              if (!doc) return;
              const realH =
                doc.documentElement.scrollHeight || doc.body.scrollHeight;
              if (!realH) return;
              // Gebruik aspect-ratio op basis van de echte sectie-hoogte
              // (gecapt op previewH zodat ultra-lange secties croppen).
              const cappedH = Math.min(realH, previewH);
              wrap.style.aspectRatio = previewW + "/" + cappedH;
              previewIframe.style.height = realH + "px";
            } catch (_) {
              /* cross-origin: fallback aspect-ratio blijft staan */
            }
          });
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
        card.addEventListener("click", () => {
          const newId = card.dataset.id;
          if (!newId) return;
          D.replaceSectionTemplate(iframe, newId);
        });
      });
    }

    function buildCategoryBar(templates) {
      if (!catBar) return;
      // Verzamel unieke tags uit alle templates
      const tagSet = new Set();
      templates.forEach((t) =>
        (t.tags || []).forEach((tag) => tagSet.add(tag)),
      );
      const tags = Array.from(tagSet).sort();

      catBar.innerHTML = [
        { key: "all", label: "Alle" },
        ...(currentType && currentType !== "section"
          ? [{ key: currentType, label: currentType }]
          : []),
        ...tags
          .filter((tag) => tag !== currentType)
          .map((tag) => ({ key: tag, label: tag })),
      ]
        .map(
          (cat) =>
            `<button type="button" class="aisb-ep-cat-btn${activeCategory === cat.key ? " is-active" : ""}" data-cat="${cat.key}">${cat.label}</button>`,
        )
        .join("");

      catBar.querySelectorAll(".aisb-ep-cat-btn").forEach((btn) => {
        btn.addEventListener("click", () => {
          activeCategory = btn.dataset.cat;
          catBar
            .querySelectorAll(".aisb-ep-cat-btn")
            .forEach((b) => b.classList.toggle("is-active", b === btn));
          applyFilters();
        });
      });
    }

    function applyFilters() {
      const all = D._templatesCache || [];
      const q = (search.value || "").toLowerCase().trim();
      const items = all.filter((t) => {
        if (
          activeCategory !== "all" &&
          !(t.tags || []).includes(activeCategory)
        )
          return false;
        if (q && !(t.title || "").toLowerCase().includes(q)) return false;
        return true;
      });
      render(items);
    }

    if (search) search.addEventListener("input", applyFilters);

    if (D._templatesCache) {
      buildCategoryBar(D._templatesCache);
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
          buildCategoryBar(D._templatesCache);
          applyFilters();
        } else {
          grid.innerHTML = `<div class="aisb-add-section-modal__msg aisb-add-section-modal__msg--error">Fout bij laden.</div>`;
        }
      })
      .catch(() => {
        grid.innerHTML = `<div class="aisb-add-section-modal__msg aisb-add-section-modal__msg--error">Netwerkfout.</div>`;
      });
  };

  /* ── Sectie spiegelen (flex-direction omdraaien) ───────────── */

  D.toggleMirrorLayout = function (iframe) {
    if (!iframe || !iframe.contentDocument) return;
    const doc = iframe.contentDocument;
    const existing = doc.getElementById("aisb-section-mirror");
    if (existing) {
      existing.remove();
      iframe._aisbMirrored = false;
      return;
    }
    D._applySectionMirror(doc, iframe);
  };

  /* ── Iframe-src vervangen door nieuw template ──────────────── */

  D.replaceSectionTemplate = async function (iframe, newPostId) {
    if (!iframe || !newPostId) return;
    const previewUrl = (window.AISB_DESIGN && AISB_DESIGN.previewUrl) || "";

    // Reset iframe + wrap hoogte direct: anders blijft de oude (mogelijk
    // mega-lange) sectie-hoogte zichtbaar terwijl de nieuwe korte sectie
    // erin laadt — dat ziet eruit alsof de oude root er nog omheen zit.
    const wrap = iframe.parentElement;
    iframe.style.height = "200px";
    if (wrap) wrap.style.height = "200px";

    // Toon direct een laad-indicator in het iframe zodat de gebruiker weet dat
    // de AI-fill bezig is (kan ~15-30s duren).
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (doc && doc.body) {
        doc.body.innerHTML =
          '<div class="aisb-ep-swap-loading">' +
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

  D._showElementEditor = function (el) {
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

    const friendlyTitle = isImg
      ? "Afbeelding"
      : isTextEl
        ? tag === "button" ||
          /\bbrxe-button\b|\bbricks-button\b/.test(String(el.className || ""))
          ? "Knop"
          : tag === "a"
            ? "Link"
            : /^h[1-6]$/.test(tag)
              ? "Titel"
              : "Tekst"
        : "Element";
    panel.querySelector(".aisb-ep-title").textContent = friendlyTitle;

    const body = panel.querySelector(".aisb-ep-body");

    /* ── Afbeelding ── */
    if (isImg) {
      body.innerHTML = `
        <details class="aisb-ep-accordion" open>
          <summary>Afbeelding</summary>
          <div class="aisb-ep-accordion-body">
            <div class="aisb-ep-group aisb-ep-img-source-group">
              <div class="aisb-ep-align-btns aisb-ep-img-tabs" id="aisb-ep-img-tabs">
                <button class="aisb-ep-align-btn is-active" data-tab="upload">Upload</button>
                <button class="aisb-ep-align-btn" data-tab="unsplash">Unsplash</button>
              </div>
              <!-- Upload Tab -->
              <div id="aisb-ep-tab-upload">
                <label class="aisb-ep-upload-zone" id="aisb-ep-upload-label">
                  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                  <span>Klik of sleep een afbeelding</span>
                  <input type="file" id="aisb-ep-upload-input" accept="image/*" style="display:none">
                </label>
                <div id="aisb-ep-upload-status"></div>
              </div>
              <!-- Unsplash Tab -->
              <div id="aisb-ep-tab-unsplash" style="display:none">
                <div class="aisb-ep-unsplash-search-row">
                  <input type="text" class="aisb-ep-input" id="aisb-ep-unsplash-search" placeholder="Zoek op Unsplash…">
                  <button class="aisb-ep-upload-btn" id="aisb-ep-unsplash-go">Zoek</button>
                </div>
                <div class="aisb-ep-unsplash-grid" id="aisb-ep-unsplash-results"></div>
              </div>
            </div>
          </div>
        </details>
        <details class="aisb-ep-accordion">
          <summary>Afmetingen &amp; Stijl</summary>
          <div class="aisb-ep-accordion-body">
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
                <input type="number" class="aisb-ep-number" id="aisb-ep-radius-num"
                  min="0" max="50" value="${parseInt(computed.borderRadius) || 0}">
                <span>px</span>
              </div>
            </div>
            <div class="aisb-ep-group">
              <label class="aisb-ep-label">Doorzichtigheid</label>
              <div class="aisb-ep-row">
                <input type="range" class="aisb-ep-range" id="aisb-ep-opacity"
                  min="0" max="1" step="0.05"
                  value="${parseFloat(computed.opacity) ?? 1}">
                <input type="number" class="aisb-ep-number" id="aisb-ep-opacity-num"
                  min="0" max="100" step="5" value="${Math.round((parseFloat(computed.opacity) ?? 1) * 100)}">
                <span>%</span>
              </div>
            </div>
          </div>
        </details>
      `;
      D._bindImageEditorControls(el);
      return;
    }

    /* ── Tekstelement ── */
    if (isTextEl) {
      const rawText = (el.innerText || el.textContent || "").trim();
      const preview = rawText.slice(0, 18) || "Voorbeeld";
      // Button-achtige elementen krijgen ook een achtergrond + border-picker.
      // Detectie: <button>, of <a>/<span>/<div> met een button-class
      // (Bricks gebruikt .brxe-button / .bricks-button).
      const cls = String(el.className || "");
      const isBtn =
        tag === "button" ||
        /\bbrxe-button\b/.test(cls) ||
        /\bbricks-button\b/.test(cls) ||
        (tag === "a" && /\bbtn\b|\bbutton\b/.test(cls));
      const btnBgHex = D._rgbToHex(computed.backgroundColor) || "#118cf0";
      const btnBorderHex = D._rgbToHex(computed.borderTopColor) || "#118cf0";
      const btnPaddingVert = parseInt(computed.paddingTop) || 10;
      const btnPaddingHorz = parseInt(computed.paddingLeft) || 20;
      const btnBorderRadius = parseInt(computed.borderRadius) || 0;
      const btnBgBlock = isBtn
        ? `
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Knop achtergrond</label>
          <div class="aisb-ep-row">
            <input type="color" class="aisb-ep-color" id="aisb-ep-btn-bg"
              value="${btnBgHex}">
            <span class="aisb-ep-color-val" id="aisb-ep-btn-bg-val">${btnBgHex}</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Knop randkleur</label>
          <div class="aisb-ep-row">
            <input type="color" class="aisb-ep-color" id="aisb-ep-btn-border"
              value="${btnBorderHex}">
            <span class="aisb-ep-color-val" id="aisb-ep-btn-border-val">${btnBorderHex}</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Padding Y (Boven/Onder)</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-btn-pad-y"
              min="0" max="60" step="1" value="${btnPaddingVert}">
            <input type="number" class="aisb-ep-number" id="aisb-ep-btn-pad-y-num"
              min="0" max="60" value="${btnPaddingVert}">
            <span>px</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Padding X (Links/Rechts)</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-btn-pad-x"
              min="0" max="100" step="1" value="${btnPaddingHorz}">
            <input type="number" class="aisb-ep-number" id="aisb-ep-btn-pad-x-num"
              min="0" max="100" value="${btnPaddingHorz}">
            <span>px</span>
          </div>
        </div>
        <div class="aisb-ep-group">
          <label class="aisb-ep-label">Border radius</label>
          <div class="aisb-ep-row">
            <input type="range" class="aisb-ep-range" id="aisb-ep-btn-radius"
              min="0" max="100" step="1" value="${btnBorderRadius}">
            <input type="number" class="aisb-ep-number" id="aisb-ep-btn-radius-num"
              min="0" max="100" value="${btnBorderRadius}">
            <span>px</span>
          </div>
        </div>`
        : "";
      body.innerHTML = `
        <details class="aisb-ep-accordion" open>
          <summary>Tekst &amp; Kleur</summary>
          <div class="aisb-ep-accordion-body">
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
            ${btnBgBlock}
          </div>
        </details>
        <details class="aisb-ep-accordion">
          <summary>Lettertype</summary>
          <div class="aisb-ep-accordion-body aisb-ep-accordion-body--grid">
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
        </details>
        <details class="aisb-ep-accordion">
          <summary>Grootte &amp; Opmaak</summary>
          <div class="aisb-ep-accordion-body">
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
                <input type="number" class="aisb-ep-number" id="aisb-ep-lh-num"
                  min="0.8" max="3" step="0.05" value="${(parseFloat(computed.lineHeight) / parseFloat(computed.fontSize)).toFixed(2) || 1.5}">
              </div>
            </div>
          </div>
        </details>
        <details class="aisb-ep-accordion">
          <summary>Gewicht</summary>
          <div class="aisb-ep-accordion-body aisb-ep-accordion-body--grid">
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
        </details>
      `;
      D._bindTextEditorControls(el);
      return;
    }

    /* ── Algemeen element ── */
    body.innerHTML = `
      <details class="aisb-ep-accordion" open>
        <summary>Achtergrond &amp; Kleur</summary>
        <div class="aisb-ep-accordion-body">
          <div class="aisb-ep-group">
            <label class="aisb-ep-label">Achtergrondkleur</label>
            <div class="aisb-ep-row">
              <input type="color" class="aisb-ep-color" id="aisb-ep-bg"
                value="${D._rgbToHex(computed.backgroundColor) || "#ffffff"}">
              <span class="aisb-ep-color-val">${D._rgbToHex(computed.backgroundColor) || computed.backgroundColor}</span>
            </div>
          </div>
        </div>
      </details>
      <details class="aisb-ep-accordion">
        <summary>Vorm &amp; Zichtbaarheid</summary>
        <div class="aisb-ep-accordion-body">
          <div class="aisb-ep-group">
            <label class="aisb-ep-label">Border radius</label>
            <div class="aisb-ep-row">
              <input type="range" class="aisb-ep-range" id="aisb-ep-radius"
                min="0" max="50" step="1"
                value="${parseInt(computed.borderRadius) || 0}">
              <input type="number" class="aisb-ep-number" id="aisb-ep-radius-num"
                min="0" max="50" value="${parseInt(computed.borderRadius) || 0}">
              <span>px</span>
            </div>
          </div>
          <div class="aisb-ep-group">
            <label class="aisb-ep-label">Doorzichtigheid</label>
            <div class="aisb-ep-row">
              <input type="range" class="aisb-ep-range" id="aisb-ep-opacity"
                min="0" max="1" step="0.05"
                value="${parseFloat(computed.opacity) ?? 1}">
              <input type="number" class="aisb-ep-number" id="aisb-ep-opacity-num"
                min="0" max="100" step="5" value="${Math.round((parseFloat(computed.opacity) ?? 1) * 100)}">
              <span>%</span>
            </div>
          </div>
        </div>
      </details>
    `;
    D._bindGenericEditorControls(el);
  };

  /* ── Extra Modal voor Unsplash ──────────────────────────────── */

  D.openUnsplashPicker = function (keyword, el) {
    let modal = document.getElementById("aisb-ep-unsplash-modal");
    if (!modal) {
      modal = document.createElement("div");
      modal.id = "aisb-ep-unsplash-modal";
      modal.className = "aisb-ep-unsplash-modal";
      modal.innerHTML = `
        <div class="aisb-ep-unsplash-modal__inner">
          <div class="aisb-ep-header">
            <span class="aisb-ep-title">Uitgebreide Unsplash Bibliotheek</span>
            <button class="aisb-ep-close" id="aisb-ep-unsplash-modal-close">✕</button>
          </div>
          <div class="aisb-ep-unsplash-modal__toolbar">
            <input type="text" class="aisb-ep-input" id="aisb-ep-unsplash-modal-search" placeholder="Zoek op Unsplash...">
            <button class="aisb-ep-upload-btn" id="aisb-ep-unsplash-modal-go">Zoek (max 30)</button>
          </div>
          <div id="aisb-ep-unsplash-modal-results" class="aisb-ep-unsplash-modal__results"></div>
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

        resDiv.innerHTML = `
          <div class="aisb-ep-progress-wrap" style="padding:0 8px">
            <div class="aisb-ep-progress-track"><div class="aisb-ep-progress-bar" id="aisb-ep-modal-bar"></div></div>
            <div class="aisb-ep-progress-label"><span>Afbeeldingen laden…</span><span id="aisb-ep-modal-pct">0%</span></div>
          </div>`;
        let mPct = 0;
        const mBar = document.getElementById("aisb-ep-modal-bar");
        const mPctEl = document.getElementById("aisb-ep-modal-pct");
        const mTick = setInterval(() => {
          mPct = Math.min(mPct + (mPct < 60 ? 8 : mPct < 80 ? 3 : 1), 85);
          if (mBar) mBar.style.width = mPct + "%";
          if (mPctEl) mPctEl.textContent = mPct + "%";
        }, 200);

        const fd = new FormData();
        fd.append("action", "aisb_search_similar_images");
        fd.append("nonce", AISB_DESIGN.nonce);
        fd.append("keyword", q);
        fd.append("page", 1);
        fd.append("per_page", 30); // 30 is max via style guide api

        fetch(AISB_DESIGN.ajaxUrl, { method: "POST", body: fd })
          .then((r) => r.json())
          .then((out) => {
            clearInterval(mTick);
            if (mBar) mBar.style.width = "100%";
            if (mPctEl) mPctEl.textContent = "100%";
            if (!out || !out.success || !out.data || !out.data.images) {
              resDiv.innerHTML =
                '<div class="aisb-ep-unsplash-modal__msg aisb-ep-unsplash-modal__msg--error">Fout bij zoeken.</div>';
              return;
            }
            if (!out.data.images.length) {
              resDiv.innerHTML =
                '<div class="aisb-ep-unsplash-modal__msg">Geen resultaten.</div>';
              return;
            }

            resDiv.innerHTML = out.data.images
              .map((img) => {
                if (!img.thumb) return "";
                return `<div class="aisb-ep-unsplash-modal__item" title="${img.alt ? img.alt.replace(/"/g, "&quot;") : "Unsplash"}">
                <img src="${img.thumb}" data-full="${img.full}" alt="" />
              </div>`;
              })
              .join("");
          })
          .catch(() => {
            clearInterval(mTick);
            resDiv.innerHTML =
              '<div class="aisb-ep-unsplash-modal__msg aisb-ep-unsplash-modal__msg--error">Fout bij communicatie met server.</div>';
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
          D._registerEdit(D._selectedIframe, "img", D._selectedEl, {
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

  D._bindTextEditorControls = function (el) {
    const panel = D._editorPanel;

    // Tekst inhoud — innerText preserveert line breaks en overschrijft zonder child-elementen te breken
    const textarea = document.getElementById("aisb-ep-text");
    textarea.addEventListener("input", () => {
      el.innerText = textarea.value;
      // Fix: Bricks counter data-attribuut bijwerken en lopend interval
      // stoppen via iframe-context clearInterval.
      const _cRoot = el.closest("[data-bricks-counter-options]");
      if (_cRoot) {
        try {
          const _iframeWin =
            D._selectedIframe && D._selectedIframe.contentWindow;
          const _cOpts = JSON.parse(
            _cRoot.dataset.bricksCounterOptions || "{}",
          );
          const _cNum = parseFloat(
            String(textarea.value).replace(/[^\d.-]/g, ""),
          );
          if (!isNaN(_cNum)) {
            _cOpts.countTo = _cNum;
            _cOpts.countFrom = _cNum;
            _cRoot.dataset.bricksCounterOptions = JSON.stringify(_cOpts);
          }
          if (
            el.dataset.counterId &&
            el.dataset.counterId !== "aisb-locked" &&
            _iframeWin
          ) {
            _iframeWin.clearInterval(Number(el.dataset.counterId));
          }
          el.dataset.counterId = "aisb-locked";
        } catch (_) {}
      }
      D._registerEdit(D._selectedIframe, "text", el, { text: textarea.value });
    });

    // Kleur
    const colorInput = document.getElementById("aisb-ep-color");
    const colorVal = panel.querySelector(".aisb-ep-color-val");
    colorInput.addEventListener("input", () => {
      el.style.setProperty("color", colorInput.value, "important");
      colorVal.textContent = colorInput.value;
      D._registerEdit(D._selectedIframe, "css", el, {
        prop: "color",
        value: colorInput.value,
      });
    });

    // Knop achtergrond + randkleur (alleen voor button-achtige elementen)
    const btnBgInput = document.getElementById("aisb-ep-btn-bg");
    if (btnBgInput) {
      const btnBgValEl = document.getElementById("aisb-ep-btn-bg-val");
      btnBgInput.addEventListener("input", () => {
        el.style.setProperty("background-color", btnBgInput.value, "important");
        if (btnBgValEl) btnBgValEl.textContent = btnBgInput.value;
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "background-color",
          value: btnBgInput.value,
        });
      });
    }
    const btnBorderInput = document.getElementById("aisb-ep-btn-border");
    if (btnBorderInput) {
      const btnBorderValEl = document.getElementById("aisb-ep-btn-border-val");
      btnBorderInput.addEventListener("input", () => {
        el.style.setProperty("border-color", btnBorderInput.value, "important");
        if (btnBorderValEl) btnBorderValEl.textContent = btnBorderInput.value;
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "border-color",
          value: btnBorderInput.value,
        });
      });
    }

    const btnPadYInput = document.getElementById("aisb-ep-btn-pad-y");
    const btnPadYNum = document.getElementById("aisb-ep-btn-pad-y-num");
    if (btnPadYInput) {
      btnPadYInput.addEventListener("input", () => {
        if (btnPadYNum) btnPadYNum.value = btnPadYInput.value;
        const val = btnPadYInput.value + "px";
        el.style.setProperty("padding-top", val, "important");
        el.style.setProperty("padding-bottom", val, "important");
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "padding-top",
          value: val,
        });
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "padding-bottom",
          value: val,
        });
      });
      if (btnPadYNum) {
        btnPadYNum.addEventListener("input", () => {
          btnPadYInput.value = btnPadYNum.value;
          const val = btnPadYNum.value + "px";
          el.style.setProperty("padding-top", val, "important");
          el.style.setProperty("padding-bottom", val, "important");
          D._registerEdit(D._selectedIframe, "css", el, {
            prop: "padding-top",
            value: val,
          });
          D._registerEdit(D._selectedIframe, "css", el, {
            prop: "padding-bottom",
            value: val,
          });
        });
      }
    }

    const btnPadXInput = document.getElementById("aisb-ep-btn-pad-x");
    const btnPadXNum = document.getElementById("aisb-ep-btn-pad-x-num");
    if (btnPadXInput) {
      btnPadXInput.addEventListener("input", () => {
        if (btnPadXNum) btnPadXNum.value = btnPadXInput.value;
        const val = btnPadXInput.value + "px";
        el.style.setProperty("padding-left", val, "important");
        el.style.setProperty("padding-right", val, "important");
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "padding-left",
          value: val,
        });
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "padding-right",
          value: val,
        });
      });
      if (btnPadXNum) {
        btnPadXNum.addEventListener("input", () => {
          btnPadXInput.value = btnPadXNum.value;
          const val = btnPadXNum.value + "px";
          el.style.setProperty("padding-left", val, "important");
          el.style.setProperty("padding-right", val, "important");
          D._registerEdit(D._selectedIframe, "css", el, {
            prop: "padding-left",
            value: val,
          });
          D._registerEdit(D._selectedIframe, "css", el, {
            prop: "padding-right",
            value: val,
          });
        });
      }
    }

    const btnRadiusInput = document.getElementById("aisb-ep-btn-radius");
    const btnRadiusNum = document.getElementById("aisb-ep-btn-radius-num");
    if (btnRadiusInput) {
      btnRadiusInput.addEventListener("input", () => {
        if (btnRadiusNum) btnRadiusNum.value = btnRadiusInput.value;
        const val = btnRadiusInput.value + "px";
        el.style.setProperty("border-radius", val, "important");
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "border-radius",
          value: val,
        });
      });
      if (btnRadiusNum) {
        btnRadiusNum.addEventListener("input", () => {
          btnRadiusInput.value = btnRadiusNum.value;
          const val = btnRadiusNum.value + "px";
          el.style.setProperty("border-radius", val, "important");
          D._registerEdit(D._selectedIframe, "css", el, {
            prop: "border-radius",
            value: val,
          });
        });
      }
    }

    // Lettertype
    document.getElementById("aisb-ep-font").addEventListener("click", (e) => {
      const btn = e.target.closest(".aisb-ep-font-btn");
      if (!btn) return;
      panel
        .querySelectorAll(".aisb-ep-font-btn")
        .forEach((b) => b.classList.remove("is-active"));
      btn.classList.add("is-active");
      el.style.setProperty("font-family", btn.dataset.font, "important");
      D._registerEdit(D._selectedIframe, "css", el, {
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
      D._registerEdit(D._selectedIframe, "css", el, {
        prop: "font-size",
        value: sizeRange.value + "px",
      });
    });
    sizeNum.addEventListener("input", () => {
      sizeRange.value = sizeNum.value;
      el.style.setProperty("font-size", sizeNum.value + "px", "important");
      D._registerEdit(D._selectedIframe, "css", el, {
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
      D._registerEdit(D._selectedIframe, "css", el, {
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
      D._registerEdit(D._selectedIframe, "css", el, {
        prop: "text-align",
        value: btn.dataset.align,
      });
    });

    // Regelafstand
    const lhRange = document.getElementById("aisb-ep-lh");
    const lhNum = document.getElementById("aisb-ep-lh-num");
    lhRange.addEventListener("input", () => {
      if (lhNum) lhNum.value = parseFloat(lhRange.value).toFixed(2);
      el.style.setProperty("line-height", lhRange.value, "important");
      D._registerEdit(D._selectedIframe, "css", el, {
        prop: "line-height",
        value: lhRange.value,
      });
    });
    if (lhNum) {
      lhNum.addEventListener("input", () => {
        lhRange.value = lhNum.value;
        el.style.setProperty("line-height", lhNum.value, "important");
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "line-height",
          value: lhNum.value,
        });
      });
    }
  };

  /* ── Afbeelding controls ────────────────────────────────────── */

  D._bindImageEditorControls = function (el) {
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
          tabUnsplash.style.display = "block";
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

        // Progress UI
        unResults.innerHTML = `
          <div class="aisb-ep-progress-wrap">
            <div class="aisb-ep-progress-track"><div class="aisb-ep-progress-bar" id="aisb-ep-un-bar"></div></div>
            <div class="aisb-ep-progress-label"><span id="aisb-ep-un-msg">Zoeken op Unsplash…</span><span id="aisb-ep-un-pct">0%</span></div>
          </div>`;
        let pct = 0;
        const bar = document.getElementById("aisb-ep-un-bar");
        const pctEl = document.getElementById("aisb-ep-un-pct");
        const tick = setInterval(() => {
          pct = Math.min(pct + (pct < 60 ? 8 : pct < 80 ? 3 : 1), 85);
          if (bar) bar.style.width = pct + "%";
          if (pctEl) pctEl.textContent = pct + "%";
        }, 200);

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
            clearInterval(tick);
            if (bar) bar.style.width = "100%";
            if (pctEl) pctEl.textContent = "100%";
            if (!out || !out.success || !out.data || !out.data.images) {
              unResults.innerHTML =
                '<div class="aisb-ep-unsplash-msg aisb-ep-unsplash-msg--error">Fout bij zoeken.</div>';
              return;
            }

            const items = out.data.images;
            if (!items.length) {
              unResults.innerHTML =
                '<div class="aisb-ep-unsplash-msg">Geen resultaten.</div>';
              return;
            }

            unResults.innerHTML =
              items
                .map((img) => {
                  if (!img.thumb) return "";
                  return `<div class="aisb-ep-unsplash-item" title="${img.alt ? img.alt.replace(/"/g, "&quot;") : "Unsplash image"}">
                  <img src="${img.thumb}" data-full="${img.full}" />
                </div>`;
                })
                .join("") +
              `<button class="aisb-ep-upload-btn aisb-ep-unsplash-more-btn" id="aisb-ep-unsplash-more">Meer laden...</button>`;

            const moreBtn = document.getElementById("aisb-ep-unsplash-more");
            if (moreBtn) {
              moreBtn.addEventListener("click", () => {
                D.openUnsplashPicker(q, el);
              });
            }
          })
          .catch(() => {
            clearInterval(tick);
            unResults.innerHTML =
              '<div class="aisb-ep-unsplash-msg aisb-ep-unsplash-msg--error">Fout bij communicatie met server.</div>';
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
          D._registerEdit(D._selectedIframe, "img", el, { src: fullUrl });
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
    const uploadZone = document.getElementById("aisb-ep-upload-label");
    const srcInput = document.getElementById("aisb-ep-src");

    // Drag-over styling
    if (uploadZone) {
      uploadZone.addEventListener("dragover", (e) => {
        e.preventDefault();
        uploadZone.classList.add("is-dragover");
      });
      uploadZone.addEventListener("dragleave", () =>
        uploadZone.classList.remove("is-dragover"),
      );
      uploadZone.addEventListener("drop", (e) => {
        e.preventDefault();
        uploadZone.classList.remove("is-dragover");
        const file = e.dataTransfer.files && e.dataTransfer.files[0];
        if (file) doUpload(file);
      });
    }

    const doUpload = (file) => {
      if (!file) return;

      // Progress UI
      uploadStatus.innerHTML = `
          <div class="aisb-ep-progress-wrap">
            <div class="aisb-ep-progress-track"><div class="aisb-ep-progress-bar" id="aisb-ep-ul-bar"></div></div>
            <div class="aisb-ep-progress-label"><span id="aisb-ep-ul-msg">Uploading…</span><span id="aisb-ep-ul-pct">0%</span></div>
          </div>`;

      const xhr = new XMLHttpRequest();
      xhr.open("POST", AISB_DESIGN.ajaxUrl);

      xhr.upload.addEventListener("progress", (e) => {
        if (!e.lengthComputable) return;
        const pct = Math.round((e.loaded / e.total) * 100);
        const bar = document.getElementById("aisb-ep-ul-bar");
        const pctEl = document.getElementById("aisb-ep-ul-pct");
        if (bar) bar.style.width = pct + "%";
        if (pctEl) pctEl.textContent = pct + "%";
      });

      xhr.addEventListener("load", () => {
        const bar = document.getElementById("aisb-ep-ul-bar");
        const pctEl = document.getElementById("aisb-ep-ul-pct");
        const msgEl = document.getElementById("aisb-ep-ul-msg");
        if (bar) bar.style.width = "100%";
        if (pctEl) pctEl.textContent = "100%";
        let out;
        try {
          out = JSON.parse(xhr.responseText);
        } catch (_) {}
        if (!out || !out.success || !out.data.images || !out.data.images[0]) {
          if (msgEl) msgEl.textContent = "Upload mislukt.";
          uploadStatus.style.color = "#f87171";
          return;
        }
        const url = out.data.images[0].full || out.data.images[0].thumb || "";
        el.src = url;
        el.srcset = "";
        el.style.objectFit = "cover";
        D._registerEdit(D._selectedIframe, "img", el, { src: url });
        if (srcInput) srcInput.value = url;
        if (msgEl) msgEl.textContent = "Afbeelding geüpload ✓";
        uploadInput.value = "";
        setTimeout(() => {
          uploadStatus.innerHTML = "";
        }, 2500);
      });

      xhr.addEventListener("error", () => {
        uploadStatus.textContent = "Upload mislukt.";
      });

      const fd = new FormData();
      fd.append("action", "aisb_upload_images");
      fd.append("nonce", AISB_DESIGN.nonce);
      fd.append("images[]", file);
      xhr.send(fd);
    };

    if (uploadInput) {
      uploadInput.addEventListener("change", () => {
        const file = uploadInput.files && uploadInput.files[0];
        if (file) doUpload(file);
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

    D._bindSharedEditorControls(el);
  };

  /* ── Algemeen controls ──────────────────────────────────────── */

  D._bindGenericEditorControls = function (el) {
    const bgInput = document.getElementById("aisb-ep-bg");
    const bgVal = D._editorPanel.querySelector(".aisb-ep-color-val");
    bgInput.addEventListener("input", () => {
      el.style.setProperty("background-color", bgInput.value, "important");
      bgVal.textContent = bgInput.value;
      D._registerEdit(D._selectedIframe, "css", el, {
        prop: "background-color",
        value: bgInput.value,
      });
    });

    D._bindSharedEditorControls(el);
  };

  /* ── Gedeelde controls (radius + opacity) ───────────────────── */

  D._bindSharedEditorControls = function (el) {
    const radiusRange = document.getElementById("aisb-ep-radius");
    const radiusNum = document.getElementById("aisb-ep-radius-num");
    if (radiusRange) {
      radiusRange.addEventListener("input", () => {
        if (radiusNum) radiusNum.value = radiusRange.value;
        el.style.setProperty(
          "border-radius",
          radiusRange.value + "px",
          "important",
        );
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "border-radius",
          value: radiusRange.value + "px",
        });
      });
      if (radiusNum) {
        radiusNum.addEventListener("input", () => {
          radiusRange.value = radiusNum.value;
          el.style.setProperty(
            "border-radius",
            radiusNum.value + "px",
            "important",
          );
          D._registerEdit(D._selectedIframe, "css", el, {
            prop: "border-radius",
            value: radiusNum.value + "px",
          });
        });
      }
    }

    const opacityRange = document.getElementById("aisb-ep-opacity");
    const opacityNum = document.getElementById("aisb-ep-opacity-num");
    if (opacityRange) {
      opacityRange.addEventListener("input", () => {
        if (opacityNum)
          opacityNum.value = Math.round(parseFloat(opacityRange.value) * 100);
        el.style.setProperty("opacity", opacityRange.value, "important");
        D._registerEdit(D._selectedIframe, "css", el, {
          prop: "opacity",
          value: opacityRange.value,
        });
      });
      if (opacityNum) {
        opacityNum.addEventListener("input", () => {
          const val =
            Math.min(100, Math.max(0, parseInt(opacityNum.value) || 0)) / 100;
          opacityRange.value = val;
          el.style.setProperty("opacity", String(val), "important");
          D._registerEdit(D._selectedIframe, "css", el, {
            prop: "opacity",
            value: String(val),
          });
        });
      }
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
      modal.className = "aisb-add-section-modal";
      document.body.appendChild(modal);

      modal.addEventListener("click", (e) => {
        if (e.target === modal) modal.style.display = "none";
      });
    }

    modal.style.display = "flex";
    modal.innerHTML = `
      <div class="aisb-add-section-modal__inner">
        <div class="aisb-ep-header aisb-add-section-modal__header">
          <span class="aisb-ep-title">Sectie toevoegen</span>
          <button class="aisb-ep-close" id="aisb-add-sec-close">✕</button>
        </div>
        <div class="aisb-add-section-modal__toolbar">
          <input type="text" class="aisb-ep-input" id="aisb-add-sec-search"
            placeholder="Zoek template…">
        </div>
        <div id="aisb-add-sec-grid" class="aisb-add-section-modal__grid">
          <div class="aisb-add-section-modal__msg">
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
          '<div class="aisb-add-section-modal__msg aisb-add-section-modal__msg--muted">Geen templates gevonden.</div>';
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
              style="min-height:${previewDisplayH + 64}px;">
              <div class="aisb-ep-tpl-preview"
                style="height:${previewDisplayH}px;min-height:${previewDisplayH}px;background:#fff;"></div>
              <div class="aisb-ep-tpl-card__footer">
                <div class="aisb-ep-tpl-card__title">
                  ${D.escapeHtml(t.title)}
                </div>
                <div class="aisb-ep-tpl-card__meta">
                  ${
                    tag
                      ? `<span class="aisb-ep-tpl-card__tag">${D.escapeHtml(tag)}</span>`
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
                  `transform:scale(${scale});` +
                  `transform-origin:0 0;` +
                  `pointer-events:none;display:block;position:absolute;top:0;left:0;`,
              );
              // Na load: meet werkelijke sectie-hoogte en pas de
              // preview-container daarop aan, anders krijg je een
              // grote witruimte onder korte secties (forms, footers…).
              pif.addEventListener("load", () => {
                try {
                  const doc = pif.contentDocument;
                  if (!doc) return;
                  const realH =
                    doc.documentElement.scrollHeight || doc.body.scrollHeight;
                  if (!realH) return;
                  // Beperk tot previewDisplayH (lange secties croppen),
                  // anders precies passend zodat geen witruimte overblijft.
                  const targetH = Math.min(
                    Math.ceil(realH * scale) + 2,
                    previewDisplayH,
                  );
                  pw.style.height = targetH + "px";
                  pw.style.minHeight = targetH + "px";
                  card.style.minHeight = targetH + 64 + "px";
                  // iframe pas aansnijden op de echte hoogte zodat
                  // er ook niet binnen het iframe extra witruimte zit.
                  pif.style.height = realH + "px";
                } catch (_) {
                  /* cross-origin: laat het op default staan */
                }
              });
              pw.appendChild(pif);
            });
          });
        },
        { root: grid, rootMargin: "300px" },
      );

      grid.querySelectorAll(".aisb-ep-tpl-card").forEach((card) => {
        io.observe(card);
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
            '<div class="aisb-add-section-modal__msg aisb-add-section-modal__msg--error">Netwerkfout.</div>';
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
    notify.className = "aisb-design-notify";
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
      notify.classList.add("aisb-design-notify--success");
      setTimeout(() => notify.remove(), 2200);
    } catch (err) {
      console.error("[AISB] insert section error:", err);
      notify.textContent = "⚠ Mislukt: " + (err.message || "Onbekend");
      notify.classList.add("aisb-design-notify--error");
      setTimeout(() => notify.remove(), 3500);
    }
  };

  /* ── Maakt de nieuwe iframe-wrap aan en voegt hem in na afterWrap ── */

  D._insertSectionAfterWrap = function (afterWrap, section, page, pageBody) {
    const postId = section.ai_wireframe_id || section.bricks_template_id;
    if (!postId) return;

    const wrap = document.createElement("div");
    wrap.className = "aisb-design-iframe-wrap";
    wrap.dataset.uuid = section.uuid || "";
    wrap.dataset.pageSlug = page ? page.slug || "" : "";
    wrap.dataset.sitemapVersionId = String(
      page ? page.sitemap_version_id || 0 : 0,
    );

    const iframe = document.createElement("iframe");
    iframe.src =
      ((window.AISB_DESIGN && AISB_DESIGN.previewUrl) || "") + postId;
    iframe.className = "aisb-design-iframe";
    iframe.scrolling = "yes";
    iframe._loaded = false;
    iframe._pageSlug = page ? page.slug : "";
    iframe._sitemapVersionId = page ? page.sitemap_version_id || 0 : 0;
    iframe._sectionIdx = D.allIframes.length;
    iframe._bgIndex =
      typeof section.bg_index === "number"
        ? section.bg_index
        : iframe._sectionIdx % 2;
    section.bg_index = iframe._bgIndex;
    iframe._localSectionIdx =
      page && page.sections ? page.sections.length - 1 : 0;
    iframe._sectionType = section.type || "";
    iframe._sectionPostId = postId;
    iframe._sectionData = section;
    iframe.dataset.aisbSection = "1";

    iframe.addEventListener("load", () => {
      iframe._loaded = true;
      if (D.injectStyleGuide) D.injectStyleGuide(iframe);
      if (D.injectSectionImages) D.injectSectionImages(iframe);
      if (D.applyStoredEdits) D.applyStoredEdits(iframe);
      try {
        const h = iframe.contentDocument.documentElement.scrollHeight || 400;
        iframe.style.height = h + "px";
        wrap.style.height = h + "px";
      } catch (e) {
        iframe.style.height = "500px";
        wrap.style.height = "500px";
      }
      if (D._enableSectionInteractivity) D._enableSectionInteractivity(iframe);
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

    // Drag handle voor herordenen
    if (D._initSectionDragDrop) {
      const dragHandle = document.createElement("button");
      dragHandle.className = "aisb-section-drag-handle";
      dragHandle.type = "button";
      dragHandle.title = "Versleep om volgorde te wijzigen";
      dragHandle.draggable = true;
      dragHandle.innerHTML =
        '<svg width="14" height="18" viewBox="0 0 14 18" fill="currentColor" aria-hidden="true">' +
        '<circle cx="4" cy="3" r="1.6"/><circle cx="10" cy="3" r="1.6"/>' +
        '<circle cx="4" cy="9" r="1.6"/><circle cx="10" cy="9" r="1.6"/>' +
        '<circle cx="4" cy="15" r="1.6"/><circle cx="10" cy="15" r="1.6"/>' +
        "</svg>";
      wrap.appendChild(dragHandle);
      D._initSectionDragDrop(wrap, dragHandle, pageBody, page);
    }

    // Invoegen na afterWrap
    if (afterWrap && afterWrap.parentNode === pageBody) {
      afterWrap.after(wrap);
    } else if (pageBody) {
      pageBody.appendChild(wrap);
    }

    D.allIframes.push(iframe);
  };
})();
