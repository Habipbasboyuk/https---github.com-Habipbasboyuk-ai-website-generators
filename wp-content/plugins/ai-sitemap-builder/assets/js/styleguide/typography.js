/**
 * Style Guide — Step 2: Typography
 * Auto-assign fonts via AI, render typography preview, load Google Fonts.
 */
(function () {
  "use strict";

  const SG = window.AISB_StyleGuide;
  if (!SG) return;

  const el = SG.el;

  /* ─── Render typography preview ────────────────────────────── */

  SG.renderTypography = function (typeScale) {
    if (!el.typographyDisplay) return;
    if (!Array.isArray(typeScale) || !typeScale.length) {
      el.typographyDisplay.innerHTML =
        '<div class="aisb-sg-empty-state">No typography defined yet.</div>';
      return;
    }
    el.typographyDisplay.innerHTML = typeScale
      .map(function (t) {
        return (
          '<div class="aisb-sg-type-row">' +
          '<div class="aisb-sg-type-meta">' +
          SG.escapeHtml(t.label || "") +
          "</div>" +
          '<div class="aisb-sg-type-sample ' +
          SG.escapeHtml(t.cls || "body") +
          '"' +
          (t.fontFamily
            ? ' style="font-family:' +
              SG.escapeHtml(t.fontFamily) +
              ', sans-serif;"'
            : "") +
          ">" +
          SG.escapeHtml(t.sample || t.label || "The quick brown fox") +
          "</div>" +
          "</div>"
        );
      })
      .join("");
  };

  /* ─── Load Google Fonts into parent document ───────────────── */

  SG.loadGoogleFonts = function (heading, body) {
    const families = [heading, body].filter(Boolean).map(function (f) {
      return f.replace(/ /g, "+");
    });
    if (!families.length) return;
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href =
      "https://fonts.googleapis.com/css2?" +
      families
        .map(function (f) {
          return "family=" + f + ":wght@400;600;700";
        })
        .join("&") +
      "&display=swap";
    document.head.appendChild(link);
  };

  /* ─── Popular Google Fonts list ─────────────────────────────── */

  const popularFonts = [
    "Inter",
    "Roboto",
    "Open Sans",
    "Montserrat",
    "Lato",
    "Poppins",
    "Raleway",
    "Nunito",
    "Playfair Display",
    "Merriweather",
    "Source Sans 3",
    "PT Sans",
    "Oswald",
    "Noto Sans",
    "Ubuntu",
    "Rubik",
    "Work Sans",
    "Roboto Slab",
    "Lora",
    "Fira Sans",
    "Mulish",
    "Barlow",
    "Quicksand",
    "Cabin",
    "DM Sans",
    "Josefin Sans",
    "Libre Baskerville",
    "Karla",
    "Manrope",
    "Space Grotesk",
    "Bitter",
    "Archivo",
    "IBM Plex Sans",
    "Outfit",
    "Plus Jakarta Sans",
    "Crimson Text",
    "Cormorant Garamond",
    "EB Garamond",
    "PT Serif",
    "Noto Serif",
  ];

  /* ─── Font picker elements ─────────────────────────────────── */

  const headingSelect = SG.root.querySelector("[data-font-select-heading]");
  const bodySelect = SG.root.querySelector("[data-font-select-body]");

  function populateFontSelect(selectEl, currentFont) {
    if (!selectEl) return;
    // Show "AI suggestion" placeholder only when no font has been chosen yet.
    // Once a font is set (by AI on first visit or manually), it is locked in
    // and only manual changes are allowed — no AI re-pick.
    let opts = currentFont ? "" : '<option value="">— AI suggestion —</option>';
    const seen = {};
    // Add current font at top if it is not in the popular list
    if (currentFont && popularFonts.indexOf(currentFont) === -1) {
      opts +=
        '<option value="' +
        SG.escapeHtml(currentFont) +
        '" selected>' +
        SG.escapeHtml(currentFont) +
        "</option>";
      seen[currentFont] = true;
    }
    popularFonts.forEach(function (f) {
      const selected = f === currentFont ? " selected" : "";
      opts +=
        '<option value="' +
        SG.escapeHtml(f) +
        '"' +
        selected +
        ">" +
        SG.escapeHtml(f) +
        "</option>";
      seen[f] = true;
    });
    selectEl.innerHTML = opts;
  }

  function loadFontPreviewStyles() {
    // Load all popular fonts so the dropdown options can preview in their font
    const families = popularFonts
      .map(function (f) {
        return "family=" + f.replace(/ /g, "+") + ":wght@400;600;700";
      })
      .join("&");
    if (document.getElementById("aisb-popular-fonts-css")) return;
    const link = document.createElement("link");
    link.id = "aisb-popular-fonts-css";
    link.rel = "stylesheet";
    link.href =
      "https://fonts.googleapis.com/css2?" + families + "&display=swap";
    document.head.appendChild(link);
  }

  function applyFontSelectStyles(selectEl) {
    if (!selectEl) return;
    Array.from(selectEl.options).forEach(function (opt) {
      if (opt.value) opt.style.fontFamily = opt.value + ", sans-serif";
    });
  }

  function onFontChange() {
    const newHeading =
      (headingSelect && headingSelect.value) ||
      SG.guide._aiHeadingFont ||
      SG.guide.headingFont ||
      "";
    const newBody =
      (bodySelect && bodySelect.value) ||
      SG.guide._aiBodyFont ||
      SG.guide.bodyFont ||
      "";

    SG.guide.headingFont = newHeading;
    SG.guide.bodyFont = newBody;

    // Update type scale font families
    if (SG.guide.typography) {
      SG.guide.typography.forEach(function (t) {
        if (t.cls === "body" || t.cls === "small") {
          t.fontFamily = newBody;
        } else {
          t.fontFamily = newHeading;
        }
      });
    }

    SG.loadGoogleFonts(newHeading, newBody);
    SG.renderTypography(SG.guide.typography);
    SG.applyOverridesToAllIframes();
  }

  if (headingSelect) headingSelect.addEventListener("change", onFontChange);
  if (bodySelect) bodySelect.addEventListener("change", onFontChange);

  /* ─── Init font pickers after AI assigns ───────────────────── */

  SG.initFontPickers = function () {
    loadFontPreviewStyles();
    // Store AI choices so we can fall back to them
    if (!SG.guide._aiHeadingFont)
      SG.guide._aiHeadingFont = SG.guide.headingFont;
    if (!SG.guide._aiBodyFont) SG.guide._aiBodyFont = SG.guide.bodyFont;
    populateFontSelect(headingSelect, SG.guide.headingFont);
    populateFontSelect(bodySelect, SG.guide.bodyFont);
    applyFontSelectStyles(headingSelect);
    applyFontSelectStyles(bodySelect);
  };

  /* ─── Auto-assign fonts (AI picks automatisch bij eerste bezoek) ──── */

  function restoreFontsFromGuide() {
    SG.fontsAssigned = true;
    SG.loadGoogleFonts(SG.guide.headingFont, SG.guide.bodyFont);
    if (SG.guide.typography && SG.guide.typography.length) {
      SG.renderTypography(SG.guide.typography);
      if (SG.el.typographyResult) SG.el.typographyResult.style.display = "";
    }
    if (SG.el.typographyStatus) SG.el.typographyStatus.style.display = "none";
    if (SG.initFontPickers) SG.initFontPickers();
  }
  SG.restoreFontsFromGuide = restoreFontsFromGuide;

  SG.autoAssignFonts = async function () {
    if (SG.fontsLoading) return;

    // 1. Al toegewezen via loadGuide of pre-check
    if (SG.fontsAssigned) {
      restoreFontsFromGuide();
      return;
    }

    // 2. fontsAssigned kan gereset zijn door een kleurwijziging; lees draft opnieuw
    try {
      const raw = localStorage.getItem(SG.draftKey);
      if (raw) {
        const d = JSON.parse(raw);
        if (d && d.guide && (d.guide.headingFont || d.guide.bodyFont)) {
          Object.assign(SG.guide, d.guide);
          restoreFontsFromGuide();
          return;
        }
      }
    } catch (e) {}

    // 3. Fonts al in guide (server-save)
    if (SG.guide.headingFont || SG.guide.bodyFont) {
      restoreFontsFromGuide();
      return;
    }

    // 4. Geen kleuren beschikbaar → kan geen fonts toewijzen
    if (!SG.extractedColours.length) return;

    SG.fontsLoading = true;
    if (el.typographyStatus) {
      el.typographyStatus.innerHTML =
        '<div class="aisb-sg-empty-state">⏳ AI is picking the perfect fonts for your brand…</div>';
    }

    const out = await SG.post("aisb_auto_fonts", {
      project_id: SG.projectId,
      colours: JSON.stringify(SG.extractedColours),
    });

    SG.fontsLoading = false;

    if (out && out.success) {
      const data = out.data;
      SG.guide.headingFont = data.fonts.heading_font || "";
      SG.guide.bodyFont = data.fonts.body_font || "";
      SG.guide.typography = data.fonts.type_scale || [];
      SG.guide.sectionBg1 = data.fonts.section_bg_1 || "#ffffff";
      SG.guide.sectionBg2 = data.fonts.section_bg_2 || "#f0f4ff";

      SG.loadGoogleFonts(SG.guide.headingFont, SG.guide.bodyFont);
      SG.renderTypography(SG.guide.typography);
      if (el.typographyResult) el.typographyResult.style.display = "";
      if (el.typographyStatus) el.typographyStatus.style.display = "none";
      SG.applyOverridesToAllIframes();
      SG.fontsAssigned = true;
      if (SG.saveDraft) SG.saveDraft();
      SG.initFontPickers();
    } else {
      if (el.typographyStatus) {
        el.typographyStatus.innerHTML =
          '<div class="aisb-sg-empty-state" style="color:#c00;">Font generation failed. Try going back and picking colours again.</div>';
      }
      SG.setStatus(
        (out && out.data && out.data.message) || "Font generation failed.",
        "err",
      );
    }
  };

  // Register step 2 enter callback
  SG.onStep2Enter = SG.autoAssignFonts;
})();
