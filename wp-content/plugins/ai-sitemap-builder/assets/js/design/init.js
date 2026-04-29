/**
 * design/init.js — Bootstrap: laadt de style guide en wireframes, bouwt dan de canvas.
 *
 * Laadvolgorde:
 *   1. Inline data-design-guide attribuut (PHP embed)
 *   2. localStorage preview-sleutel (geschreven door "Save & Design")
 *   3. AJAX (wireframes altijd; guide alleen als nog geen kleuren)
 *   4. localStorage draft-sleutel (laatste redmiddel)
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  async function init() {
    D.canvasEl.innerHTML =
      '<div class="aisb-design-empty">Loading design preview…</div>';

    console.log("[AISB design] init, projectId:", D.projectId);

    // ── 1. Inline guide — PHP embedt verse DB-data in het HTML-attribuut.
    try {
      const inlineRaw = D.root.getAttribute("data-design-guide");
      console.log(
        "[AISB design] inline data-design-guide length:",
        inlineRaw ? inlineRaw.length : 0,
      );
      if (inlineRaw && inlineRaw !== "{}") {
        D.guide = JSON.parse(inlineRaw);
        console.log(
          "[AISB design] inline guide parsed — colours:",
          D.guide.colours && D.guide.colours.length,
          "headingFont:",
          D.guide.headingFont,
        );
      }
    } catch (e) {
      console.error("[AISB design] failed to parse inline guide:", e);
    }

    // ── 2. localStorage preview-sleutel — geschreven door "Save & Design" net
    //    vóór de navigatie; wordt bovenop de inline data toegepast als extra veiligheidsnet.
    try {
      const previewRaw = localStorage.getItem("aisb_sg_preview_" + D.projectId);
      console.log(
        "[AISB design] localStorage preview key present:",
        !!previewRaw,
      );
      if (previewRaw) {
        const pg = JSON.parse(previewRaw);
        if (pg && Object.keys(pg).length) Object.assign(D.guide, pg);
        localStorage.removeItem("aisb_sg_preview_" + D.projectId);
        console.log(
          "[AISB design] preview key applied — colours:",
          D.guide.colours && D.guide.colours.length,
        );
      }
    } catch (e) {
      console.error("[AISB design] preview key parse failed:", e);
    }

    // ── 3. Wireframes komen altijd via AJAX; guide via AJAX als inline/preview
    //    geen kleuren óf geen afbeeldingen had.
    const needsGuide =
      !D.guide.colours ||
      !D.guide.colours.length ||
      !D.guide.images ||
      !D.guide.images.length;
    console.log("[AISB design] needsGuide (AJAX fallback):", needsGuide);
    const reqs = [
      D.post("aisb_get_wireframe_sections", { project_id: D.projectId }),
    ];
    if (needsGuide)
      reqs.push(D.post("aisb_get_style_guide", { project_id: D.projectId }));

    const [wfRes, guideRes] = await Promise.all(reqs);

    if (wfRes && wfRes.success && wfRes.data.pages) {
      D.wireframePages = wfRes.data.pages;
      console.log(
        "[AISB design] wireframe pages loaded:",
        D.wireframePages.length,
      );
    } else {
      console.warn("[AISB design] wireframe sections failed:", wfRes);
    }
    if (guideRes && guideRes.success) {
      const sg = guideRes.data.style_guide;
      // Guard: PHP kan [] (JSON array) teruggeven voor een lege guide
      if (sg && !Array.isArray(sg)) Object.assign(D.guide, sg);
      // Re-inject images into any iframes that already loaded before the guide arrived
      if (D.guide.images && D.guide.images.length && D.allIframes) {
        D.allIframes.forEach((iframe) => {
          if (iframe._loaded) D.injectImages(iframe);
        });
      }
    }

    // ── 4. localStorage draft-sleutel — laatste redmiddel als alles hierboven
    //    geen kleuren opleverde.
    if (!D.guide.colours || !D.guide.colours.length) {
      console.warn("[AISB design] still no colours — trying draft key");
      try {
        const raw = localStorage.getItem("aisb_sg_draft_" + D.projectId);
        if (raw) {
          const draft = JSON.parse(raw);
          if (draft && draft.guide) Object.assign(D.guide, draft.guide);
          if (draft && draft.colours && draft.colours.length)
            D.guide.colours = draft.colours;
          console.log(
            "[AISB design] draft applied — colours:",
            D.guide.colours && D.guide.colours.length,
          );
        }
      } catch (e) {}
    }

    console.log(
      "[AISB design] final guide:",
      JSON.parse(JSON.stringify(D.guide)),
    );
    D.buildCanvas();
  }

  init();
})();
