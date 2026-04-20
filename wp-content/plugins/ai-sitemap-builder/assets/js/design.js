/**
 * design.js — Step 4 Design preview
 * Figma-style infinite canvas: pages side by side horizontally,
 * sections stacked vertically per page, iframes scrollable.
 * Pan with mouse drag, zoom with Ctrl+scroll, double-click to fit.
 */
(function () {
  "use strict";

  const root = document.querySelector("[data-design]");
  if (!root) return;

  const projectId =
    parseInt(root.getAttribute("data-design-project") || "0", 10) || 0;
  if (!projectId) return;

  const canvasEl = root.querySelector("[data-design-canvas]");
  if (!canvasEl) return;

  /* ── State ──────────────────────────────────────────────────── */
  let guide = {};
  let wireframePages = [];
  const allIframes = [];

  /* ── Helpers ────────────────────────────────────────────────── */
  function escapeHtml(text) {
    return String(text || "").replace(
      /[&<>"']/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[c],
    );
  }

  function toQueryString(params) {
    return Object.keys(params)
      .map((k) => encodeURIComponent(k) + "=" + encodeURIComponent(params[k]))
      .join("&");
  }

  async function post(action, data) {
    const r = await fetch(AISB_DESIGN.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: toQueryString(
        Object.assign({ action, nonce: AISB_DESIGN.nonce }, data || {}),
      ),
    });
    return r.json();
  }

  function clamp(v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
  }

  /* ── Colour / font override CSS ─────────────────────────────── */
  function buildOverrideCss() {
    const colours = guide.colours && guide.colours.length ? guide.colours : [];
    const find = (name) => (colours.find((c) => c.name === name) || {}).hex;

    let css = "";
    if (colours.length) {
      const primary = colours[0] ? colours[0].hex : "";
      const light = find("Light") || (colours[1] ? colours[1].hex : "");
      const dark = find("Dark") || (colours[3] ? colours[3].hex : "");
      const accent = find("Accent") || (colours[2] ? colours[2].hex : "");
      const neutral = find("Neutral") || "#f5f5f5";

      css += ":root{";
      if (primary)
        css +=
          "--bricks-color-primary:" +
          primary +
          ";--e-global-color-primary:" +
          primary +
          ";";
      if (accent)
        css +=
          "--bricks-color-accent:" +
          accent +
          ";--e-global-color-accent:" +
          accent +
          ";";
      if (dark) css += "--bricks-color-dark:" + dark + ";";
      if (light) css += "--bricks-color-light:" + light + ";";
      if (neutral) css += "--bricks-color-neutral:" + neutral + ";";

      const neutralSteps = [
        { step: "25", l: 0.949 },
        { step: "50", l: 0.875 },
        { step: "100", l: 0.796 },
        { step: "200", l: 0.718 },
        { step: "300", l: 0.643 },
        { step: "400", l: 0.565 },
        { step: "500", l: 0.486 },
        { step: "600", l: 0.408 },
        { step: "700", l: 0.329 },
        { step: "800", l: 0.255 },
        { step: "900", l: 0.18 },
      ];
      neutralSteps.forEach((ns) => {
        const val = "hsla(0,0%," + (ns.l * 100).toFixed(1) + "%,1)";
        css += "--brxw-color-neutral-" + ns.step + ":" + val + ";";
        css += "--bricks-color-brxw-color-neutral-" + ns.step + ":" + val + ";";
      });
      css += "}";

      if (primary) {
        css +=
          ".brxe-button,.bricks-button{background-color:" +
          primary +
          " !important;border-color:" +
          primary +
          " !important;}";
        css += "a:not(.brxe-button){color:" + primary + " !important;}";
      }
      if (accent) {
        css +=
          ".brxe-button.bricks-background-none,.brxe-button.outline,.brxe-button[class*=outline],.brxe-button.ghost{background-color:transparent !important;border-color:" +
          accent +
          " !important;color:" +
          accent +
          " !important;}";
        css +=
          ".brxe-icon-svg svg,.brxe-icon svg{color:" +
          accent +
          " !important;fill:" +
          accent +
          " !important;}";
        css += ".brxe-list li::marker{color:" + accent + " !important;}";
        css +=
          ".brxe-divider .line,.brxe-divider hr{border-color:" +
          accent +
          " !important;}";
      }
      if (light && dark) {
        css +=
          ".brxe-pricing .bricks-button,.brxe-post-meta{color:" +
          dark +
          " !important;}";
      }
      if (neutral && neutral !== "#f5f5f5") {
        css +=
          "input,textarea,select,.brxe-form input,.brxe-form textarea,.brxe-form select{background-color:" +
          neutral +
          " !important;border-color:" +
          (light || neutral) +
          " !important;}";
      }
    }
    if (guide.headingFont) {
      css +=
        "h1,h2,h3,h4,h5,h6,.brxe-heading{font-family:" +
        guide.headingFont +
        ",sans-serif !important;}";
    }
    if (guide.bodyFont) {
      css +=
        "body,p,.brxe-text,.brxe-text-basic,.brxe-rich-text,.brxe-post-content{font-family:" +
        guide.bodyFont +
        ",sans-serif !important;}";
    }
    return css;
  }

  function buildGoogleFontsUrl() {
    const families = [guide.headingFont, guide.bodyFont]
      .filter(Boolean)
      .map((f) => f.replace(/ /g, "+"));
    if (!families.length) return "";
    return (
      "https://fonts.googleapis.com/css2?" +
      families.map((f) => "family=" + f + ":wght@400;600;700").join("&") +
      "&display=swap"
    );
  }

  function getLuminance(hex) {
    if (!hex || hex.length < 7) return 1;
    let r = parseInt(hex.slice(1, 3), 16) / 255;
    let g = parseInt(hex.slice(3, 5), 16) / 255;
    let b = parseInt(hex.slice(5, 7), 16) / 255;
    r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
    g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
    b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  }

  /* ── Inject overrides into one iframe ───────────────────────── */
  function injectOverride(iframe) {
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (!doc) return;

      let style = doc.getElementById("aisb-design-override");
      if (!style) {
        style = doc.createElement("style");
        style.id = "aisb-design-override";
        doc.head.appendChild(style);
      }

      let css = buildOverrideCss();

      const sIdx =
        typeof iframe._sectionIdx === "number" ? iframe._sectionIdx : -1;
      if (sIdx >= 0) {
        const colours =
          guide.colours && guide.colours.length ? guide.colours : [];
        const find = (name) => (colours.find((c) => c.name === name) || {}).hex;
        // Use name-based lookup with index fallbacks (same strategy as buildOverrideCss)
        const palDark = find("Dark") || (colours[3] ? colours[3].hex : "");
        const palNeutral =
          find("Neutral") || (colours[5] ? colours[5].hex : "");
        const palLight = find("Light") || (colours[4] ? colours[4].hex : "");

        // Mirror the exact same logic as core.js / injectOverrideIntoIframe:
        // even sections → palLight (or sectionBg1 fallback)
        // odd sections  → palNeutral (or palLight, or sectionBg2 fallback)
        let secBg;
        if (sIdx % 2 === 0) {
          secBg = palLight || guide.sectionBg1 || "#ffffff";
        } else {
          secBg = palNeutral || palLight || guide.sectionBg2 || "#f0f4ff";
        }

        // Use :not([style*='background-image']) to protect sections with a
        // background image (same guard as core.js).
        css +=
          ".brxe-section:not([style*='background-image'])," +
          ".brxe-container:not([style*='background-image'])," +
          ".brxe-block:not([style*='background-image'])" +
          "{background-color:" +
          secBg +
          " !important;}";
        css += "body{background-color:" + secBg + " !important;}";

        const isDarkBg = getLuminance(secBg) < 0.4;
        const headingColour = isDarkBg ? "#ffffff" : palDark || "#1a1a1a";
        const bodyColour = isDarkBg
          ? "rgba(255,255,255,0.85)"
          : palDark || "#333333";

        css +=
          "h1,h2,h3,h4,h5,h6,.brxe-heading{color:" +
          headingColour +
          " !important;}";
        css +=
          "body,p,.brxe-text,.brxe-text-basic,.brxe-rich-text,.brxe-post-content,li,td,th,label,figcaption,blockquote{color:" +
          bodyColour +
          " !important;}";
        if (isDarkBg) {
          css += ".brxe-button,.bricks-button{color:#fff !important;}";
          css +=
            "a:not(.brxe-button){color:" +
            (palLight || "#ffffff") +
            " !important;}";
        } else {
          const primary = colours.length ? colours[0].hex : null;
          if (primary) {
            const btnTextColour =
              getLuminance(primary) < 0.4 ? "#ffffff" : "#1a1a1a";
            css +=
              ".brxe-button,.bricks-button{color:" +
              btnTextColour +
              " !important;}";
          }
        }
      }

      style.textContent = css;

      // Logo injecteren in eerste sectie per pagina (sectionIdx 0 = header/nav)
      if (guide.logoUrl && sIdx === 0) {
        const logoImgs = doc.querySelectorAll(
          ".brxe-nav-menu img, nav img, header img, [class*='logo'] img, .brxe-image img",
        );
        if (logoImgs.length) {
          logoImgs[0].src = guide.logoUrl;
          logoImgs[0].srcset = "";
          logoImgs[0].style.cssText +=
            ";max-height:60px;width:auto;object-fit:contain";
        }
      }

      // Google Fonts
      const fontsUrl = buildGoogleFontsUrl();
      let link = doc.getElementById("aisb-design-gfonts");
      if (fontsUrl) {
        if (!link) {
          link = doc.createElement("link");
          link.id = "aisb-design-gfonts";
          link.rel = "stylesheet";
          doc.head.appendChild(link);
        }
        link.href = fontsUrl;
      } else if (link) {
        link.remove();
      }
    } catch (e) {
      /* cross-origin */
    }
  }

  /* ── Image injection ────────────────────────────────────────── */
  function buildImageMap() {
    if (!guide.images || !guide.images.length) return {};
    const map = {};
    let imgIdx = 0;
    wireframePages.forEach((page) => {
      (page.sections || []).forEach((s, sIdx) => {
        const count = s.media_count || 0;
        const urls = [];
        for (let i = 0; i < count; i++) {
          if (imgIdx < guide.images.length)
            urls.push(
              guide.images[imgIdx++].full ||
                guide.images[imgIdx - 1].thumb ||
                "",
            );
        }
        // key uses per-page sIdx (same as _localSectionIdx on the iframe)
        if (urls.length) map[page.slug + ":" + sIdx] = urls;
      });
    });
    return map;
  }

  function injectImages(iframe) {
    if (!guide.images || !guide.images.length) return;
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (!doc) return;
      // Use _localSectionIdx (per-page) to match buildImageMap keys
      const localIdx =
        typeof iframe._localSectionIdx === "number"
          ? iframe._localSectionIdx
          : iframe._sectionIdx;
      const urls = buildImageMap()[iframe._pageSlug + ":" + localIdx];
      if (!urls || !urls.length) return;
      const imgs = doc.querySelectorAll("img");
      let ui = 0;
      imgs.forEach((img) => {
        if (ui < urls.length) {
          img.src = urls[ui];
          img.srcset = "";
          img.style.objectFit = "cover";
          ui++;
        }
      });
    } catch (e) {
      /* cross-origin */
    }
  }

  /* ── Build the infinite canvas ──────────────────────────────── */
  function buildCanvas() {
    canvasEl.innerHTML = "";

    if (!wireframePages.length) {
      canvasEl.innerHTML =
        '<div class="aisb-design-empty">No wireframes found. Generate wireframes in Step 2 first.</div>';
      return;
    }

    // 3-layer structure: canvas > inner (transform target) > pages grid
    const inner = document.createElement("div");
    inner.className = "aisb-design-inner";
    canvasEl.appendChild(inner);

    const grid = document.createElement("div");
    grid.className = "aisb-design-grid";
    inner.appendChild(grid);

    // One card per page, side by side
    // Global section counter so alternating backgrounds continue across pages
    let globalSectionIdx = 0;

    wireframePages.forEach((page) => {
      const card = document.createElement("div");
      card.className = "aisb-design-page-card";

      // Card header (page label + section count)
      const head = document.createElement("div");
      head.className = "aisb-design-page-head";
      head.innerHTML =
        '<span class="aisb-design-page-title">' +
        escapeHtml(page.title || page.slug) +
        "</span>" +
        '<span class="aisb-design-page-badge">' +
        (page.sections ? page.sections.length : 0) +
        " sections</span>";
      card.appendChild(head);

      // Card body: stacked iframes
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
        // scrolling="yes" so when you reach the iframe content edge the site scrolls
        iframe.scrolling = "yes";
        iframe._loaded = false;
        iframe._pageSlug = page.slug;
        iframe._sectionIdx = globalSectionIdx++; // global counter for alternating backgrounds
        iframe._localSectionIdx = sIdx; // per-page index for image map lookup

        iframe.addEventListener("load", () => {
          iframe._loaded = true;
          injectOverride(iframe);
          injectImages(iframe);
          // Size the iframe to its full content height so nothing is cut off
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
        allIframes.push(iframe);
      });

      card.appendChild(body);
      grid.appendChild(card);
    });

    setupPanZoom(canvasEl, inner);
  }

  /* ── Pan / zoom (identical logic to core.js) ────────────────── */
  function setupPanZoom(canvas, inner) {
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
      state.scale = clamp(Math.min(scaleX, scaleY), 0.05, 1);
      state.translateX =
        (canvasRect.width - contentWidth * state.scale) / 2 -
        minX * state.scale;
      state.translateY = padding;
      applyTransform();
    }

    canvas._designFitToView = fitToView;
    requestAnimationFrame(fitToView);

    // Pan — drag anywhere on canvas (iframes are pointer-events:none, so they
    // never swallow mousedown/mouseup and panning always stops correctly)
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

    // mousemove + mouseup scoped to canvas only — avoids interference with
    // other parts of the page and iframes can't swallow these anymore
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

    // Zoom — Ctrl + wheel
    canvas.addEventListener(
      "wheel",
      (e) => {
        if (e.ctrlKey) {
          e.preventDefault();
          const rect = canvas.getBoundingClientRect();
          const cx = e.clientX - rect.left;
          const cy = e.clientY - rect.top;
          const prev = state.scale;
          const next = clamp(prev * (1 - e.deltaY * 0.001), 0.05, 3);
          if (Math.abs(next - prev) < 0.0001) return;
          state.translateX = cx - (cx - state.translateX) * (next / prev);
          state.translateY = cy - (cy - state.translateY) * (next / prev);
          state.scale = next;
          applyTransform();
        } else {
          // Normal scroll → pan canvas; pass through if already at edge
          const newTX = state.translateX - e.deltaX;
          const newTY = state.translateY - e.deltaY;
          const contentW = inner.scrollWidth * state.scale;
          const contentH = inner.scrollHeight * state.scale;
          const viewW = canvas.getBoundingClientRect().width;
          const viewH = canvas.getBoundingClientRect().height;
          const clampedTX = clamp(newTX, Math.min(40, viewW - contentW), 40);
          const clampedTY = clamp(newTY, Math.min(40, viewH - contentH), 40);
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

    // Double-click on empty area → fit all to view
    canvas.addEventListener("dblclick", (e) => {
      if (e.target.closest(".aisb-design-page-card")) return;
      fitToView();
    });
  }

  /* ── Boot ───────────────────────────────────────────────────── */
  async function init() {
    canvasEl.innerHTML =
      '<div class="aisb-design-empty">Loading design preview…</div>';

    console.log("[AISB design] init, projectId:", projectId);

    // ── 1. Inline guide — PHP embeds fresh DB data in the HTML attribute.
    try {
      const inlineRaw = root.getAttribute("data-design-guide");
      console.log(
        "[AISB design] inline data-design-guide length:",
        inlineRaw ? inlineRaw.length : 0,
      );
      if (inlineRaw && inlineRaw !== "{}") {
        guide = JSON.parse(inlineRaw);
        console.log(
          "[AISB design] inline guide parsed — colours:",
          guide.colours && guide.colours.length,
          "headingFont:",
          guide.headingFont,
        );
      }
    } catch (e) {
      console.error("[AISB design] failed to parse inline guide:", e);
    }

    // ── 2. localStorage preview key — written by Save & Design handler just
    //    before navigation; applied on top of inline data as extra safety net.
    try {
      const previewRaw = localStorage.getItem("aisb_sg_preview_" + projectId);
      console.log(
        "[AISB design] localStorage preview key present:",
        !!previewRaw,
      );
      if (previewRaw) {
        const pg = JSON.parse(previewRaw);
        if (pg && Object.keys(pg).length) Object.assign(guide, pg);
        localStorage.removeItem("aisb_sg_preview_" + projectId);
        console.log(
          "[AISB design] preview key applied — colours:",
          guide.colours && guide.colours.length,
        );
      }
    } catch (e) {
      console.error("[AISB design] preview key parse failed:", e);
    }

    // ── 3. Wireframes always come from AJAX; fetch guide via AJAX only when
    //    neither inline data nor preview key had colours.
    const needsGuide = !guide.colours || !guide.colours.length;
    console.log("[AISB design] needsGuide (AJAX fallback):", needsGuide);
    const reqs = [
      post("aisb_get_wireframe_sections", { project_id: projectId }),
    ];
    if (needsGuide)
      reqs.push(post("aisb_get_style_guide", { project_id: projectId }));

    const [wfRes, guideRes] = await Promise.all(reqs);

    if (wfRes && wfRes.success && wfRes.data.pages) {
      wireframePages = wfRes.data.pages;
      console.log(
        "[AISB design] wireframe pages loaded:",
        wireframePages.length,
      );
    } else {
      console.warn("[AISB design] wireframe sections failed:", wfRes);
    }
    if (guideRes && guideRes.success) {
      const sg = guideRes.data.style_guide;
      // Guard against PHP returning [] (JSON array) for an empty guide
      if (sg && !Array.isArray(sg)) Object.assign(guide, sg);
    }

    // ── 4. localStorage draft — last resort if all above yielded no colours.
    if (!guide.colours || !guide.colours.length) {
      console.warn("[AISB design] still no colours — trying draft key");
      try {
        const raw = localStorage.getItem("aisb_sg_draft_" + projectId);
        if (raw) {
          const draft = JSON.parse(raw);
          if (draft && draft.guide) Object.assign(guide, draft.guide);
          if (draft && draft.colours && draft.colours.length)
            guide.colours = draft.colours;
          console.log(
            "[AISB design] draft applied — colours:",
            guide.colours && guide.colours.length,
          );
        }
      } catch (e) {}
    }

    console.log(
      "[AISB design] final guide:",
      JSON.parse(JSON.stringify(guide)),
    );
    buildCanvas();
  }

  init();
})();
