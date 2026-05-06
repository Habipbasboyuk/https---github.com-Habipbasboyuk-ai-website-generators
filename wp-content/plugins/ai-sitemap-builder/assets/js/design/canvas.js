/**
 * design/canvas.js — Figma-stijl infinite canvas: pagina's naast elkaar,
 * secties per pagina gestapeld in iframes.
 *
 * Verantwoordelijk voor:
 *   - buildDesignCanvas()  — bouwt de DOM-structuur en maakt de iframes aan
 *   - initCanvasPanZoom()  — pan (slepen) + zoom (Ctrl+scroll) + dubbelklik om te fitten
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  /* ── Canvas opbouwen ────────────────────────────────────────── */

  D.buildDesignCanvas = function () {
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
        wrap.dataset.uuid = section.uuid || "";
        wrap.dataset.pageSlug = page.slug || "";
        wrap.dataset.sitemapVersionId = String(page.sitemap_version_id || 0);

        const iframe = document.createElement("iframe");
        iframe.src = (AISB_DESIGN.previewUrl || "") + postId;
        iframe.className = "aisb-design-iframe";
        // scrolling="yes" zodat de gebruiker door de sectie-inhoud kan scrollen
        iframe.scrolling = "yes";
        iframe._loaded = false;
        iframe._pageSlug = page.slug;
        iframe._sitemapVersionId = page.sitemap_version_id || 0;
        iframe._sectionIdx = globalSectionIdx++; // globale teller voor afwisselende achtergronden
        // Bevroren bg_index uit wireframe-model (overleeft herordenen).
        // Valt terug op huidig idx % 2 als nog niet opgeslagen.
        iframe._bgIndex =
          typeof section.bg_index === "number"
            ? section.bg_index
            : iframe._sectionIdx % 2;
        section.bg_index = iframe._bgIndex;
        iframe._localSectionIdx = sIdx; // per-pagina index voor de afbeeldingskaart
        iframe._sectionType = section.type || ""; // hero, features, footer, ...
        iframe._sectionPostId = postId; // huidige template/wireframe id
        iframe._sectionData = section; // ruwe sectie data voor evt. herstel
        iframe.dataset.aisbSection = "1";

        iframe.addEventListener("load", () => {
          iframe._loaded = true;
          D.injectStyleGuide(iframe);
          D.injectSectionImages(iframe);
          // Vervang nav/footer menu-items door de echte paginanamen
          if (D.injectNavMenuLinks) D.injectNavMenuLinks(iframe);
          // Pas eerder opgeslagen design-patches toe (tekst/stijl/afbeelding/spiegel)
          if (D.applyStoredEdits) D.applyStoredEdits(iframe);

          // Iframe hoogte aanpassen aan volledige inhoudshoogte.
          // We meten meerdere keren omdat Bricks async rendert — anders
          // krijgen we de hoogte van het loading-skeleton ipv de echte sectie.
          const fitHeight = () => {
            try {
              const doc = iframe.contentDocument;
              if (!doc || !doc.body) return;
              // Forceer iframe even kort tot 0 zodat documentElement.scrollHeight
              // de echte content-hoogte teruggeeft (en niet de huidige iframe-hoogte).
              const prev = iframe.style.height;
              iframe.style.height = "0px";
              const h =
                doc.documentElement.scrollHeight || doc.body.scrollHeight || 0;
              if (h > 0) {
                iframe.style.height = h + "px";
                wrap.style.height = h + "px";
              } else {
                iframe.style.height = prev || "500px";
              }
            } catch (e) {
              iframe.style.height = "500px";
              wrap.style.height = "500px";
            }
          };
          fitHeight();
          iframe._fitHeight = fitHeight;
          setTimeout(fitHeight, 200);
          setTimeout(fitHeight, 600);
          setTimeout(fitHeight, 1200);

          // Injecteer CSS :hover-highlight en klik/wheel listeners.
          try {
            D._enableSectionInteractivity(iframe);
          } catch (err) {
            /* cross-origin – skip */
          }
        });

        wrap.appendChild(iframe);

        // ⋮⋮ Drag handle (Relume-stijl, zichtbaar bij hover)
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
        dragHandle.addEventListener("click", (e) => {
          e.stopPropagation();
          const doc = iframe.contentDocument || null;
          if (D.openSectionEditor) D.openSectionEditor(iframe, doc);
        });
        wrap.appendChild(dragHandle);
        D._initSectionDragDrop(wrap, dragHandle, body, page);

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

    D.initCanvasPanZoom(canvasEl, inner);
  };

  /* ── Iframe interactiviteit instellen ──────────────────────── */

  D._enableSectionInteractivity = function (iframe) {
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
        ".brxe-container:hover,.brxe-block:hover,.brxe-div:hover{outline:none !important;}" +
        "*:hover:not(html):not(body):not(.brxe-container):not(.brxe-block):not(.brxe-div):not(:has(*:hover)){outline:6px solid #118cf0 !important;" +
        "outline-offset:-2px !important; transition: 0.2s ease-in-out !important;}" +
        "[contenteditable='true']{outline:3px solid #118cf0 !important;outline-offset:2px !important;cursor:text !important;}" +
        /* Verberg de lightbox-zoomknop van Bricks/WP-gallery op hover */
        ".wp-lightbox-container button{display:none !important;}" +
        /* Vervang zoom-in cursor door gewone pointer op alle afbeeldingen */
        "img{cursor:pointer !important;}" +
        /* Afbeeldingen altijd 100% breedte/hoogte van hun vak, object-cover */
        "img:not([data-aisb-logo='1']){width:100% !important;height:100% !important;object-fit:cover !important;display:block !important;}";

      // ── Hulpfunctie: is dit een tekstelement? ───────────────
      const TEXT_TAGS = new Set([
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
      ]);
      const isBricksWrapper = (node) => {
        const c = String(node.className || "");
        return (
          /\bbrxe-container\b/.test(c) ||
          /\bbrxe-block\b/.test(c) ||
          /\bbrxe-div\b/.test(c)
        );
      };
      const isTextEl = (node) => {
        const t = (node.tagName || "").toLowerCase();
        return (
          TEXT_TAGS.has(t) ||
          (!["img", "iframe", "video", "canvas", "svg"].includes(t) &&
            node.childElementCount === 0)
        );
      };

      // ── Inline tekst bewerken (dubbelklik) ──────────────────
      let _activeEditable = null;

      const stopInlineEdit = () => {
        if (!_activeEditable) return;
        const node = _activeEditable;
        _activeEditable = null;
        node.removeAttribute("contenteditable");
        node.removeEventListener("input", node._aisbInputHandler);
        node.removeEventListener("keydown", node._aisbKeydownHandler);
        node.removeEventListener("blur", node._aisbBlurHandler);
        delete node._aisbInputHandler;
        delete node._aisbKeydownHandler;
        delete node._aisbBlurHandler;
        // Sync patch with final text
        D._registerEdit(iframe, "text", node, {
          text: node.innerText || node.textContent || "",
        });
        // Sync editor panel textarea if open
        const ta = document.getElementById("aisb-ep-text");
        if (ta) ta.value = node.innerText || node.textContent || "";
      };

      doc.addEventListener("dblclick", (e) => {
        let el = e.target;
        if (!el) return;
        // Skip wrappers
        while (
          isBricksWrapper(el) &&
          el.parentElement &&
          el.parentElement !== doc.body
        ) {
          el = el.parentElement;
        }
        const tag = (el.tagName || "").toLowerCase();
        const cls = String(el.className || "");
        const isSectionRoot =
          tag === "section" ||
          /\bbrxe-section\b/.test(cls) ||
          el === doc.body ||
          el === doc.documentElement;
        if (isSectionRoot || tag === "img") return;
        if (!isTextEl(el)) return;

        e.preventDefault();
        e.stopPropagation();

        // Stop vorige inline edit
        if (_activeEditable && _activeEditable !== el) stopInlineEdit();

        // Activeer contenteditable
        el.setAttribute("contenteditable", "true");
        el.focus();
        _activeEditable = el;

        // Zet cursor aan het einde
        try {
          const range = doc.createRange();
          const sel = doc.defaultView.getSelection();
          range.selectNodeContents(el);
          range.collapse(false);
          sel.removeAllRanges();
          sel.addRange(range);
        } catch (_) {}

        // Open editor panel zodat de textarea ook meesynct
        if (D.openElementEditor) D.openElementEditor(el, doc, iframe);

        // Live sync naar editor panel textarea
        el._aisbInputHandler = () => {
          const ta = document.getElementById("aisb-ep-text");
          if (ta) ta.value = el.innerText || el.textContent || "";
          D._registerEdit(iframe, "text", el, {
            text: el.innerText || el.textContent || "",
          });
        };
        el.addEventListener("input", el._aisbInputHandler);

        // Escape = stop bewerken
        el._aisbKeydownHandler = (ev) => {
          if (ev.key === "Escape") {
            ev.preventDefault();
            stopInlineEdit();
          }
        };
        el.addEventListener("keydown", el._aisbKeydownHandler);

        // Blur = stop bewerken
        el._aisbBlurHandler = () => {
          // Kleine vertraging: klik op editor panel mag de blur niet annuleren
          setTimeout(() => {
            if (_activeEditable === el) stopInlineEdit();
          }, 150);
        };
        el.addEventListener("blur", el._aisbBlurHandler);
      });

      doc.addEventListener("click", (e) => {
        // Als er een inline edit actief is laat klikken gewoon door
        if (_activeEditable) return;

        // Laat native <details>/<summary> en Bricks accordion-toggles door —
        // anders klappen FAQ/accordion-items nooit uit.
        const tgt = e.target;
        const tgtTag = (tgt.tagName || "").toLowerCase();
        const isAccordionToggle =
          tgtTag === "summary" ||
          !!tgt.closest("details > summary") ||
          !!tgt.closest(".brxe-accordion-item-title") ||
          !!tgt.closest(".brxe-accordion-toggle") ||
          !!tgt.closest("[data-brx-accordion]");

        if (!isAccordionToggle) {
          e.preventDefault();
          e.stopPropagation();
        }

        // Herbereken iframe-hoogte na elke klik (accordion uitklappen, etc.)
        setTimeout(() => {
          if (iframe._fitHeight) iframe._fitHeight();
        }, 400);
        setTimeout(() => {
          if (iframe._fitHeight) iframe._fitHeight();
        }, 800);

        if (isAccordionToggle) return;

        let el = tgt;

        // Walk up past Bricks layout wrappers
        while (
          isBricksWrapper(el) &&
          el.parentElement &&
          el.parentElement !== doc.body
        ) {
          el = el.parentElement;
        }

        const tag = (el.tagName || "").toLowerCase();
        const cls = String(el.className || "");
        const isSectionRoot =
          tag === "section" ||
          /\bbrxe-section\b/.test(cls) ||
          el === doc.body ||
          el === doc.documentElement;
        if (e.shiftKey || isSectionRoot) {
          if (D.openSectionEditor) D.openSectionEditor(iframe, doc);
          return;
        }
        if (D.openElementEditor) D.openElementEditor(el, doc, iframe);
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

      // Verberg de "+ sectie toevoegen" knop wanneer de muis BINNEN het
      // iframe-content gebied is, behalve in de onderste 40px (= "rand"
      // van de sectie). Zo verschijnt de knop alleen als je richting de
      // grens tussen twee secties beweegt, niet midden in de sectie.
      const wrap = iframe.parentElement;
      if (wrap) {
        const EDGE_PX = 40;
        doc.addEventListener("mouseenter", () => {
          wrap.classList.add("aisb-iframe-active");
        });
        doc.addEventListener("mouseleave", () => {
          wrap.classList.remove("aisb-iframe-active");
          wrap.classList.remove("aisb-edge-bottom");
        });
        doc.addEventListener("mousemove", (e) => {
          const h =
            doc.documentElement.clientHeight ||
            doc.body.clientHeight ||
            iframe.clientHeight ||
            0;
          if (h && e.clientY >= h - EDGE_PX) {
            wrap.classList.add("aisb-edge-bottom");
          } else {
            wrap.classList.remove("aisb-edge-bottom");
          }
        });
      }

      // MutationObserver: herbereken hoogte bij DOM-wijzigingen in iframe
      // (Bricks accordion opent/sluit via class-toggle of DOM-insertie)
      if (doc.body && typeof MutationObserver !== "undefined") {
        const mo = new MutationObserver(() => {
          if (iframe._fitHeight) iframe._fitHeight();
        });
        mo.observe(doc.body, {
          childList: true,
          subtree: true,
          attributes: true,
          attributeFilter: ["class", "style", "open"],
        });
      }
    } catch (err) {
      /* cross-origin – skip */
    }
  };

  /* ── Drag & drop: secties herordenen ───────────────────────── */

  // Houdt de huidig versleepte wrap bij (één tegelijk).
  D._draggingWrap = null;

  /**
   * Zet de drag-listeners op de handle van een sectie en zorgt dat de
   * pagina-body precies één keer dragover/drop afhandelt.
   */
  D._initSectionDragDrop = function (wrap, handle, pageBody, page) {
    if (!handle || !wrap || !pageBody) return;

    // Body-listeners maar 1x per pagina-body koppelen
    if (!pageBody._aisbReorderInit) {
      pageBody._aisbReorderInit = true;
      pageBody._aisbPage = page;

      pageBody.addEventListener("dragover", function (e) {
        const drag = D._draggingWrap;
        if (!drag || drag.parentNode !== pageBody) return;
        e.preventDefault();
        if (e.dataTransfer) e.dataTransfer.dropEffect = "move";

        const wraps = Array.from(
          pageBody.querySelectorAll(".aisb-design-iframe-wrap"),
        ).filter((w) => w !== drag);

        let inserted = false;
        for (const w of wraps) {
          const r = w.getBoundingClientRect();
          if (e.clientY < r.top + r.height / 2) {
            if (drag.nextSibling !== w) pageBody.insertBefore(drag, w);
            inserted = true;
            break;
          }
        }
        if (!inserted && pageBody.lastElementChild !== drag) {
          pageBody.appendChild(drag);
        }
      });

      pageBody.addEventListener("drop", function (e) {
        const drag = D._draggingWrap;
        if (!drag || drag.parentNode !== pageBody) return;
        e.preventDefault();
        D._stageSectionReorder(pageBody, pageBody._aisbPage);
      });
    }

    handle.addEventListener("mousedown", function (e) {
      // Voorkom dat pan/zoom triggert
      e.stopPropagation();
    });

    handle.addEventListener("dragstart", function (e) {
      D._draggingWrap = wrap;
      wrap.classList.add("is-dragging");
      if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = "move";
        try {
          e.dataTransfer.setData("text/plain", wrap.dataset.uuid || "");
        } catch (_) {
          /* IE fallback, niet relevant */
        }
      }
      // Iframes vangen drag-events op; tijdelijk uitzetten zodat dragover op
      // de wraps werkt.
      D.allIframes.forEach(function (f) {
        f.style.pointerEvents = "none";
      });
      document.body.classList.add("aisb-is-reordering");
    });

    handle.addEventListener("dragend", function () {
      wrap.classList.remove("is-dragging");
      D._draggingWrap = null;
      D.allIframes.forEach(function (f) {
        f.style.pointerEvents = "";
      });
      document.body.classList.remove("aisb-is-reordering");
    });
  };

  /**
   * Markeer de huidige volgorde als gewijzigd. De daadwerkelijke save loopt
   * via D.saveAllEdits() (de 💾 knop) zodat alles in één klik opgeslagen wordt.
   */
  D._stageSectionReorder = function (pageBody, page) {
    if (!page) return;
    const wraps = Array.from(
      pageBody.querySelectorAll(".aisb-design-iframe-wrap"),
    );
    const uuids = wraps.map((w) => w.dataset.uuid || "").filter(Boolean);
    if (!uuids.length) return;

    // Bevries de huidige bg_index per uuid zodat de kleur na refresh
    // aan de sectie blijft hangen, ongeacht de nieuwe volgorde.
    const bgIndices = {};
    wraps.forEach((w) => {
      const u = w.dataset.uuid || "";
      if (!u) return;
      const f = w.querySelector("iframe.aisb-design-iframe");
      if (f && typeof f._bgIndex === "number") bgIndices[u] = f._bgIndex;
    });

    if (!D._pendingReorders) D._pendingReorders = {};
    const key = (page.sitemap_version_id || 0) + "|" + (page.slug || "");
    D._pendingReorders[key] = {
      sitemap_version_id: page.sitemap_version_id || 0,
      page_slug: page.slug || "",
      uuids: uuids,
      bg_indices: bgIndices,
      page: page,
    };

    // Visuele hint op opslaan-knop
    const btn = document.getElementById("aisb-design-save-btn");
    if (btn && !btn.classList.contains("is-dirty")) {
      btn.classList.add("is-dirty");
    }
  };

  D.initCanvasPanZoom = function (canvas, inner) {
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
