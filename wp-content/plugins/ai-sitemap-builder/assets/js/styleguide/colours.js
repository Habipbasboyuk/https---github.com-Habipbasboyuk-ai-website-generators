(function () {
  "use strict";

  const SG = window.AISB_StyleGuide;
  if (!SG) return;

  const el = SG.el;

  /* ─── Kleur-hulpfuncties ───────────────────────────────────── */

  // zet RGB-waarden om naar hex-string (#rrggbb)
  function rgbToHex(r, g, b) {
    return (
      "#" +
      [r, g, b]
        .map(function (v) {
          // elk kanaal naar 2-cijferig hex
          return v.toString(16).padStart(2, "0");
        })
        .join("")
    );
  }

  // zet hex-kleur om naar HSL-object { h, s, l }
  function hexToHSL(hex) {
    // hex naar genormaliseerde RGB (0-1)
    const r = parseInt(hex.slice(1, 3), 16) / 255,
      g = parseInt(hex.slice(3, 5), 16) / 255,
      b = parseInt(hex.slice(5, 7), 16) / 255;
    const max = Math.max(r, g, b),
      min = Math.min(r, g, b);
    let h,
      s,
      l = (max + min) / 2;
    if (max === min) {
      // grijstint — geen tint of verzadiging
      h = s = 0;
    } else {
      const d = max - min;
      // verzadiging berekenen op basis van lichtheid
      s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
      // tint berekenen op basis van dominant kanaal
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

  // zet HSL-waarden om naar hex-string (#rrggbb)
  function hslToHex(h, s, l) {
    s /= 100;
    l /= 100;
    const a = s * Math.min(l, 1 - l);
    // hulpfunctie: berekent kanaalwaarde per kleurcomponent
    const f = function (n) {
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

  // genereert 6 harmonieuze kleuren op basis van één hex-kleur
  function generateHarmony(hex) {
    const hsl = hexToHSL(hex);
    return [
      { name: "Primary", hex: hex },
      {
        name: "Light",
        hex: hslToHex(
          hsl.h,
          Math.max(hsl.s - 15, 10), // minder verzadigd
          Math.min(hsl.l + 30, 95), // lichter
        ),
      },
      {
        name: "Dark",
        hex: hslToHex(
          hsl.h,
          Math.min(hsl.s + 10, 100), // meer verzadigd
          Math.max(hsl.l - 25, 10), // donkerder
        ),
      },
      { name: "Complement", hex: hslToHex((hsl.h + 180) % 360, hsl.s, hsl.l) }, // tegenovergestelde kleur
      { name: "Accent", hex: hslToHex((hsl.h + 30) % 360, hsl.s, hsl.l) }, // naastgelegen kleur
      { name: "Neutral", hex: hslToHex(hsl.h, 8, 92) }, // bijna grijs
    ];
  }

  /* ─── Sub-tab wisselen (logo / handmatig) ──────────────────── */

  const panels = SG.root.querySelectorAll("[data-colour-panel]");
  const modeButtons = SG.root.querySelectorAll("[data-colour-mode]");

  // klik-delegatie: wisselt tussen logo- en handmatig-paneel
  SG.root.addEventListener("click", function (e) {
    const btn = e.target.closest("[data-colour-mode]");
    if (!btn) return;
    const mode = btn.dataset.colourMode;
    // actieve knop markeren
    modeButtons.forEach(function (b) {
      b.classList.toggle("is-active", b === btn);
    });
    // juiste paneel tonen, rest verbergen
    panels.forEach(function (p) {
      p.style.display = p.dataset.colourPanel === mode ? "" : "none";
    });
  });

  /* ─── Logo-upload + ColorThief ─────────────────────────────── */

  // klik op "Bladeren"-link opent verborgen file-input
  el.browseLink &&
    el.browseLink.addEventListener("click", function (e) {
      e.preventDefault();
      el.logoInput && el.logoInput.click();
    });

  // drag & drop zone voor logo-bestanden
  if (el.dropzone) {
    el.dropzone.addEventListener("dragover", function (e) {
      e.preventDefault();
      el.dropzone.classList.add("drag-over"); // visuele feedback
    });
    el.dropzone.addEventListener("dragleave", function () {
      el.dropzone.classList.remove("drag-over");
    });
    el.dropzone.addEventListener("drop", function (e) {
      e.preventDefault();
      el.dropzone.classList.remove("drag-over");
      // eerste bestand doorsturen naar handler
      if (e.dataTransfer.files[0]) handleLogoFile(e.dataTransfer.files[0]);
    });
  }

  // file-input change: geselecteerd bestand doorsturen
  el.logoInput &&
    el.logoInput.addEventListener("change", function () {
      if (el.logoInput.files[0]) handleLogoFile(el.logoInput.files[0]);
    });

  // verwerkt logo-bestand: toont preview, extraheert kleuren met ColorThief
  function handleLogoFile(file) {
    // alleen afbeeldingen toestaan
    if (!file.type.startsWith("image/")) {
      SG.setStatus("Please upload an image file.", "err");
      return;
    }
    // blob-URL aanmaken voor lokale weergave
    const url = URL.createObjectURL(file);
    if (el.logoPreview) {
      el.logoPreview.src = url;
      el.logoPreview.style.display = "block";
    }
    if (el.uploadPlaceholder) el.uploadPlaceholder.style.display = "none";

    // onzichtbare afbeelding laden voor ColorThief
    const img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = function () {
      try {
        // 6 dominante kleuren extraheren uit afbeelding
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
        // RGB-arrays omzetten naar { name, hex } objecten
        SG.extractedColours = palette.map(function (rgb, i) {
          return {
            name: names[i] || "Colour " + (i + 1),
            hex: rgbToHex(rgb[0], rgb[1], rgb[2]),
          };
        });
        // kleurstalen renderen in de UI
        SG.renderSwatchRow(el.extractedSwatches, SG.extractedColours);
        if (el.extractedContainer) el.extractedContainer.style.display = "";
        // vlag resetten zodat typografie opnieuw berekend wordt
        SG.fontsAssigned = false;
        // CSS-overrides in alle iframes bijwerken
        SG.applyOverridesToAllIframes();
        SG.setStatus(
          "Colours extracted from logo. Click Next to continue.",
          "ok",
        );
      } catch (err) {
        SG.setStatus("Could not extract colours: " + err.message, "err");
      }
    };
    img.src = url;
  }

  /* ─── Handmatige kleurkiezer ───────────────────────────────── */

  // bij elke wijziging van de kleurkiezer: harmonie herberekenen
  el.primaryPicker &&
    el.primaryPicker.addEventListener("input", onManualColorChange);

  // hex-invoerveld: synchroniseert met kleurkiezer als geldig hex
  el.primaryHex &&
    el.primaryHex.addEventListener("input", function () {
      if (/^#[0-9a-f]{6}$/i.test(el.primaryHex.value)) {
        el.primaryPicker.value = el.primaryHex.value;
        onManualColorChange();
      }
    });

  // handmatige kleurwijziging: genereert harmoniepalet en past overrides toe
  function onManualColorChange() {
    const hex = el.primaryPicker.value;
    // hex-veld synchroniseren met picker
    if (el.primaryHex) el.primaryHex.value = hex;
    // harmoniepalet genereren vanuit gekozen kleur
    const palette = generateHarmony(hex);
    SG.renderSwatchRow(el.manualSwatches, palette);
    SG.extractedColours = palette;
    SG.fontsAssigned = false;
    SG.applyOverridesToAllIframes();
  }

  /* ─── Inline kleur aanpassen ─────────────────────────────────── */

  // klik op edit-icoon opent de native kleurkiezer
  SG.root.addEventListener("click", function (e) {
    const editBtn = e.target.closest("[data-edit-idx]");
    if (!editBtn) return;
    const picker = editBtn.parentElement.querySelector(".aisb-sg-swatch-picker");
    if (picker) picker.click();
  });

  // kleur gewijzigd via native picker
  SG.root.addEventListener("input", function (e) {
    const picker = e.target.closest(".aisb-sg-swatch-picker");
    if (!picker || !SG.extractedColours) return;
    const idx = parseInt(picker.dataset.pickerIdx, 10);
    if (isNaN(idx) || !SG.extractedColours[idx]) return;

    // kleur bijwerken
    SG.extractedColours[idx].hex = picker.value;

    // swatch-blok direct bijwerken (zonder volledige re-render)
    const block = picker.closest(".aisb-sg-swatch-block");
    if (block) block.style.background = picker.value;
    // hex-label bijwerken
    const swatch = picker.closest(".aisb-sg-swatch");
    if (swatch) {
      const hexLabel = swatch.querySelector(".aisb-sg-swatch-hex");
      if (hexLabel) hexLabel.textContent = picker.value;
    }

    // wireframes bijwerken
    SG.applyOverridesToAllIframes();
  });

  // bij sluiten van picker: volledige re-render zodat alles klopt
  SG.root.addEventListener("change", function (e) {
    const picker = e.target.closest(".aisb-sg-swatch-picker");
    if (!picker || !SG.extractedColours) return;
    const idx = parseInt(picker.dataset.pickerIdx, 10);
    if (isNaN(idx) || !SG.extractedColours[idx]) return;
    SG.extractedColours[idx].hex = picker.value;
    // re-render alle swatch-containers
    [el.extractedSwatches, el.manualSwatches].forEach(function (container) {
      if (container) SG.renderSwatchRow(container, SG.extractedColours);
    });
    SG.fontsAssigned = false;
    SG.applyOverridesToAllIframes();
  });

  /* ─── Lock / Shuffle ───────────────────────────────────────── */

  // lock-klik delegatie op hele root
  SG.root.addEventListener("click", function (e) {
    const lockBtn = e.target.closest("[data-lock-idx]");
    if (!lockBtn) return;
    const idx = parseInt(lockBtn.dataset.lockIdx, 10);
    if (SG.lockedColours.has(idx)) {
      SG.lockedColours.delete(idx);
    } else {
      SG.lockedColours.add(idx);
    }
    // re-render om lock-visueel bij te werken
    const swatchContainer = lockBtn.closest(".aisb-sg-swatches");
    if (swatchContainer && SG.extractedColours) {
      SG.renderSwatchRow(swatchContainer, SG.extractedColours);
    }
  });

  // willekeurige kleur genereren
  function randomHex() {
    return (
      "#" +
      Math.floor(Math.random() * 16777215)
        .toString(16)
        .padStart(6, "0")
    );
  }

  // slimme shuffle: genereert harmonieuze kleuren voor ongelockte posities
  function shuffleUnlocked() {
    if (!SG.extractedColours || !SG.extractedColours.length) return;

    // kies een willekeurige basis-hue
    const baseHue = Math.floor(Math.random() * 360);
    const baseSat = 50 + Math.floor(Math.random() * 40); // 50-90
    const baseLit = 40 + Math.floor(Math.random() * 20); // 40-60

    // genereer een harmonieus palet op basis van random hue
    const harmonyBase = hslToHex(baseHue, baseSat, baseLit);
    const harmony = generateHarmony(harmonyBase);

    // als logo-modus (6 kleuren, andere namen): maak ook varianten
    const logoNames = [
      "Primary",
      "Secondary",
      "Accent",
      "Dark",
      "Light",
      "Neutral",
    ];
    const logoHarmony = [
      { name: "Primary", hex: harmonyBase },
      {
        name: "Secondary",
        hex: hslToHex((baseHue + 40) % 360, baseSat, baseLit),
      },
      {
        name: "Accent",
        hex: hslToHex((baseHue + 180) % 360, baseSat, baseLit),
      },
      {
        name: "Dark",
        hex: hslToHex(
          baseHue,
          Math.min(baseSat + 10, 100),
          Math.max(baseLit - 25, 10),
        ),
      },
      {
        name: "Light",
        hex: hslToHex(
          baseHue,
          Math.max(baseSat - 15, 10),
          Math.min(baseLit + 30, 95),
        ),
      },
      { name: "Neutral", hex: hslToHex(baseHue, 8, 92) },
    ];

    // bepaal of dit logo- of handmatig-modus is op basis van bestaande namen
    const isLogoMode = SG.extractedColours.some(function (c) {
      return c.name === "Secondary";
    });
    const source = isLogoMode ? logoHarmony : harmony;

    SG.extractedColours.forEach(function (colour, i) {
      if (SG.lockedColours.has(i)) return; // gelockte kleuren overslaan
      if (source[i]) {
        colour.hex = source[i].hex;
      }
    });

    // re-render alle swatch-containers
    [el.extractedSwatches, el.manualSwatches].forEach(function (container) {
      if (container) SG.renderSwatchRow(container, SG.extractedColours);
    });

    SG.fontsAssigned = false;
    SG.applyOverridesToAllIframes();
  }

  // shuffle-knop delegatie
  SG.root.addEventListener("click", function (e) {
    if (e.target.closest("[data-shuffle-colours]")) {
      e.preventDefault();
      shuffleUnlocked();
    }
  });
})();
