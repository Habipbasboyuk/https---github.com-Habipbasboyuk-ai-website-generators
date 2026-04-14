(function () {
  const root = document.querySelector("[data-aisb-style-guide]");
  if (!root) return;

  const projectId =
    parseInt(root.getAttribute("data-project-id") || "0", 10) || 0;

  /* ─── Element refs ─────────────────────────────────────────── */
  const elStatus = root.querySelector("[data-aisb-sg-status]");
  const elSwatches = root.querySelector("[data-aisb-sg-swatches]");
  const elType = root.querySelector("[data-aisb-sg-type]");
  const elComponents = root.querySelector("[data-aisb-sg-components]");
  const btnGenerate = root.querySelector("[data-aisb-sg-generate]");
  const btnSave = root.querySelector("[data-aisb-sg-save]");

  // Logo-modus
  const logoInput = root.querySelector("[data-aisb-sg-logo-input]");
  const logoPreview = root.querySelector("[data-aisb-sg-logo-preview]");
  const dropzone = root.querySelector("[data-aisb-sg-dropzone]");
  const browseLink = root.querySelector("[data-aisb-sg-browse]");
  const extractedContainer = root.querySelector("[data-aisb-sg-extracted]");
  const extractedSwatches = root.querySelector(
    "[data-aisb-sg-extracted-swatches]",
  );
  const uploadPlaceholder = root.querySelector(
    "[data-aisb-sg-upload-placeholder]",
  );

  // Handmatige modus
  const primaryPicker = root.querySelector("[data-aisb-sg-primary-picker]");
  const primaryHex = root.querySelector("[data-aisb-sg-primary-hex]");
  const manualSwatches = root.querySelector("[data-aisb-sg-manual-swatches]");

  // Live preview
  const previewEl = root.querySelector("[data-aisb-sg-preview]");

  let guide = {};
  let currentMode = "logo";
  let extractedColours = [];

  /* ─── Helpers ──────────────────────────────────────────────── */

  function setStatus(msg, kind) {
    if (!elStatus) return;
    elStatus.innerHTML = msg
      ? `<span class="${kind === "err" ? "aisb-error" : "aisb-ok"}">${escHtml(msg)}</span>`
      : "";
  }

  function escHtml(str) {
    return String(str || "").replace(
      /[&<>"']/g,
      (s) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[s],
    );
  }

  function qs(obj) {
    return Object.keys(obj)
      .map((k) => encodeURIComponent(k) + "=" + encodeURIComponent(obj[k]))
      .join("&");
  }

  async function post(action, data) {
    const res = await fetch(AISB_SG.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: qs(Object.assign({ action, nonce: AISB_SG.nonce }, data || {})),
    });
    return res.json();
  }

  /* ─── Kleur-hulpfuncties ───────────────────────────────────── */

  function rgbToHex(r, g, b) {
    return "#" + [r, g, b].map((v) => v.toString(16).padStart(2, "0")).join("");
  }

  function hexToHSL(hex) {
    let r = parseInt(hex.slice(1, 3), 16) / 255,
      g = parseInt(hex.slice(3, 5), 16) / 255,
      b = parseInt(hex.slice(5, 7), 16) / 255;
    const max = Math.max(r, g, b),
      min = Math.min(r, g, b);
    let h,
      s,
      l = (max + min) / 2;
    if (max === min) {
      h = s = 0;
    } else {
      const d = max - min;
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      h =
        max === r
          ? ((g - b) / d + (g < b ? 6 : 0)) / 6
          : max === g
            ? ((b - r) / d + 2) / 6
            : ((r - g) / d + 4) / 6;
    }
    return {
      h: Math.round(h * 360),
      s: Math.round(s * 100),
      l: Math.round(l * 100),
    };
  }

  function hslToHex(h, s, l) {
    s /= 100;
    l /= 100;
    const a = s * Math.min(l, 1 - l);
    const f = (n) => {
      const k = (n + h / 30) % 12;
      return l - a * Math.max(-1, Math.min(k - 3, 9 - k, 1));
    };
    return rgbToHex(
      Math.round(f(0) * 255),
      Math.round(f(8) * 255),
      Math.round(f(4) * 255),
    );
  }

  /* ─── Harmonie-algoritme (zonder logo) ─────────────────────── */

  function generateHarmony(hex) {
    const hsl = hexToHSL(hex);
    return [
      { name: "Primary", hex: hex },
      {
        name: "Light",
        hex: hslToHex(
          hsl.h,
          Math.max(hsl.s - 15, 10),
          Math.min(hsl.l + 30, 95),
        ),
      },
      {
        name: "Dark",
        hex: hslToHex(
          hsl.h,
          Math.min(hsl.s + 10, 100),
          Math.max(hsl.l - 25, 10),
        ),
      },
      { name: "Complement", hex: hslToHex((hsl.h + 180) % 360, hsl.s, hsl.l) },
      { name: "Accent", hex: hslToHex((hsl.h + 30) % 360, hsl.s, hsl.l) },
      { name: "Neutral", hex: hslToHex(hsl.h, 8, 92) },
    ];
  }

  /* ─── Sub-tab switching ────────────────────────────────────── */

  const panels = root.querySelectorAll("[data-sg-panel]");
  const buttons = root.querySelectorAll("[data-sg-mode]");

  root.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-sg-mode]");
    if (!btn) return;

    // Bepaal welke modus we willen tonen
    const mode = btn.dataset.sgMode;

    // Update buttons & panels in één klap via data-attributes
    buttons.forEach((b) => b.classList.toggle("is-active", b === btn));
    panels.forEach((p) => (p.hidden = p.dataset.sgPanel !== mode));
  });

  /* ─── Logo upload + ColorThief ─────────────────────────────── */

  browseLink &&
    browseLink.addEventListener("click", (e) => {
      e.preventDefault();
      logoInput && logoInput.click();
    });

  if (dropzone) {
    dropzone.addEventListener("dragover", (e) => {
      e.preventDefault();
      dropzone.classList.add("drag-over");
    });
    dropzone.addEventListener("dragleave", () =>
      dropzone.classList.remove("drag-over"),
    );
    dropzone.addEventListener("drop", (e) => {
      e.preventDefault();
      dropzone.classList.remove("drag-over");
      if (e.dataTransfer.files[0]) handleLogoFile(e.dataTransfer.files[0]);
    });
  }

  logoInput &&
    logoInput.addEventListener("change", () => {
      if (logoInput.files[0]) handleLogoFile(logoInput.files[0]);
    });

  function handleLogoFile(file) {
    if (!file.type.startsWith("image/")) {
      setStatus("Please upload an image file.", "err");
      return;
    }
    const url = URL.createObjectURL(file);
    if (logoPreview) {
      logoPreview.src = url;
      logoPreview.style.display = "block";
    }
    if (uploadPlaceholder) uploadPlaceholder.style.display = "none";

    // Wacht tot afbeelding geladen is, haal kleuren eruit
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = () => {
      try {
        const ct = new ColorThief();
        const palette = ct.getPalette(img, 6);
        const names = [
          "Primary",
          "Secondary",
          "Accent",
          "Dark",
          "Light",
          "Neutral",
        ];
        extractedColours = palette.map((rgb, i) => ({
          name: names[i] || "Colour " + (i + 1),
          hex: rgbToHex(rgb[0], rgb[1], rgb[2]),
        }));
        renderSwatchRow(extractedSwatches, extractedColours);
        if (extractedContainer) extractedContainer.style.display = "";
        applyLivePreview(extractedColours);
        setStatus(
          "Colours extracted from logo. Click Generate to get font pairing.",
          "ok",
        );
      } catch (err) {
        setStatus("Could not extract colours: " + err.message, "err");
      }
    };
    img.src = url;
  }

  /* ─── Handmatige kleurkiezer ───────────────────────────────── */

  primaryPicker && primaryPicker.addEventListener("input", onManualColorChange);

  primaryHex &&
    primaryHex.addEventListener("input", () => {
      if (/^#[0-9a-f]{6}$/i.test(primaryHex.value)) {
        primaryPicker.value = primaryHex.value;
        onManualColorChange();
      }
    });

  function onManualColorChange() {
    const hex = primaryPicker.value;
    if (primaryHex) primaryHex.value = hex;
    const palette = generateHarmony(hex);
    renderSwatchRow(manualSwatches, palette);
    applyLivePreview(palette);
    extractedColours = palette;
  }

  /* ─── Live Preview via CSS-variabelen ──────────────────────── */

  function applyLivePreview(colours) {
    if (!previewEl || !colours.length) return;
    const find = (name) => (colours.find((c) => c.name === name) || {}).hex;
    const vars = {
      "--aisb-primary": colours[0] ? colours[0].hex : null,
      "--aisb-light": find("Light") || (colours[1] ? colours[1].hex : null),
      "--aisb-dark": find("Dark") || (colours[3] ? colours[3].hex : null),
      "--aisb-accent": find("Accent") || (colours[2] ? colours[2].hex : null),
      "--aisb-neutral": find("Neutral") || "#f5f5f5",
    };
    Object.entries(vars).forEach(([k, v]) => {
      if (v) previewEl.style.setProperty(k, v);
    });

    // Pas typografie toe op preview als beschikbaar
    if (guide.headingFont) {
      const headings = previewEl.querySelectorAll(
        "h1, h2, h3, h4, .aisb-lp-h1, .aisb-lp-h2, .aisb-lp-cardtitle",
      );
      headings.forEach(
        (el) => (el.style.fontFamily = guide.headingFont + ", sans-serif"),
      );
    }
    if (guide.bodyFont) {
      const bodies = previewEl.querySelectorAll(
        "p, .aisb-lp-p, .aisb-lp-cardtxt, .aisb-lp-eyebrow",
      );
      bodies.forEach(
        (el) => (el.style.fontFamily = guide.bodyFont + ", sans-serif"),
      );
    }
  }

  /* ─── Wireframe skeleton renderer voor live preview ────────── */

  function defaultPreviewSchema(type) {
    const t = (type || "generic").toString();
    if (t === "hero") {
      return {
        type: t,
        elements: [
          { tag: "eyebrow", text: "Tagline" },
          { tag: "h1", text: "A clear headline that explains what you do" },
          {
            tag: "p",
            text: "A short paragraph that supports the headline and nudges the visitor to take action.",
          },
          { tag: "button", text: "Primary action" },
          { tag: "button", text: "Secondary action", variant: "secondary" },
          { tag: "media", text: "Hero image" },
        ],
      };
    }
    if (t === "features" || t === "values" || t === "social_proof") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "Why choose us" },
          { tag: "p", text: "A one-liner that frames the feature grid." },
          {
            tag: "cards",
            count: 3,
            item: { title: "Feature title", text: "Short description." },
          },
        ],
      };
    }
    if (t === "testimonials") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "What customers say" },
          {
            tag: "cards",
            count: 2,
            item: {
              title: "Name · Company",
              text: '"A short testimonial quote."',
            },
          },
        ],
      };
    }
    if (t === "pricing") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "Pricing" },
          { tag: "p", text: "Pick the plan that matches your needs." },
          {
            tag: "cards",
            count: 3,
            item: { title: "Plan name", text: "Key benefits and price." },
          },
        ],
      };
    }
    if (t === "faq") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "Frequently asked questions" },
          {
            tag: "list",
            count: 3,
            item: { title: "Question?", text: "Short answer." },
          },
        ],
      };
    }
    if (t === "cta") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "Ready to get started?" },
          {
            tag: "p",
            text: "A clear CTA line that tells people what happens next.",
          },
          { tag: "button", text: "Get in touch" },
        ],
      };
    }
    if (t === "footer") {
      return {
        type: t,
        elements: [{ tag: "p", text: "© Company · Links · Contact" }],
      };
    }
    return {
      type: t,
      elements: [
        { tag: "h2", text: "Section headline" },
        {
          tag: "p",
          text: "Short supporting copy that explains the value proposition.",
        },
        { tag: "button", text: "Call to action" },
      ],
    };
  }

  function renderSectionSkeleton(schema) {
    const sc = schema || defaultPreviewSchema("generic");
    const type = (sc.type || "generic").toString();
    const els = Array.isArray(sc.elements) ? sc.elements : [];

    if (type === "hero") {
      const eyebrow =
        (els.find((e) => e.tag === "eyebrow") || {}).text || "Tagline";
      const h1 =
        (els.find((e) => e.tag === "h1") || {}).text || "Hero headline";
      const p =
        (els.find((e) => e.tag === "p") || {}).text || "Supporting paragraph.";
      const buttons = els.filter((e) => e.tag === "button");
      const primary = buttons[0] ? buttons[0].text : "Primary action";
      const secondary = buttons[1] ? buttons[1].text : "";
      return (
        '<div class="aisb-lp-section aisb-lp-hero">' +
        '<div class="aisb-lp-hero-left">' +
        '<div class="aisb-lp-eyebrow">' +
        escHtml(eyebrow) +
        "</div>" +
        '<h1 class="aisb-lp-h1">' +
        escHtml(h1) +
        "</h1>" +
        '<p class="aisb-lp-p">' +
        escHtml(p) +
        "</p>" +
        '<div class="aisb-lp-btn-row">' +
        '<span class="aisb-lp-btn primary">' +
        escHtml(primary) +
        "</span>" +
        (secondary
          ? '<span class="aisb-lp-btn">' + escHtml(secondary) + "</span>"
          : "") +
        "</div>" +
        "</div>" +
        '<div class="aisb-lp-hero-right"><div class="aisb-lp-media">Image</div></div>' +
        "</div>"
      );
    }

    var out = '<div class="aisb-lp-section aisb-lp-' + escHtml(type) + '">';
    for (var i = 0; i < els.length; i++) {
      var e = els[i];
      if (!e || !e.tag) continue;
      if (e.tag === "h1" || e.tag === "h2") {
        out +=
          '<h2 class="aisb-lp-h2">' + escHtml(e.text || "Heading") + "</h2>";
      } else if (e.tag === "h3" || e.tag === "h4") {
        out +=
          '<h3 class="aisb-lp-h3">' + escHtml(e.text || "Subheading") + "</h3>";
      } else if (e.tag === "p") {
        out += '<p class="aisb-lp-p">' + escHtml(e.text || "") + "</p>";
      } else if (e.tag === "eyebrow") {
        out +=
          '<div class="aisb-lp-eyebrow">' + escHtml(e.text || "") + "</div>";
      } else if (e.tag === "button") {
        out +=
          '<span class="aisb-lp-btn ' +
          (e.variant === "secondary" ? "" : "primary") +
          '">' +
          escHtml(e.text || "Button") +
          "</span>";
      } else if (e.tag === "media") {
        out +=
          '<div class="aisb-lp-media">' + escHtml(e.text || "Media") + "</div>";
      } else if (e.tag === "cards") {
        var n = Math.max(1, Math.min(4, parseInt(e.count || 3, 10) || 3));
        out += '<div class="aisb-lp-cards">';
        for (var j = 0; j < n; j++) {
          out +=
            '<div class="aisb-lp-card">' +
            '<div class="aisb-lp-cardtitle">' +
            escHtml((e.item && e.item.title) || "Card") +
            "</div>" +
            '<div class="aisb-lp-cardtxt">' +
            escHtml((e.item && e.item.text) || "") +
            "</div>" +
            "</div>";
        }
        out += "</div>";
      } else if (e.tag === "list") {
        var m = Math.max(1, Math.min(4, parseInt(e.count || 3, 10) || 3));
        out += '<div class="aisb-lp-list">';
        for (var k = 0; k < m; k++) {
          out +=
            '<div class="aisb-lp-listitem">' +
            '<div class="aisb-lp-cardtitle">' +
            escHtml((e.item && e.item.title) || "Item") +
            "</div>" +
            '<div class="aisb-lp-cardtxt">' +
            escHtml((e.item && e.item.text) || "") +
            "</div>" +
            "</div>";
        }
        out += "</div>";
      }
    }
    out += "</div>";
    return out;
  }

  let wireframeSections = [];

  async function loadWireframeSections() {
    if (!projectId || !previewEl) return;
    const out = await post("aisb_get_wireframe_sections", {
      project_id: projectId,
    });
    if (out && out.success && out.data.sections && out.data.sections.length) {
      wireframeSections = out.data.sections;
      renderWireframePreview();
    } else {
      // Geen wireframe gevonden — toon melding
      previewEl.innerHTML =
        '<div class="aisb-sg-empty-state">No wireframe found for this project. Generate wireframes in Step 2 first.</div>';
    }
  }

  function renderWireframePreview() {
    if (!previewEl || !wireframeSections.length) return;
    var html = "";
    // Toon maximaal 4 secties in de preview
    var max = Math.min(wireframeSections.length, 4);
    for (var i = 0; i < max; i++) {
      var s = wireframeSections[i];
      var schema =
        s.preview_schema && typeof s.preview_schema === "object"
          ? s.preview_schema
          : defaultPreviewSchema(s.type || "generic");
      html += renderSectionSkeleton(schema);
    }
    if (wireframeSections.length > 4) {
      html +=
        '<div class="aisb-lp-more">+ ' +
        (wireframeSections.length - 4) +
        " more sections</div>";
    }
    previewEl.innerHTML = html;
    // Herapplliceer kleuren als die er al zijn
    if (extractedColours.length) applyLivePreview(extractedColours);
    else if (guide.colours && guide.colours.length)
      applyLivePreview(guide.colours);
  }

  /* ─── Render helpers ───────────────────────────────────────── */

  function renderSwatchRow(container, colours) {
    if (!container) return;
    if (!Array.isArray(colours) || !colours.length) {
      container.innerHTML =
        '<div class="aisb-sg-empty-state">No colours defined yet.</div>';
      return;
    }
    container.innerHTML = colours
      .map(
        (c) =>
          '<div class="aisb-sg-swatch">' +
          '<div class="aisb-sg-swatch-block" style="background:' +
          escHtml(c.hex || "#ccc") +
          ';"></div>' +
          '<div class="aisb-sg-swatch-label">' +
          escHtml(c.name || c.hex || "") +
          "</div>" +
          '<div class="aisb-sg-swatch-hex">' +
          escHtml(c.hex || "") +
          "</div>" +
          "</div>",
      )
      .join("");
  }

  function renderSwatches(colours) {
    renderSwatchRow(elSwatches, colours);
  }

  function renderTypography(typeScale) {
    if (!elType) return;
    if (!Array.isArray(typeScale) || !typeScale.length) {
      elType.innerHTML =
        '<div class="aisb-sg-empty-state">No typography defined yet.</div>';
      return;
    }
    elType.innerHTML = typeScale
      .map(
        (t) =>
          '<div class="aisb-sg-type-row">' +
          '<div class="aisb-sg-type-meta">' +
          escHtml(t.label || "") +
          "</div>" +
          '<div class="aisb-sg-type-sample ' +
          escHtml(t.cls || "body") +
          '"' +
          (t.fontFamily
            ? ' style="font-family:' + escHtml(t.fontFamily) + ', sans-serif;"'
            : "") +
          ">" +
          escHtml(t.sample || t.label || "The quick brown fox") +
          "</div>" +
          "</div>",
      )
      .join("");
  }

  function renderComponents(components) {
    if (!elComponents) return;
    if (!Array.isArray(components) || !components.length) {
      elComponents.innerHTML =
        '<div class="aisb-sg-empty-state">No components defined yet.</div>';
      return;
    }
    elComponents.innerHTML = components
      .map(
        (c) =>
          '<div class="aisb-sg-component-card">' +
          '<div class="aisb-sg-component-name">' +
          escHtml(c.name || "") +
          "</div>" +
          '<div class="aisb-sg-component-preview">' +
          (c.preview_html || "") +
          "</div>" +
          "</div>",
      )
      .join("");
  }

  function renderGuide() {
    renderSwatches(guide.colours || []);
    renderTypography(guide.typography || []);
    renderComponents(guide.components || []);
    applyLivePreview(guide.colours || extractedColours);
  }

  /* ─── Google Fonts laden ───────────────────────────────────── */

  function loadGoogleFonts(heading, body) {
    const families = [heading, body]
      .filter(Boolean)
      .map((f) => f.replace(/ /g, "+"));
    if (!families.length) return;
    const link = document.createElement("link");
    link.rel = "stylesheet";
    link.href =
      "https://fonts.googleapis.com/css2?" +
      families.map((f) => "family=" + f + ":wght@400;600;700").join("&") +
      "&display=swap";
    document.head.appendChild(link);
  }

  /* ─── Load ─────────────────────────────────────────────────── */

  async function loadGuide() {
    if (!projectId) return;
    const out = await post("aisb_get_style_guide", { project_id: projectId });
    if (out && out.success) {
      guide = out.data.style_guide || {};
      if (guide.headingFont || guide.bodyFont) {
        loadGoogleFonts(guide.headingFont, guide.bodyFont);
      }
      if (guide.colours && guide.colours.length) {
        extractedColours = guide.colours;
      }
      renderGuide();
    } else {
      setStatus("Could not load style guide.", "err");
    }
  }

  /* ─── Generate button ──────────────────────────────────────── */

  btnGenerate &&
    btnGenerate.addEventListener("click", async () => {
      if (!extractedColours.length) {
        setStatus("Upload a logo or pick a primary colour first.", "err");
        return;
      }
      setStatus("Generating font pairing via AI…", "ok");
      btnGenerate.disabled = true;

      const out = await post("aisb_generate_style_guide", {
        project_id: projectId,
        colours: JSON.stringify(extractedColours),
      });

      btnGenerate.disabled = false;

      if (out && out.success) {
        const data = out.data;
        guide.colours = data.colours || extractedColours;
        guide.headingFont = data.fonts.heading_font || "";
        guide.bodyFont = data.fonts.body_font || "";
        guide.typography = data.fonts.type_scale || [];
        guide.components = guide.components || [];

        loadGoogleFonts(guide.headingFont, guide.bodyFont);
        renderGuide();
        setStatus("Style guide generated! Review and click Save & Lock.", "ok");
      } else {
        setStatus(
          (out && out.data && out.data.message) || "Generation failed.",
          "err",
        );
      }
    });

  /* ─── Save button ──────────────────────────────────────────── */

  btnSave &&
    btnSave.addEventListener("click", async () => {
      if (!guide.colours || !guide.colours.length) {
        setStatus("Nothing to save yet. Generate a style guide first.", "err");
        return;
      }
      setStatus("Saving…", "ok");
      const out = await post("aisb_save_style_guide", {
        project_id: projectId,
        style_guide_json: JSON.stringify(guide),
      });
      if (out && out.success) {
        setStatus("Style guide saved & locked.", "ok");
      } else {
        setStatus(
          (out && out.data && out.data.message) || "Save failed.",
          "err",
        );
      }
    });

  /* ─── Init ─────────────────────────────────────────────────── */

  loadGuide();
  loadWireframeSections();
})();
