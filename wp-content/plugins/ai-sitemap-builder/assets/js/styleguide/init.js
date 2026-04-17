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
 */
(function () {
  "use strict";

  const SG = window.AISB_StyleGuide;
  if (!SG) return;

  /* ─── Load existing guide from server ──────────────────────── */

  async function loadGuide() {
    if (!SG.projectId) return;
    const out = await SG.post("aisb_get_style_guide", {
      project_id: SG.projectId,
    });
    if (out && out.success) {
      SG.guide = out.data.style_guide || {};
      if (SG.guide.headingFont || SG.guide.bodyFont) {
        SG.loadGoogleFonts(SG.guide.headingFont, SG.guide.bodyFont);
        SG.fontsAssigned = true;
        if (SG.el.typographyStatus)
          SG.el.typographyStatus.style.display = "none";
      }
      if (SG.guide.colours && SG.guide.colours.length) {
        SG.extractedColours = SG.guide.colours;
      }
      if (SG.guide.typography && SG.guide.typography.length) {
        SG.renderTypography(SG.guide.typography);
        if (SG.el.typographyResult) SG.el.typographyResult.style.display = "";
        if (SG.initFontPickers) SG.initFontPickers();
      }
      // Restore uploaded images from saved guide
      if (SG.guide.uploadedImages && SG.guide.uploadedImages.length) {
        SG.uploadedImages = SG.guide.uploadedImages;
      }
      SG.applyOverridesToAllIframes();
    }
  }

  /* ─── Save button ──────────────────────────────────────────── */

  SG.el.saveButton &&
    SG.el.saveButton.addEventListener("click", async function () {
      if (!SG.extractedColours.length) {
        SG.setStatus(
          "Nothing to save yet. Pick colours in Step 1 first.",
          "err",
        );
        return;
      }
      SG.guide.colours = SG.guide.colours || SG.extractedColours;
      SG.guide.uploadedImages = SG.uploadedImages || [];
      SG.setStatus("Saving…", "ok");
      const out = await SG.post("aisb_save_style_guide", {
        project_id: SG.projectId,
        style_guide_json: JSON.stringify(SG.guide),
      });
      if (out && out.success) {
        SG.setStatus("Style guide saved!", "ok");
      } else {
        SG.setStatus(
          (out && out.data && out.data.message) || "Save failed.",
          "err",
        );
      }
    });

  /* ─── Init ─────────────────────────────────────────────────── */

  loadGuide();
  SG.loadWireframeSections();
})();
