// core.js — Hoofdmodule van de Style Guide wizard
// Dit bestand bevat de gedeelde state, wizard-navigatie,
// live preview (CSS-overrides in iframes), de wireframe canvas-bouwer
// met pan/zoom functionaliteit, en render-helpers.
//
// Hulpfuncties (setStatus, escapeHtml, toQueryString, post) staan in helpers.js
//
// Wordt geladen als eerste script in de keten:
//   core.js → helpers.js → colours.js / typography.js / images.js → init.js
//
// Alle andere scripts lezen en schrijven via window.AISB_StyleGuide (alias SG).
// WordPress geeft via wp_localize_script het object AISB_SG mee met:
//   ajaxUrl  — URL van admin-ajax.php
//   nonce    — beveiligingstoken voor AJAX-verzoeken
//   previewUrl — basis-URL voor Bricks iframe-previews

(function () {
  "use strict";

  // zoek het root-element van de style guide wizard in de DOM
  // als het element niet bestaat, is de wizard niet actief en stoppen we direct
  const root = document.querySelector("[data-styleguide]");
  if (!root) return;

  // lees het project-ID uit het data-attribuut (0 als niet aanwezig)
  // dit ID wordt bij elk AJAX-verzoek meegestuurd om het juiste project te laden
  const projectId =
    parseInt(root.getAttribute("data-styleguide-project") || "0", 10) || 0;

  // SG = het centrale state-object dat door alle scripts wordt gedeeld
  // bevat de huidige wizard-status, DOM-referenties en geëxtraheerde data
  const SG = {
    root,
    projectId,
    guide: {},
    extractedColours: [],
    wireframePages: [],
    allIframes: [],
    currentWizardStep: 1,
    fontsAssigned: false,
    fontsLoading: false,
    imagesLoaded: false,

    // el = verzameling van alle DOM-elementen die de wizard nodig heeft
    // elke property is een querySelector op het root-element
    el: {
      status: root.querySelector("[data-status-bar]"),
      typographyDisplay: root.querySelector("[data-typography-preview]"),
      saveButton: root.querySelector("[data-save-button]"),
      typographyStatus: root.querySelector("[data-typography-status]"),
      typographyResult: root.querySelector("[data-typography-result]"),
      logoInput: root.querySelector("[data-logo-input]"),
      logoPreview: root.querySelector("[data-logo-preview]"),
      dropzone: root.querySelector("[data-logo-dropzone]"),
      browseLink: root.querySelector("[data-logo-browse]"),
      extractedContainer: root.querySelector("[data-colours-extracted]"),
      extractedSwatches: root.querySelector("[data-colours-swatches]"),
      uploadPlaceholder: root.querySelector("[data-logo-placeholder]"),
      primaryPicker: root.querySelector("[data-colour-picker]"),
      primaryHex: root.querySelector("[data-colour-hex]"),
      manualSwatches: root.querySelector("[data-manual-swatches]"),
      coloursPreview: root.querySelector("[data-preview-colours]"),
      typographyPreview: root.querySelector("[data-preview-typography]"),
      imagesPreview: root.querySelector("[data-preview-images]"),
      autoImagesContainer: root.querySelector("[data-images-grid]"),
      uploadZone: root.querySelector("[data-upload-zone]"),
      uploadInput: root.querySelector("[data-upload-input]"),
      uploadHint: root.querySelector("[data-upload-hint]"),
      uploadTotalNeeded: root.querySelector("[data-total-needed]"),
      uploadedGrid: root.querySelector("[data-uploaded-grid]"),
      uploadedGridInner: root.querySelector("[data-uploaded-grid-inner]"),
      uploadedCount: root.querySelector("[data-uploaded-count]"),
      wizardPanels: root.querySelectorAll("[data-wizard-panel]"),
      wizardStepButtons: root.querySelectorAll("[data-wizard-step]"),
    },
    onStep2Enter: null,
  };

  // maak SG beschikbaar als globale variabele zodat andere scripts
  // (helpers.js, colours.js, typography.js, images.js, init.js) erbij kunnen
  window.AISB_StyleGuide = SG;

  // hulpfuncties (setStatus, escapeHtml, toQueryString, post) staan in helpers.js

  /* ─── Wizard navigatie ─────────────────────────────────────── */

  // navigeert de wizard naar een specifieke stap (1, 2 of 3)
  // deze functie regelt drie dingen tegelijk:
  //   1. toont het juiste paneel en verbergt de andere twee
  //   2. past de actieve/voltooide stijlen toe op de stap-knoppen
  //   3. triggert de fitToView op de preview-canvas van de actieve stap
  // bij stap 2 wordt ook onStep2Enter() aangeroepen (als die bestaat)
  // zodat typography.js automatisch AI font-suggesties kan laden
  SG.goToWizardStep = function (step) {
    // zorg dat step altijd een getal is, en alleen 1-3 geldig is
    step = parseInt(step, 10);
    if (step < 1 || step > 3) return;
    SG.currentWizardStep = step;

    // toon het juiste paneel, verberg de rest
    // elk paneel heeft data-wizard-panel="1", "2" of "3"
    SG.el.wizardPanels.forEach((panel) => {
      const panelStep = parseInt(panel.getAttribute("data-wizard-panel"), 10);
      panel.style.display = panelStep === step ? "" : "none";
    });

    // markeer de actieve stap-knop en eerdere stappen als voltooid
    // is-active = huidige stap, is-completed = stappen die al gedaan zijn
    SG.el.wizardStepButtons.forEach((button) => {
      const buttonStep = parseInt(button.getAttribute("data-wizard-step"), 10);
      button.classList.toggle("is-active", buttonStep === step);
      button.classList.toggle("is-completed", buttonStep < step);
    });

    // elke stap heeft zijn eigen preview-container met een wireframe-canvas
    // als die canvas een fitToView-functie heeft, roepen we die aan
    // zodat de preview netjes in het zicht past na het wisselen van stap
    const containerForStep = [
      null,
      SG.el.coloursPreview,
      SG.el.typographyPreview,
      SG.el.imagesPreview,
    ][step];
    if (containerForStep && containerForStep._sgFitToView) {
      requestAnimationFrame(containerForStep._sgFitToView);
    }

    // bij stap 2: typografie automatisch toewijzen als dat nog niet is gebeurd
    // onStep2Enter wordt door typography.js ingesteld bij het laden
    if (step === 2 && SG.onStep2Enter) {
      SG.onStep2Enter();
    }
  };

  // koppel klik-events aan de stap-knoppen in de wizard-balk
  // elke knop heeft data-wizard-step="1", "2" of "3"
  // bij klik navigeert de wizard naar die stap
  SG.el.wizardStepButtons.forEach((button) => {
    button.addEventListener("click", () => {
      SG.goToWizardStep(button.getAttribute("data-wizard-step"));
    });
  });

  // luistert naar klikken op "Volgende" en "Vorige" knoppen binnen de panelen
  // deze knoppen hebben data-wizard-next="2" of data-wizard-prev="1"
  // event delegation op root zodat we niet per knop hoeven te luisteren
  root.addEventListener("click", (event) => {
    const nextButton = event.target.closest("[data-wizard-next]");
    if (nextButton) {
      SG.goToWizardStep(nextButton.getAttribute("data-wizard-next"));
      return;
    }
    const previousButton = event.target.closest("[data-wizard-prev]");
    if (previousButton) {
      SG.goToWizardStep(previousButton.getAttribute("data-wizard-prev"));
      return;
    }
  });

  /* ─── Live Preview — CSS override injectie ─────────────────── */

  // bouwt een CSS-string met kleur- en font-overrides voor iframe-previews
  // deze CSS wordt in elke wireframe-iframe geïnjecteerd zodat de
  // gekozen kleuren en fonts direct zichtbaar worden in de preview
  SG.buildOverrideCss = function () {
    // stap 1: bepaal welke kleuren we gebruiken
    // prioriteit: net geëxtraheerde kleuren (uit logo/picker) → opgeslagen kleuren → lege array
    const colours = SG.extractedColours.length
      ? SG.extractedColours
      : SG.guide.colours && SG.guide.colours.length
        ? SG.guide.colours
        : [];

    // zoekt een kleur op naam (bijv. "Light", "Dark") in de kleuren-array
    // geeft de hex-waarde terug, of undefined als die naam niet bestaat
    const findColourByName = (name) =>
      (colours.find((colour) => colour.name === name) || {}).hex;

    // stap 2: CSS-string opbouwen
    let css = "";

    if (colours.length) {
      // stap 3: elke kleurrol toewijzen
      // primary = altijd de eerste kleur in de array (index 0)
      const primary = colours[0] ? colours[0].hex : "";
      // light = zoek op naam "Light", fallback naar index 1
      const light =
        findColourByName("Light") || (colours[1] ? colours[1].hex : "");
      // dark = zoek op naam "Dark", fallback naar index 3
      const dark =
        findColourByName("Dark") || (colours[3] ? colours[3].hex : "");
      // accent = zoek op naam "Accent", fallback naar index 2
      const accent =
        findColourByName("Accent") || (colours[2] ? colours[2].hex : "");
      // neutral = zoek op naam "Neutral", fallback naar hardcoded lichtgrijs
      const neutral = findColourByName("Neutral") || "#f5f5f5";

      // stap 4: :root CSS custom properties voor Bricks Builder en Elementor
      css += ":root{";
      // primary kleur als Bricks én Elementor variabele
      if (primary)
        css +=
          "--bricks-color-primary:" +
          primary +
          ";--e-global-color-primary:" +
          primary +
          ";";
      // accent kleur als Bricks én Elementor variabele
      if (accent)
        css +=
          "--bricks-color-accent:" +
          accent +
          ";--e-global-color-accent:" +
          accent +
          ";";
      // dark, light en neutral alleen als Bricks variabelen
      if (dark) css += "--bricks-color-dark:" + dark + ";";
      if (light) css += "--bricks-color-light:" + light + ";";
      if (neutral) css += "--bricks-color-neutral:" + neutral + ";";

      // stap 4b: neutral kleurenschaal (brxw-color-neutral-25 t/m 900)
      // deze variabelen worden door Bricks templates gebruikt als achtergrondkleur
      // we zetten ze in BEIDE formaten: --brxw-color-neutral-X én --bricks-color-brxw-color-neutral-X
      // zodat het werkt ongeacht hoe het template ernaar verwijst
      const neutralSteps = [
        { step: "25", lightness: 0.949 },
        { step: "50", lightness: 0.875 },
        { step: "100", lightness: 0.796 },
        { step: "200", lightness: 0.718 },
        { step: "300", lightness: 0.643 },
        { step: "400", lightness: 0.565 },
        { step: "500", lightness: 0.486 },
        { step: "600", lightness: 0.408 },
        { step: "700", lightness: 0.329 },
        { step: "800", lightness: 0.255 },
        { step: "900", lightness: 0.18 },
      ];
      neutralSteps.forEach(function (ns) {
        const val = "hsla(0,0%," + (ns.lightness * 100).toFixed(1) + "%,1)";
        css += "--brxw-color-neutral-" + ns.step + ":" + val + ";";
        css += "--bricks-color-brxw-color-neutral-" + ns.step + ":" + val + ";";
      });

      css += "}";

      // stap 5: directe element-stijlen met !important (overschrijft Bricks defaults)
      if (primary) {
        // knoppen krijgen de primary kleur als achtergrond en border
        css +=
          ".brxe-button,.bricks-button{background-color:" +
          primary +
          " !important;border-color:" +
          primary +
          " !important;}";
        // alle links (behalve knoppen) krijgen de primary kleur als tekstkleur
        css += "a:not(.brxe-button){color:" + primary + " !important;}";
      }
      // heading- en body-tekstkleur wordt PER IFRAME gezet in injectOverrideIntoIframe
      // zodat we contrast kunnen berekenen t.o.v. de sectie-achtergrond
      // accent kleur: outline/secondary buttons, badges, icon-wrappers, borders
      if (accent) {
        // outline / ghost buttons → accent border + text
        css +=
          ".brxe-button.bricks-background-none,.brxe-button.outline,.brxe-button[class*=outline],.brxe-button.ghost{" +
          "background-color:transparent !important;border-color:" +
          accent +
          " !important;color:" +
          accent +
          " !important;}";
        // icon-wrappers, badges, tags → accent achtergrond
        css +=
          ".brxe-icon-svg svg,.brxe-icon svg{color:" +
          accent +
          " !important;fill:" +
          accent +
          " !important;}";
        // list-markers en decoratieve elementen
        css += ".brxe-list li::marker{color:" + accent + " !important;}";
        // borders / dividers
        css +=
          ".brxe-divider .line,.brxe-divider hr{border-color:" +
          accent +
          " !important;}";
      }
      // light kleur: lichte achtergronden, cards, form inputs
      if (light) {
        css +=
          ".brxe-pricing .bricks-button,.brxe-post-meta{color:" +
          dark +
          " !important;}";
      }
      // neutral kleur: body-tekst, alinea's
      if (neutral && neutral !== "#f5f5f5") {
        // gebruik neutral als subtiele achtergrond voor card-achtige elementen
        css +=
          "input,textarea,select,.brxe-form input,.brxe-form textarea,.brxe-form select" +
          "{background-color:" +
          neutral +
          " !important;border-color:" +
          (light || neutral) +
          " !important;}";
      }

      // sectie-achtergrondkleur wordt per iframe apart gezet in injectOverrideIntoIframe
      // even secties: white/light, oneven secties: neutral/light
    }

    // stap 6: font-family overrides voor headings
    if (SG.guide.headingFont) {
      css +=
        "h1,h2,h3,h4,h5,h6,.brxe-heading{font-family:" +
        SG.guide.headingFont +
        ",sans-serif !important;}";
    }

    // stap 7: font-family overrides voor body-tekst en Bricks tekst-elementen
    if (SG.guide.bodyFont) {
      css +=
        "body,p,.brxe-text,.brxe-text-basic,.brxe-rich-text,.brxe-post-content{font-family:" +
        SG.guide.bodyFont +
        ",sans-serif !important;}";
    }

    // geeft de volledige CSS-string terug, klaar om in een <style> tag te plaatsen
    return css;
  };

  // bouwt de Google Fonts URL voor de huidige heading- en body-font
  // wordt als <link> in de iframe-head geplaatst (niet als @import in <style>,
  // want @import moet vóór alle andere regels staan en wordt anders genegeerd)
  SG.buildGoogleFontsUrl = function () {
    const families = [SG.guide.headingFont, SG.guide.bodyFont]
      .filter(Boolean)
      .map(function (f) {
        return f.replace(/ /g, "+");
      });
    if (!families.length) return "";
    return (
      "https://fonts.googleapis.com/css2?" +
      families
        .map(function (f) {
          return "family=" + f + ":wght@400;600;700";
        })
        .join("&") +
      "&display=swap"
    );
  };

  // berekent relatieve luminantie van een hex-kleur (0 = zwart, 1 = wit)
  // gebruikt de WCAG-formule voor perceptuele helderheid
  SG.getLuminance = function (hex) {
    if (!hex || hex.length < 7) return 1; // default: licht
    let r = parseInt(hex.slice(1, 3), 16) / 255;
    let g = parseInt(hex.slice(3, 5), 16) / 255;
    let b = parseInt(hex.slice(5, 7), 16) / 255;
    // sRGB → lineair
    r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
    g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
    b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };

  // injecteert de override-CSS in een specifieke iframe
  // opent het document van de iframe, zoekt een bestaande <style id="aisb-sg-override">
  // tag (of maakt er een aan), en vult die met de CSS uit buildOverrideCss()
  // als de iframe cross-origin is (andere domeinnaam), wordt de fout stilletjes genegeerd
  SG.injectOverrideIntoIframe = function (iframe) {
    try {
      // haal het document-object van de iframe op
      const iframeDocument =
        iframe.contentDocument || iframe.contentWindow.document;
      if (!iframeDocument) return;
      // zoek de bestaande style-tag, of maak er een aan
      let style = iframeDocument.getElementById("aisb-sg-override");
      if (!style) {
        style = iframeDocument.createElement("style");
        style.id = "aisb-sg-override";
        iframeDocument.head.appendChild(style);
      }
      // vervang de CSS-inhoud met de actuele overrides
      let overrideCss = SG.buildOverrideCss();

      // stap 5b: sectie-achtergrondkleur per iframe
      // elke iframe bevat maar 1 sectie, dus we gebruiken de sectie-index
      // om even/oneven secties een andere achtergrondkleur te geven
      // sectie-achtergrondkleur per iframe: alterneer tussen wit en de light/neutral kleur uit het palet
      const sIdx =
        typeof iframe._sectionIdx === "number" ? iframe._sectionIdx : -1;
      if (sIdx >= 0) {
        // haal kleuren uit het huidige palet
        const paletteColours = SG.extractedColours.length
          ? SG.extractedColours
          : SG.guide.colours && SG.guide.colours.length
            ? SG.guide.colours
            : [];
        const findByName = function (name) {
          return (
            paletteColours.find(function (c) {
              return c.name === name;
            }) || {}
          ).hex;
        };
        const palLight = findByName("Light");
        const palNeutral = findByName("Neutral");
        // even secties: lichte paletkleur (of sectionBg1 als fallback), oneven: neutral/sectionBg2
        let secBg;
        if (sIdx % 2 === 0) {
          secBg = palLight || SG.guide.sectionBg1 || "#ffffff";
        } else {
          secBg = palNeutral || palLight || SG.guide.sectionBg2 || "#f0f4ff";
        }
        // Sectie- en container-achtergrond overschrijven met paletkleur.
        // Gebruik !important om Bricks ID-selector CSS te overrulen.
        // Uitzondering: elementen met een inline background-image behouden die.
        overrideCss +=
          ".brxe-section:not([style*='background-image'])," +
          ".brxe-container:not([style*='background-image'])," +
          ".brxe-block:not([style*='background-image'])" +
          "{background-color:" + secBg + " !important;}";
        overrideCss += "body{background-color:" + secBg + " !important;}";

        // contrast-bewuste tekst- en headingkleur per sectie
        const bgLum = SG.getLuminance(secBg);
        const isDarkBg = bgLum < 0.4; // drempel: achtergrond is donker

        const palDark = findByName("Dark");
        let headingColour, bodyColour;

        if (isDarkBg) {
          // donkere achtergrond → lichte tekst
          headingColour = "#ffffff";
          bodyColour = "rgba(255,255,255,0.85)";
        } else {
          // lichte achtergrond → donkere tekst
          headingColour = palDark || "#1a1a1a";
          bodyColour = palDark ? palDark : "#333333";
        }

        overrideCss +=
          "h1,h2,h3,h4,h5,h6,.brxe-heading{color:" +
          headingColour +
          " !important;}";
        overrideCss +=
          "body,p,.brxe-text,.brxe-text-basic,.brxe-rich-text{color:" +
          bodyColour +
          " !important;}";

        // knoppen op donkere achtergrond: witte tekst op primary knop
        if (isDarkBg) {
          overrideCss += ".brxe-button,.bricks-button{color:#fff !important;}";
          overrideCss +=
            "a:not(.brxe-button){color:" +
            (palLight || "#ffffff") +
            " !important;}";
        } else {
          // lichte sectie-achtergrond: knoptekst baseren op contrast t.o.v. primary knopkleur
          const primary = paletteColours.length ? paletteColours[0].hex : null;
          if (primary) {
            const btnTextColour =
              SG.getLuminance(primary) < 0.4 ? "#ffffff" : "#1a1a1a";
            overrideCss +=
              ".brxe-button,.bricks-button{color:" +
              btnTextColour +
              " !important;}";
          }
        }
      }

      style.textContent = overrideCss;

      // Logo injecteren in header/nav secties (sectionIdx 0 = eerste sectie per pagina)
      if (SG.guide.logoUrl && sIdx === 0) {
        const logoImgs = iframeDocument.querySelectorAll(
          ".brxe-nav-menu img, nav img, header img, [class*='logo'] img, .brxe-image img"
        );
        if (logoImgs.length) {
          logoImgs[0].src = SG.guide.logoUrl;
          logoImgs[0].srcset = "";
          logoImgs[0].style.maxHeight = "60px";
          logoImgs[0].style.width = "auto";
          logoImgs[0].style.objectFit = "contain";
        }
      }

      // Google Fonts als <link> injecteren (niet als @import in <style>,
      // want @import na andere regels wordt door browsers genegeerd)
      const fontsUrl = SG.buildGoogleFontsUrl();
      let existingLink = iframeDocument.getElementById("aisb-sg-gfonts");
      if (fontsUrl) {
        if (!existingLink) {
          existingLink = iframeDocument.createElement("link");
          existingLink.id = "aisb-sg-gfonts";
          existingLink.rel = "stylesheet";
          iframeDocument.head.appendChild(existingLink);
        }
        existingLink.href = fontsUrl;
      } else if (existingLink) {
        existingLink.remove();
      }
    } catch (error) {
      // cross-origin iframe, overslaan — we kunnen geen CSS injecteren
    }
  };

  // past de kleur- en font-overrides toe op ALLE geladen iframes tegelijk
  // wordt aangeroepen na elke wijziging (kleurpicker, logo-upload, font-selectie)
  // zodat alle wireframe-previews in alle drie de stappen direct de nieuwe stijl tonen
  // iframes die nog niet geladen zijn (_loaded = false) worden overgeslagen
  SG.applyOverridesToAllIframes = function () {
    SG.allIframes.forEach((iframe) => {
      if (iframe._loaded) SG.injectOverrideIntoIframe(iframe);
    });
  };

  // luistert naar postMessage-berichten vanuit iframes
  // iframes sturen hun eigen hoogte door via { type: "aisb_iframe_height", height: 1234 }
  // zodat we de iframe en zijn wrapper exact op de juiste hoogte kunnen zetten
  // dit voorkomt scrollbalken en zorgt dat de hele sectie zichtbaar is
  window.addEventListener("message", (event) => {
    // negeer berichten die niet van ons type zijn
    if (!event.data || event.data.type !== "aisb_iframe_height") return;
    // zoek de iframe die dit bericht heeft gestuurd
    SG.allIframes.forEach((iframe) => {
      try {
        if (iframe.contentWindow === event.source) {
          // stel de hoogte van de iframe in op de gemelde hoogte
          iframe.style.height = event.data.height + "px";
          // pas ook de wrapper-hoogte aan, rekening houdend met de schaalfactor
          const wrapper = iframe.closest(".aisb-sg-iframe-wrap");
          if (wrapper) {
            const scale = parseFloat(
              wrapper.style.getPropertyValue("--sg-scale") || "1",
            );
            wrapper.style.height = Math.ceil(event.data.height * scale) + "px";
          }
        }
      } catch (error) {
        // cross-origin iframe, negeren
      }
    });
  });

  /* ─── Wireframe iframe preview — canvas bouwer ─────────────── */

  // bouwt een pannable/zoomable canvas met wireframe-pagina's als iframe-kaarten
  // parameter:
  //   container — het DOM-element waarin de canvas wordt gebouwd
  //              (bijv. SG.el.coloursPreview, SG.el.typographyPreview, SG.el.imagesPreview)
  SG.buildCanvasForContainer = function (container) {
    // verwijder bestaande iframes uit SG.allIframes voordat we de container leegmaken
    // anders blijven er "dode" referenties in de array staan
    container.querySelectorAll("iframe").forEach((existingIframe) => {
      const index = SG.allIframes.indexOf(existingIframe);
      if (index > -1) SG.allIframes.splice(index, 1);
    });
    // maak de container helemaal leeg voor een schone opbouw
    container.innerHTML = "";

    // als er geen wireframe-pagina's zijn, toon een placeholder-bericht
    if (!SG.wireframePages.length) {
      container.innerHTML =
        '<div class="aisb-sg-empty-state">No wireframe found. Generate wireframes in Step 2 first.</div>';
      return;
    }

    // bouw de 3-laags canvas-structuur:
    const canvas = document.createElement("div");
    canvas.className = "aisb-sg-canvas";
    const canvasInner = document.createElement("div");
    canvasInner.className = "aisb-sg-canvas-inner";
    canvas.appendChild(canvasInner);

    const grid = document.createElement("div");
    grid.className = "aisb-sg-pages-grid";
    canvasInner.appendChild(grid);

    // per wireframe-pagina een kaart aanmaken
    SG.wireframePages.forEach((page) => {
      const card = document.createElement("div");
      card.className = "aisb-sg-page-card";

      // kaart-header: toont de paginatitel en het aantal secties als badge
      const head = document.createElement("div");
      head.className = "aisb-sg-page-card-head";
      head.innerHTML =
        '<span class="aisb-sg-page-card-title">' +
        SG.escapeHtml(page.title || page.slug) +
        "</span>" +
        '<span class="aisb-sg-page-card-badge">' +
        (page.sections ? page.sections.length : 0) +
        " sections</span>";
      card.appendChild(head);

      // kaart-body: bevat alle sectie-iframes
      const body = document.createElement("div");
      body.className = "aisb-sg-page-card-body";

      // per sectie een iframe aanmaken die het Bricks-template laadt
      (page.sections || []).forEach((section, sectionIndex) => {
        // het post-ID bepaalt welk template geladen wordt
        // prioriteit: AI wireframe ID → Bricks template ID
        const postId = section.ai_wireframe_id || section.bricks_template_id;
        if (!postId) return;

        // wrapper-div voor de iframe (regelt schaalfactor en hoogte)
        const wrapper = document.createElement("div");
        wrapper.className = "aisb-sg-iframe-wrap";

        // de iframe zelf — laadt de Bricks frontend-preview van deze sectie
        const iframe = document.createElement("iframe");
        iframe.src = (AISB_SG.previewUrl || "") + postId;
        iframe.className = "aisb-bricks-iframe aisb-sg-section-iframe";
        iframe.loading = "lazy";
        iframe.scrolling = "no";
        // custom properties op het iframe-element voor tracking
        iframe._loaded = false;
        iframe._pageSlug = page.slug;
        iframe._sectionIdx = sectionIndex;

        // wanneer de iframe klaar is met laden:
        //   2. injecteer CSS-overrides (kleuren + fonts)
        //   3. injecteer afbeeldingen
        iframe.addEventListener("load", () => {
          iframe._loaded = true;
          SG.injectOverrideIntoIframe(iframe);
          if (SG.injectImagesIntoIframe) SG.injectImagesIntoIframe(iframe);
          try {
            const scrollHeight =
              iframe.contentDocument.documentElement.scrollHeight || 400;
            iframe.style.height = scrollHeight + "px";
            wrapper.style.height = scrollHeight + "px";
          } catch (error) {
            // cross-origin: gebruik fallback-hoogte van 400px
            iframe.style.height = "400px";
            wrapper.style.height = "400px";
          }
        });

        wrapper.appendChild(iframe);
        body.appendChild(wrapper);
        // voeg toe aan de globale lijst zodat applyOverridesToAllIframes hem kent
        SG.allIframes.push(iframe);
      });

      card.appendChild(body);
      grid.appendChild(card);
    });

    container.appendChild(canvas);

    // pan/zoom state-object — houdt de huidige positie, schaal en pan-status bij
    // wordt opgeslagen op canvas._sgState zodat mousemove kan controleren
    // of het event bij deze specifieke canvas hoort (meerdere canvassen mogelijk)
    const state = {
      translateX: 40,
      translateY: 40,
      scale: 1,
      isPanning: false,
      panStart: null,
    };
    canvas._sgState = state;

    // begrenst een getal tussen een minimum en maximum waarde
    // wordt gebruikt om de schaalfactor te beperken (bijv. 0.05 tot 3)
    function clampNumber(value, minimum, maximum) {
      return Math.max(minimum, Math.min(maximum, value));
    }

    // past de CSS-transform toe op canvasInner
    // combineert translate (positie) en scale (zoom-niveau) in één transform-string
    function applyTransform() {
      canvasInner.style.transform =
        "translate(" +
        state.translateX +
        "px, " +
        state.translateY +
        "px) scale(" +
        state.scale +
        ")";
    }

    // berekent de optimale schaal en positie zodat alle kaarten in het zicht passen
    // stappen:
    //   1. meet de breedte en hoogte van het canvas-element
    //   2. bereken de bounding box van alle pagina-kaarten samen
    //   3. bereken de schaalfactor zodat alles past
    //   4. centreer horizontaal
    function fitToView() {
      const canvasRect = canvas.getBoundingClientRect();
      if (!canvasRect.width || !canvasRect.height) return;
      const cards = canvasInner.querySelectorAll(".aisb-sg-page-card");
      if (!cards.length) return;
      // bereken de bounding box van alle kaarten (min/max coördinaten)
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
      // bereken de schaalfactor: het kleinste van breedte-ratio en hoogte-ratio
      // max 1 (niet groter dan 100%), min 0.05 (niet kleiner dan 5%)
      const padding = 40;
      const scaleX = (canvasRect.width - padding * 2) / contentWidth;
      const scaleY = (canvasRect.height - padding * 2) / contentHeight;
      state.scale = clampNumber(Math.min(scaleX, scaleY), 0.05, 1);
      // centreer de inhoud horizontaal binnen het canvas
      state.translateX =
        (canvasRect.width - contentWidth * state.scale) / 2 -
        minX * state.scale;
      // verticale positie: 40px marge bovenaan
      state.translateY = padding;
      applyTransform();
    }

    // sla fitToView op als property van de container
    // zodat goToWizardStep() het kan aanroepen bij stap-wissel
    container._sgFitToView = fitToView;
    // voer fitToView uit op het volgende animatieframe (DOM moet eerst gerenderd zijn)
    requestAnimationFrame(fitToView);

    // ── Pan-functionaliteit (slepen met de muis) ──
    canvas.addEventListener("mousedown", (event) => {
      if (event.target.closest(".aisb-sg-page-card")) return;
      state.isPanning = true;
      state.panStart = {
        x: event.clientX,
        y: event.clientY,
        translateX: state.translateX,
        translateY: state.translateY,
      };
      canvas.classList.add("is-panning");
      event.preventDefault();
    });

    // verplaats de canvas-inhoud mee met de muisbeweging
    // controleert of we wel aan het pannen zijn op déze canvas (niet een andere)
    window.addEventListener("mousemove", (event) => {
      if (!state.isPanning || canvas._sgState !== state) return;
      state.translateX =
        state.panStart.translateX + (event.clientX - state.panStart.x);
      state.translateY =
        state.panStart.translateY + (event.clientY - state.panStart.y);
      applyTransform();
    });

    // stop met pannen wanneer de muisknop wordt losgelaten
    window.addEventListener("mouseup", () => {
      if (!state.isPanning) return;
      state.isPanning = false;
      canvas.classList.remove("is-panning");
    });

    // ── Zoom-functionaliteit (ctrl + scrollwiel) ──
    canvas.addEventListener(
      "wheel",
      (event) => {
        if (event.ctrlKey) {
          // ctrl + scroll = zoomen gericht op de muispositie
          event.preventDefault();
          const rect = canvas.getBoundingClientRect();
          const cursorX = event.clientX - rect.left;
          const cursorY = event.clientY - rect.top;
          const previousScale = state.scale;
          const nextScale = clampNumber(
            previousScale * (1 - event.deltaY * 0.001),
            0.05,
            3,
          );
          if (Math.abs(nextScale - previousScale) < 0.0001) return;
          state.translateX =
            cursorX -
            (cursorX - state.translateX) * (nextScale / previousScale);
          state.translateY =
            cursorY -
            (cursorY - state.translateY) * (nextScale / previousScale);
          state.scale = nextScale;
          applyTransform();
        } else {
          // gewoon scrollen = pannen, maar alleen als er nog ruimte is
          // als de inhoud al aan de rand staat, laat de scroll door naar de pagina
          const contentRect = canvasInner.getBoundingClientRect();
          const viewRect = canvas.getBoundingClientRect();

          const newTX = state.translateX - event.deltaX;
          const newTY = state.translateY - event.deltaY;

          // bereken de grenzen: hoever mag de canvas verschoven worden?
          const contentW = canvasInner.scrollWidth * state.scale;
          const contentH = canvasInner.scrollHeight * state.scale;
          const viewW = viewRect.width;
          const viewH = viewRect.height;

          // minimale translate = inhoud past net aan de rechter-/onderkant
          const minTX = Math.min(40, viewW - contentW);
          const minTY = Math.min(40, viewH - contentH);
          const maxTX = 40; // maximale linker-/bovenrand
          const maxTY = 40;

          // clamp de nieuwe positie binnen de grenzen
          const clampedTX = clampNumber(newTX, minTX, maxTX);
          const clampedTY = clampNumber(newTY, minTY, maxTY);

          // check of het scrollen daadwerkelijk de canvas verplaatst
          const movedX = Math.abs(clampedTX - state.translateX) > 0.5;
          const movedY = Math.abs(clampedTY - state.translateY) > 0.5;

          if (!movedX && !movedY) {
            // niets meer om te pannen → laat de scroll door naar de pagina
            return;
          }

          event.preventDefault();
          state.translateX = clampedTX;
          state.translateY = clampedTY;
          applyTransform();
        }
      },
      { passive: false },
    );

    // dubbelklik op lege canvas-ruimte: reset zoom en positie naar "alles in zicht"
    canvas.addEventListener("dblclick", (event) => {
      if (event.target.closest(".aisb-sg-page-card")) return;
      fitToView();
    });
  };

  // bouwt de preview-canvas voor alle drie de wizard-stappen tegelijk
  // roept buildCanvasForContainer aan voor:
  //   stap 1 (kleuren), stap 2 (typografie), stap 3 (afbeeldingen)
  // wordt aangeroepen nadat wireframe-secties zijn geladen van de server
  SG.renderStepPreview = function () {
    [
      SG.el.coloursPreview,
      SG.el.typographyPreview,
      SG.el.imagesPreview,
    ].forEach((container) => {
      if (!container) return;
      SG.buildCanvasForContainer(container);
    });
  };

  /* ─── Wireframe secties laden ──────────────────────────────── */

  // haalt de wireframe-secties op van de server via AJAX
  // na een succesvol antwoord:
  //   1. slaat de pagina-data op in SG.wireframePages
  //   2. bouwt de preview-canvassen opnieuw op via renderStepPreview
  //   3. start het automatisch toewijzen van afbeeldingen (als images.js geladen is)
  SG.loadWireframeSections = async function () {
    if (!SG.projectId) return;
    const response = await SG.post("aisb_get_wireframe_sections", {
      project_id: SG.projectId,
    });
    if (
      response &&
      response.success &&
      response.data.pages &&
      response.data.pages.length
    ) {
      SG.wireframePages = response.data.pages;
      SG.renderStepPreview();
      // als images.js geladen is, wijs afbeeldingen automatisch toe aan secties
      if (SG.autoAssignImages) SG.autoAssignImages();
    }
  };

  /* ─── Render helper: kleurstalen-rij ───────────────────────── */

  // rendert een rij kleurstalen (swatches) in een container-element
  // als de array leeg is, wordt een placeholder-bericht getoond
  // locked colour indices — survives re-renders
  SG.lockedColours = SG.lockedColours || new Set();

  SG.renderSwatchRow = function (container, colours) {
    if (!container) return;
    if (!Array.isArray(colours) || !colours.length) {
      container.innerHTML =
        '<div class="aisb-sg-empty-state">No colours defined yet.</div>';
      return;
    }
    const lockSvgOpen =
      '<svg class="aisb-sg-lock-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>' +
      '<path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
    const lockSvgClosed =
      '<svg class="aisb-sg-lock-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>' +
      '<path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>';
    const editSvg =
      '<svg class="aisb-sg-edit-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
      '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>' +
      '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
    container.innerHTML = colours
      .map(function (colour, i) {
        let isLocked = SG.lockedColours.has(i);
        return (
          '<div class="aisb-sg-swatch' +
          (isLocked ? " is-locked" : "") +
          '" data-swatch-idx="' +
          i +
          '">' +
          '<div class="aisb-sg-swatch-block" style="background:' +
          SG.escapeHtml(colour.hex || "#ccc") +
          ';">' +
          '<input type="color" class="aisb-sg-swatch-picker" data-picker-idx="' +
          i +
          '" value="' +
          SG.escapeHtml(colour.hex || "#cccccc") +
          '" tabindex="-1">' +
          '<span class="aisb-sg-swatch-edit" data-edit-idx="' +
          i +
          '">' +
          editSvg +
          "</span>" +
          '<button type="button" class="aisb-sg-lock-btn" data-lock-idx="' +
          i +
          '" title="' +
          (isLocked ? "Unlock" : "Lock") +
          ' this colour">' +
          (isLocked ? lockSvgOpen : lockSvgClosed) +
          "</button>" +
          "</div>" +
          '<div class="aisb-sg-swatch-label">' +
          SG.escapeHtml(colour.name || colour.hex || "") +
          "</div>" +
          '<div class="aisb-sg-swatch-hex">' +
          SG.escapeHtml(colour.hex || "") +
          "</div>" +
          "</div>"
        );
      })
      .join("");
  };
})();
