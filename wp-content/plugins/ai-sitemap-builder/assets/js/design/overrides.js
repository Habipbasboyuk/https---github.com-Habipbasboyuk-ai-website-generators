/**
 * design/overrides.js — CSS-override injectie in de preview-iframes.
 *
 * Verantwoordelijk voor:
 *   - buildOverrideCss()     — bouwt een volledige CSS-string met kleur- en fontoverrides
 *   - buildGoogleFontsUrl()  — maakt de Google Fonts URL voor heading- en bodyfont
 *   - getLuminance()         — WCAG-luminantieberekening (0 = zwart, 1 = wit)
 *   - injectOverride()       — injecteert de CSS én Google Fonts in één iframe
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  /* ── Kleur- en font-override CSS bouwen ─────────────────────── */

  D.buildOverrideCss = function () {
    const guide = D.guide;
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
  };

  /* ── Google Fonts URL ────────────────────────────────────────── */

  D.buildGoogleFontsUrl = function () {
    const guide = D.guide;
    const families = [guide.headingFont, guide.bodyFont]
      .filter(Boolean)
      .map((f) => f.replace(/ /g, "+"));
    if (!families.length) return "";
    return (
      "https://fonts.googleapis.com/css2?" +
      families.map((f) => "family=" + f + ":wght@400;600;700").join("&") +
      "&display=swap"
    );
  };

  /* ── Luminantieberekening (WCAG) ─────────────────────────────── */

  D.getLuminance = function (hex) {
    if (!hex || hex.length < 7) return 1;
    let r = parseInt(hex.slice(1, 3), 16) / 255;
    let g = parseInt(hex.slice(3, 5), 16) / 255;
    let b = parseInt(hex.slice(5, 7), 16) / 255;
    r = r <= 0.03928 ? r / 12.92 : Math.pow((r + 0.055) / 1.055, 2.4);
    g = g <= 0.03928 ? g / 12.92 : Math.pow((g + 0.055) / 1.055, 2.4);
    b = b <= 0.03928 ? b / 12.92 : Math.pow((b + 0.055) / 1.055, 2.4);
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
  };

  /* ── Override injecteren in één iframe ──────────────────────── */

  D.injectOverride = function (iframe) {
    try {
      const guide = D.guide;
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (!doc) return;

      let style = doc.getElementById("aisb-design-override");
      if (!style) {
        style = doc.createElement("style");
        style.id = "aisb-design-override";
        doc.head.appendChild(style);
      }

      let css = D.buildOverrideCss();

      const sIdx =
        typeof iframe._sectionIdx === "number" ? iframe._sectionIdx : -1;
      if (sIdx >= 0) {
        const colours =
          guide.colours && guide.colours.length ? guide.colours : [];
        const find = (name) => (colours.find((c) => c.name === name) || {}).hex;

        // Gebruik dezelfde logica als core.js / injectOverrideIntoIframe:
        // even secties → palLight | sectionBg1-fallback
        // oneven secties → palNeutral | palLight | sectionBg2-fallback
        const palDark = find("Dark") || (colours[3] ? colours[3].hex : "");
        const palNeutral =
          find("Neutral") || (colours[5] ? colours[5].hex : "");
        const palLight = find("Light") || (colours[4] ? colours[4].hex : "");

        let secBg;
        if (sIdx % 2 === 0) {
          secBg = palLight || guide.sectionBg1 || "#ffffff";
        } else {
          secBg = palNeutral || palLight || guide.sectionBg2 || "#f0f4ff";
        }

        // :not([style*='background-image']) beschermt secties met een achtergrondafbeelding
        css +=
          ".brxe-section:not([style*='background-image'])," +
          ".brxe-container:not([style*='background-image'])," +
          ".brxe-block:not([style*='background-image'])" +
          "{background-color:" +
          secBg +
          " !important;}";
        css += "body{background-color:" + secBg + " !important;}";

        const isDarkBg = D.getLuminance(secBg) < 0.4;
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
              D.getLuminance(primary) < 0.4 ? "#ffffff" : "#1a1a1a";
            css +=
              ".brxe-button,.bricks-button{color:" +
              btnTextColour +
              " !important;}";
          }
        }
      }

      style.textContent = css;

      // Logo injecteren in eerste sectie per pagina (sIdx 0 = header/nav)
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

      // Google Fonts als <link> injecteren
      const fontsUrl = D.buildGoogleFontsUrl();
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
  };
})();
