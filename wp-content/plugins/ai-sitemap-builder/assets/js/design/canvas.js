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
        iframe._sectionIdx = globalSectionIdx++; // globale teller voor afwisselende achtergronden
        iframe._localSectionIdx = sIdx; // per-pagina index voor de afbeeldingskaart

        iframe.addEventListener("load", () => {
          iframe._loaded = true;
          D.injectOverride(iframe);
          D.injectImages(iframe);
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
        });

        wrap.appendChild(iframe);
        body.appendChild(wrap);
        D.allIframes.push(iframe);
      });

      card.appendChild(body);
      grid.appendChild(card);
    });

    D.setupPanZoom(canvasEl, inner);
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

    // Pan — sleep overal op de canvas (iframes zijn pointer-events:none)
    canvas.addEventListener("mousedown", (e) => {
      if (e.target.closest(".aisb-design-page-head")) return;
      state.isPanning = true;
      state.panStart = {
        x: e.clientX,
        y: e.clientY,
        tx: state.translateX,
        ty: state.translateY,
      };
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
      canvas.classList.remove("is-panning");
    });

    window.addEventListener("mouseup", () => {
      if (!state.isPanning) return;
      state.isPanning = false;
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
          // Normaal scrollen → canvas pannen
          const newTX = state.translateX - e.deltaX;
          const newTY = state.translateY - e.deltaY;
          const contentW = inner.scrollWidth * state.scale;
          const contentH = inner.scrollHeight * state.scale;
          const viewW = canvas.getBoundingClientRect().width;
          const viewH = canvas.getBoundingClientRect().height;
          const clampedTX = D.clamp(newTX, Math.min(40, viewW - contentW), 40);
          const clampedTY = D.clamp(newTY, Math.min(40, viewH - contentH), 40);
          if (
            Math.abs(clampedTX - state.translateX) < 0.5 &&
            Math.abs(clampedTY - state.translateY) < 0.5
          )
            return;
          e.preventDefault();
          state.translateX = clampedTX;
          state.translateY = clampedTY;
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
