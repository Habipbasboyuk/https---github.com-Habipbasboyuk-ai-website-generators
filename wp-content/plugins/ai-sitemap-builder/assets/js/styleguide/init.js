/**
 * Style Guide — Main entry point.
 *
 * This file loads AFTER the step modules:
 *   js/styleguide/core.js     — shared state, helpers, wizard, canvas, CSS injection
 *   js/styleguide/colours.js  — step 1: logo/manual colour extraction
 *   js/styleguide/typography.js — step 2: AI auto-font pairing
 *   js/styleguide/images.js   — step 3: auto-assign + swap modal
 *
 * It wires up: load existing guide, load wireframes, and the save button.
 * Auto-saves unsaved progress to localStorage so a page refresh doesn't lose work.
 */
(function () {
  "use strict";

  const SG = window.AISB_StyleGuide;
  if (!SG) return;

  /* ─── Draft auto-save (localStorage) ──────────────────────── */

  SG.draftKey = "aisb_sg_draft_" + SG.projectId;

  SG.saveDraft = function () {
    try {
      localStorage.setItem(
        SG.draftKey,
        JSON.stringify({
          colours: SG.extractedColours,
          guide: SG.guide,
          uploadedImages: SG.uploadedImages || [],
        }),
      );
    } catch (e) {}
  };

  function clearDraft() {
    try {
      localStorage.removeItem(SG.draftKey);
    } catch (e) {}
  }

  // Parse draft once at module load; reused by the pre-check and loadGuide.
  let _draft = null;
  try {
    const raw = localStorage.getItem(SG.draftKey);
    if (raw) _draft = JSON.parse(raw);
  } catch (e) {}

  // If the draft already has fonts, flag it immediately so autoAssignFonts won't
  // trigger an AI call if the user navigates to step 2 before loadGuide finishes.
  if (
    _draft &&
    _draft.guide &&
    (_draft.guide.headingFont || _draft.guide.bodyFont)
  ) {
    SG.fontsAssigned = true;
  }

  // Debounce writes so rapid changes don't hammer localStorage
  let saveDraftTimer = null;
  function scheduleSaveDraft() {
    clearTimeout(saveDraftTimer);
    saveDraftTimer = setTimeout(SG.saveDraft, 500);
  }

  // Suppress the save triggered by loadGuide's final applyOverridesToAllIframes —
  // nothing new to persist right after loading from the same localStorage.
  let _skipNextSave = false;

  // Patch applyOverridesToAllIframes — called after every colour/font change
  const _origApplyOverrides = SG.applyOverridesToAllIframes;
  SG.applyOverridesToAllIframes = function () {
    _origApplyOverrides.call(SG);
    if (_skipNextSave) {
      _skipNextSave = false;
      return;
    }
    scheduleSaveDraft();
  };

  // Patch applyImagesToAllIframes — called after every image change (images.js)
  const _origApplyImages = SG.applyImagesToAllIframes;
  SG.applyImagesToAllIframes = function () {
    _origApplyImages.call(SG);
    scheduleSaveDraft();
  };

  /* ─── Load existing guide from server + restore draft ─────── */

  async function loadGuide() {
    if (!SG.projectId) return;

    // Fetch server-saved guide
    const out = await SG.post("aisb_get_style_guide", {
      project_id: SG.projectId,
    });
    if (out && out.success) {
      const sg = out.data.style_guide;
      // Guard: PHP may return [] (JSON array) for an empty guide.
      // An array cannot hold named properties, so always use a plain object.
      SG.guide = sg && !Array.isArray(sg) ? sg : {};
    }

    // Merge draft over server data — draft holds more recent unsaved work
    if (_draft) {
      if (_draft.guide) Object.assign(SG.guide, _draft.guide);
      if (_draft.colours && _draft.colours.length) {
        SG.extractedColours = _draft.colours;
      }
      if (_draft.uploadedImages && _draft.uploadedImages.length) {
        SG.uploadedImages = _draft.uploadedImages;
      }
    }

    // Restore fonts (delegates to typography.js helper to avoid duplication)
    if (SG.guide.headingFont || SG.guide.bodyFont) {
      if (SG.restoreFontsFromGuide) {
        SG.restoreFontsFromGuide();
      } else {
        SG.loadGoogleFonts(SG.guide.headingFont, SG.guide.bodyFont);
        SG.fontsAssigned = true;
        if (SG.el.typographyStatus)
          SG.el.typographyStatus.style.display = "none";
      }
    }

    // ── Restore colours ──────────────────────────────────────
    if (
      !SG.extractedColours.length &&
      SG.guide.colours &&
      SG.guide.colours.length
    ) {
      SG.extractedColours = SG.guide.colours;
    }
    if (SG.extractedColours.length) {
      if (SG.el.extractedSwatches) {
        SG.renderSwatchRow(SG.el.extractedSwatches, SG.extractedColours);
        if (SG.el.extractedContainer)
          SG.el.extractedContainer.style.display = "";
      }
      if (SG.el.manualSwatches) {
        SG.renderSwatchRow(SG.el.manualSwatches, SG.extractedColours);
      }
      if (SG.el.primaryPicker && SG.extractedColours[0]) {
        SG.el.primaryPicker.value = SG.extractedColours[0].hex;
        if (SG.el.primaryHex)
          SG.el.primaryHex.value = SG.extractedColours[0].hex;
      }
    }

    // ── Restore logo preview ─────────────────────────────────
    if (SG.guide.logoUrl && SG.el.logoPreview) {
      SG.el.logoPreview.src = SG.guide.logoUrl;
      SG.el.logoPreview.style.display = "block";
      if (SG.el.uploadPlaceholder)
        SG.el.uploadPlaceholder.style.display = "none";
      if (SG.el.extractedContainer) SG.el.extractedContainer.style.display = "";
    }

    // ── Restore uploaded images ──────────────────────────────
    if (
      !SG.uploadedImages.length &&
      SG.guide.uploadedImages &&
      SG.guide.uploadedImages.length
    ) {
      SG.uploadedImages = SG.guide.uploadedImages;
    }
    if (SG.uploadedImages.length && SG.renderUploadedGrid) {
      SG.renderUploadedGrid();
    }

    _skipNextSave = true;
    SG.applyOverridesToAllIframes();
    // Als de guide al afbeeldingen heeft (hersteld uit draft of server), zet
    // imagesLoaded zodat autoAssignImages geen onnodige Unsplash-fetch doet.
    // De injectie zelf gebeurt via de load-handlers van de iframes.
    if (SG.guide.images && SG.guide.images.length) {
      SG.imagesLoaded = true;
    }
  }

  /* ─── Save button ──────────────────────────────────────────── */

  SG.el.saveButton &&
    SG.el.saveButton.addEventListener("click", async function (e) {
      e.preventDefault();

      console.log("[AISB] Save button clicked");
      console.log("[AISB] projectId:", SG.projectId);
      console.log("[AISB] extractedColours:", SG.extractedColours);
      console.log(
        "[AISB] guide before save:",
        JSON.parse(JSON.stringify(SG.guide)),
      );

      if (!SG.extractedColours.length) {
        console.warn("[AISB] No extractedColours — aborting save");
        SG.setStatus(
          "Nothing to save yet. Pick colours in Step 1 first.",
          "err",
        );
        return;
      }

      // Always use the current extracted colours (not a stale server value)
      // Guard: if loadGuide set SG.guide to [] (empty PHP array from server),
      // named properties like .colours are silently dropped by JSON.stringify.
      // Ensure guide is always a plain object before saving.
      if (Array.isArray(SG.guide)) SG.guide = {};
      SG.guide.colours = SG.extractedColours;
      SG.guide.uploadedImages = SG.uploadedImages || [];

      console.log(
        "[AISB] guide being saved:",
        JSON.parse(JSON.stringify(SG.guide)),
      );

      // Write to dedicated preview key BEFORE navigation so design.js always
      // has the most recent guide available immediately on step 4.
      try {
        const previewJson = JSON.stringify(SG.guide);
        localStorage.setItem("aisb_sg_preview_" + SG.projectId, previewJson);
        console.log(
          "[AISB] preview key written to localStorage, length:",
          previewJson.length,
        );
      } catch (e) {
        console.error("[AISB] localStorage write failed:", e);
      }

      // Persist draft as a secondary fallback
      SG.saveDraft();

      console.log("[AISB] posting to server...");
      const out = await SG.post("aisb_save_style_guide", {
        project_id: SG.projectId,
        style_guide_json: JSON.stringify(SG.guide),
      });
      console.log("[AISB] server save response:", out);

      if (out && out.success) {
        clearDraft();
        console.log("[AISB] server save OK, navigating...");
      } else {
        console.warn(
          "[AISB] server save FAILED — falling back to localStorage preview key",
          out,
        );
      }

      const href = SG.el.saveButton.getAttribute("href");
      console.log("[AISB] navigating to:", href);
      if (href) window.location.href = href;
    });

  /* ─── Init ─────────────────────────────────────────────────── */

  // Await loadGuide so draft/server state is fully restored before
  // wireframes load and autoAssignImages runs
  loadGuide().then(function () {
    SG.loadWireframeSections();
  });
})();
