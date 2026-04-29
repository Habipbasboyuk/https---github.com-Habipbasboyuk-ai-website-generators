/**
 * design/canvas.js — Figma-stijl infinite canvas: pagina's naast elkaar,
 * secties per pagina gestapeld in iframes.
 *
 * Verantwoordelijk voor:
 *   - buildCanvas()    — bouwt de DOM-structuur en maakt de iframes aan
 *   - setupPanZoom()   — pan (slepen) + zoom (Ctrl+scroll) + dubbelklik om te fitten
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  /* ── Canvas opbouwen ────────────────────────────────────────── */

  D.buildCanvas = function () {
    const canvasEl = D.canvasEl;
    canvasEl.innerHTML = "";

    if (!D.wireframePages.length) {
      canvasEl.innerHTML =
        '<div class="aisb-design-empty">No wireframes found. Generate wireframes in Step 2 first.</div>';
      return;
    }

    // 3-laags structuur: canvas > inner (transform-doel) > pages-grid
    const inner = document.createElement("div");
    inner.className = "aisb-design-inner";
    canvasEl.appendChild(inner);

    const grid = document.createElement("div");
    grid.className = "aisb-design-grid";
    inner.appendChild(grid);

    // Globale sectieteller zodat afwisselende achtergronden over pagina's doorlopen
    let globalSectionIdx = 0;

    D.wireframePages.forEach((page) => {
      const card = document.createElement("div");
      card.className = "aisb-design-page-card";

      // Kaart-header (paginanaam + aantal secties)
      const head = document.createElement("div");
      head.className = "aisb-design-page-head";
      head.innerHTML =
        '<span class="aisb-design-page-title">' +
        D.escapeHtml(page.title || page.slug) +
        "</span>" +
        '<span class="aisb-design-page-badge">' +
        (page.sections ? page.sections.length : 0) +
        " sections</span>";
      card.appendChild(head);

      // Kaart-body: gestapelde iframes
      const body = document.createElement("div");
      body.className = "aisb-design-page-body";

      (page.sections || []).forEach((section, sIdx) => {
        const postId = section.ai_wireframe_id || section.bricks_template_id;
        if (!postId) return;

        const wrap = document.createElement("div");
        wrap.className = "aisb-design-iframe-wrap";

        const iframe = document.createElement("iframe");
        iframe.src = (AISB_DESIGN.previewUrl || "") + postId;
        iframe.className = "aisb-design-iframe";
        // scrolling="yes" zodat de gebruiker door de sectie-inhoud kan scrollen
        iframe.scrolling = "yes";
        iframe._loaded = false;
        iframe._pageSlug = page.slug;
        iframe._sitemapVersionId = page.sitemap_version_id || 0;
        iframe._sectionIdx = globalSectionIdx++; // globale teller voor afwisselende achtergronden
        iframe._localSectionIdx = sIdx; // per-pagina index voor de afbeeldingskaart
        iframe._sectionType = section.type || ""; // hero, features, footer, ...
        iframe._sectionPostId = postId; // huidige template/wireframe id
        iframe._sectionData = section; // ruwe sectie data voor evt. herstel
        iframe.dataset.aisbSection = "1";

        iframe.addEventListener("load", () => {
          iframe._loaded = true;
          D.injectOverride(iframe);
          D.injectImages(iframe);
          // Pas eerder opgeslagen design-patches toe (tekst/stijl/afbeelding/spiegel)
          if (D.applyPatch) D.applyPatch(iframe);
          // Iframe hoogte aanpassen aan volledige inhoudshoogte
          try {
            const h =
              iframe.contentDocument.documentElement.scrollHeight || 400;
            iframe.style.height = h + "px";
            wrap.style.height = h + "px";
          } catch (e) {
            iframe.style.height = "500px";
            wrap.style.height = "500px";
          }

          // Injecteer CSS :hover-highlight en klik/wheel listeners.
          try {
            D._setupIframeInteractivity(iframe);
          } catch (err) {
            /* cross-origin – skip */
          }
        });

        wrap.appendChild(iframe);

        // ➕ Sectie toevoegen knop (Relume-stijl, zichtbaar bij hover)
        const addBtn = document.createElement("button");
        addBtn.className = "aisb-add-section-btn";
        addBtn.type = "button";
        addBtn.innerHTML = "<span>+</span> Sectie";
        addBtn.addEventListener("click", (e) => {
          e.stopPropagation();
          if (D.openAddSectionModal) D.openAddSectionModal(iframe, body, page);
        });
        wrap.appendChild(addBtn);

        body.appendChild(wrap);
        D.allIframes.push(iframe);
      });

      card.appendChild(body);
      grid.appendChild(card);
    });

    D.setupPanZoom(canvasEl, inner);
  };

  /* ── Iframe interactiviteit instellen ──────────────────────── */

  D._setupIframeInteractivity = function (iframe) {
    try {
      const doc = iframe.contentDocument;
      if (!doc || !doc.head) return;
      let hoverStyle = doc.getElementById("aisb-hover-style");
      if (!hoverStyle) {
        hoverStyle = doc.createElement("style");
        hoverStyle.id = "aisb-hover-style";
        doc.head.appendChild(hoverStyle);
      }
      hoverStyle.textContent =
        "*:not(html):not(body){pointer-events:auto !important;}" +
        "*:hover:not(html):not(body):not(:has(*:hover)){outline:6px solid #118cf0 !important;" +
        "outline-offset:-2px !important; transition: 0.2s ease-in-out !important;}";

      doc.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const el = e.target;
        if (!el) return;
        const tag = (el.tagName || "").toLowerCase();
        const cls = String(el.className || "");
        const isSectionRoot =
          tag === "section" ||
          /\bbrxe-section\b/.test(cls) ||
          el === doc.body ||
          el === doc.documentElement;
        if (e.shiftKey || isSectionRoot) {
          if (D.selectSection) D.selectSection(iframe, doc);
          return;
        }
        if (D.selectElement) D.selectElement(el, doc, iframe);
      });

      doc.addEventListener(
        "wheel",
        (e) => {
          e.preventDefault();
          const iframeRect = iframe.getBoundingClientRect();
          D.canvasEl.dispatchEvent(
            new WheelEvent("wheel", {
              bubbles: true,
              cancelable: true,
              deltaX: e.deltaX,
              deltaY: e.deltaY,
              deltaZ: e.deltaZ,
              deltaMode: e.deltaMode,
              ctrlKey: e.ctrlKey,
              shiftKey: e.shiftKey,
              clientX: iframeRect.left + e.clientX,
              clientY: iframeRect.top + e.clientY,
            }),
          );
        },
        { passive: false },
      );
    } catch (err) {
      /* cross-origin – skip */
    }
  };

  /* ── Pan / zoom ─────────────────────────────────────────────── */

  D.setupPanZoom = function (canvas, inner) {
    const state = {
      translateX: 40,
      translateY: 40,
      scale: 1,
      isPanning: false,
      panStart: null,
    };
    canvas._designState = state;

    function applyTransform() {
      inner.style.transform =
        "translate(" +
        state.translateX +
        "px," +
        state.translateY +
        "px) scale(" +
        state.scale +
        ")";
    }

    function fitToView() {
      const canvasRect = canvas.getBoundingClientRect();
      if (!canvasRect.width || !canvasRect.height) return;
      const cards = inner.querySelectorAll(".aisb-design-page-card");
      if (!cards.length) return;
      let minX = Infinity,
        minY = Infinity,
        maxX = -Infinity,
        maxY = -Infinity;
      for (const card of cards) {
        minX = Math.min(minX, card.offsetLeft);
        minY = Math.min(minY, card.offsetTop);
        maxX = Math.max(maxX, card.offsetLeft + card.offsetWidth);
        maxY = Math.max(maxY, card.offsetTop + card.offsetHeight);
      }
      const contentWidth = maxX - minX;
      const contentHeight = maxY - minY;
      if (!contentWidth || !contentHeight) return;
      const padding = 40;
      const scaleX = (canvasRect.width - padding * 2) / contentWidth;
      const scaleY = (canvasRect.height - padding * 2) / contentHeight;
      state.scale = D.clamp(Math.min(scaleX, scaleY), 0.05, 1);
      state.translateX =
        (canvasRect.width - contentWidth * state.scale) / 2 -
        minX * state.scale;
      state.translateY = padding;
      applyTransform();
    }

    canvas._designFitToView = fitToView;
    requestAnimationFrame(fitToView);

    function setIframePointerEvents(value) {
      for (const iframe of D.allIframes) {
        iframe.style.pointerEvents = value;
      }
    }

    // Pan — tijdens slepen: iframes op pointer-events:none zodat
    // mousemove-events de canvas bereiken in plaats van de iframe.
    canvas.addEventListener("mousedown", (e) => {
      if (e.target.closest(".aisb-design-page-head")) return;
      state.isPanning = true;
      state.panStart = {
        x: e.clientX,
        y: e.clientY,
        tx: state.translateX,
        ty: state.translateY,
      };
      setIframePointerEvents("none");
      canvas.classList.add("is-panning");
      e.preventDefault();
    });

    canvas.addEventListener("mousemove", (e) => {
      if (!state.isPanning) return;
      state.translateX = state.panStart.tx + (e.clientX - state.panStart.x);
      state.translateY = state.panStart.ty + (e.clientY - state.panStart.y);
      applyTransform();
    });

    canvas.addEventListener("mouseleave", () => {
      if (!state.isPanning) return;
      state.isPanning = false;
      setIframePointerEvents("auto");
      canvas.classList.remove("is-panning");
    });

    window.addEventListener("mouseup", () => {
      if (!state.isPanning) return;
      state.isPanning = false;
      setIframePointerEvents("auto");
      canvas.classList.remove("is-panning");
    });

    // Zoom — Ctrl + scroll
    canvas.addEventListener(
      "wheel",
      (e) => {
        if (e.ctrlKey) {
          e.preventDefault();
          const rect = canvas.getBoundingClientRect();
          const cx = e.clientX - rect.left;
          const cy = e.clientY - rect.top;
          const prev = state.scale;
          const next = D.clamp(prev * (1 - e.deltaY * 0.001), 0.05, 3);
          if (Math.abs(next - prev) < 0.0001) return;
          state.translateX = cx - (cx - state.translateX) * (next / prev);
          state.translateY = cy - (cy - state.translateY) * (next / prev);
          state.scale = next;
          applyTransform();
        } else {
          // Normaal scrollen → canvas pannen zonder positie te resetten
          e.preventDefault();
          state.translateX -= e.deltaX;
          state.translateY -= e.deltaY;
          applyTransform();
        }
      },
      { passive: false },
    );

    // Dubbelklik op leeg gebied → alles in beeld passen
    canvas.addEventListener("dblclick", (e) => {
      if (e.target.closest(".aisb-design-page-card")) return;
      fitToView();
    });
  };
})();
