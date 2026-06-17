/**
 * design/figma-import.js - Importeert Figma-gegenereerde Brixies JSON in het designcanvas.
 *
 * Matchingstrategie op basis van positie:
 *   - Secties  : [tpl:XXX] label -> iframe._sectionPostId
 *   - Tekst    : DFS-volgorde Figma JSON -> DFS-volgorde DOM leaf-elementen
 *   - Afbeelding: per volgorde binnen sectie via settings.src / settings.url
 *   - Achtergrond: settings.background -> root/child DOM-elementen
 *   - Afmetingen: settings.width/settings.height -> root/child DOM-elementen
 *
 * Toepassing via D._registerEdit + D.applyStoredEdits: het bewezen codepad
 * van de handmatige editor, zodat Bricks-specifieke quirks (counter-animaties
 * enz.) al afgehandeld worden.
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  // Figma exporteert absolute framegeometrie, achtergronden en typografie.
  // Layoutmaten opnieuw toepassen bovenop Bricks breekt de responsive layout,
  // ook als er niets in Figma is gewijzigd. Daarom blijft de import compact:
  //   - tekstvervangingen behouden
  //   - afbeelding-src vervangingen behouden
  //   - sectie-achtergrond behouden
  //   - tekstkleur behouden wanneer contrast goed is
  //   - font-family, font-size, font-weight en line-height behouden
  // Layoutdimensies en descendant backgrounds blijven bewust uit.
  const IMPORT_LAYOUT_DIMENSIONS = false;
  const IMPORT_DESCENDANT_BACKGROUNDS = false;
  const IMPORT_BUTTON_STYLES = true;
  const IMPORT_TEXT_TYPOGRAPHY = true;
  const UNSAFE_LAYOUT_PROPS = {
    width: 1,
    height: 1,
    "min-width": 1,
    "min-height": 1,
    padding: 1,
    "padding-top": 1,
    "padding-right": 1,
    "padding-bottom": 1,
    "padding-left": 1,
    "font-family": 1,
    "font-size": 1,
    "font-weight": 1,
    "line-height": 1,
    "text-align": 1,
    "border-radius": 1,
  };

  /* ── Index ────────────────────────────────────────────────── */

  function buildIndex(content) {
    const idx = {};
    (content || []).forEach(function (el) {
      idx[String(el.id)] = el;
    });
    return idx;
  }

  /* ── Figma-zijde: tekst/heading leaves in DFS-volgorde ─────── */

  function collectFigmaTextLeaves(rootEl, idx) {
    const result = [];
    function walk(el) {
      if (!el) return;
      if (el.name === "text" || el.name === "heading" || el.name === "button") {
        result.push(el);
        return; // leaf, niet verder
      }
      (el.children || []).forEach(function (cid) {
        walk(idx[String(cid)]);
      });
    }
    walk(rootEl);
    return result;
  }

  function collectFigmaImageLeaves(rootEl, idx) {
    const result = [];
    function walk(el) {
      if (!el) return;
      if (el.name === "image") {
        result.push(el);
        return;
      }
      (el.children || []).forEach(function (cid) {
        walk(idx[String(cid)]);
      });
    }
    walk(rootEl);
    return result;
  }

  /* ── DOM-zijde: leaf tekst-elementen in document-volgorde ───── */

  const SKIP = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1, SVG: 1, PATH: 1, IFRAME: 1 };

  // Tags die als "inline opmaak" gelden — een element met alleen dit soort
  // kinderen is nog steeds één tekst-eenheid (bv. <h2><span>deel1</span><span>deel2</span></h2>)
  const INLINE = {
    SPAN: 1,
    EM: 1,
    STRONG: 1,
    B: 1,
    I: 1,
    U: 1,
    S: 1,
    MARK: 1,
    BR: 1,
  };

  /**
   * Verzamel tekst-leaves in DFS-volgorde.
   *
   * Speciale regels:
   *  - h1-h6 / p / a / button met ALLEEN inline kinderen (span, em…)
   *    → wordt als ÉÉN leaf beschouwd. Zo wordt een heading die in Bricks
   *    gesplitst is in twee gekleurde spans toch als één element gezien,
   *    net zoals in Figma. De tekst wordt op de parent gezet (vervangt de spans).
   *  - Echte leaf (geen zichtbare kinderen) → gewoon toevoegen.
   *  - Anders → recurse.
   */
  function collectDomTextLeaves(doc) {
    var result = [];

    function visKids(el) {
      return Array.from(el.children).filter(function (c) {
        return !SKIP[c.tagName];
      });
    }

    function isInlineOnly(el) {
      var kids = visKids(el);
      return (
        kids.length === 0 ||
        kids.every(function (c) {
          return INLINE[c.tagName];
        })
      );
    }

    function walk(el) {
      if (!el || el.nodeType !== 1) return;
      if (SKIP[el.tagName]) return;
      var text = (el.innerText || "").trim();
      if (!text) return;

      // Heading of paragraph met alleen inline opmaak → als één leaf behandelen
      var tag = el.tagName;
      var isTextUnit =
        tag === "H1" ||
        tag === "H2" ||
        tag === "H3" ||
        tag === "H4" ||
        tag === "H5" ||
        tag === "H6" ||
        tag === "P" ||
        tag === "A" ||
        tag === "BUTTON" ||
        tag === "LI" ||
        tag === "TD" ||
        tag === "TH" ||
        tag === "LABEL" ||
        tag === "FIGCAPTION" ||
        tag === "BLOCKQUOTE";

      if (isTextUnit && isInlineOnly(el)) {
        result.push(el);
        return; // NIET recursen — spans zijn deel van dezelfde tekst
      }

      var kids = visKids(el);
      if (kids.length === 0) {
        // Echte leaf (bv. <span> zonder kinderen, anders dan boven gedekt)
        result.push(el);
        return;
      }

      kids.forEach(walk);
    }

    walk(doc.body);
    return result;
  }

  function normalizeComparableText(value) {
    return String(value == null ? "" : value)
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<\/p>\s*<p[^>]*>/gi, "\n\n")
      .replace(/<\/?p[^>]*>/gi, "")
      .replace(/<[^>]*>/g, "")
      .replace(/&nbsp;/g, " ")
      .replace(/&amp;/g, "&")
      .replace(/&lt;/g, "<")
      .replace(/&gt;/g, ">")
      .replace(/&quot;/g, '"')
      .replace(/&#0?39;/g, "'")
      .replace(/\s+/g, " ")
      .trim();
  }

  function figmaTextKind(figEl) {
    var name = String((figEl && figEl.name) || "").toLowerCase();
    if (name === "button") return "button";
    if (name === "heading") return "heading";
    return "text";
  }

  function domTextKind(el) {
    if (!el) return "";
    var tag = String(el.tagName || "").toUpperCase();
    if (tag === "BUTTON") return "button";
    if (tag === "A" && el.classList && el.classList.contains("bricks-button")) {
      return "button";
    }
    if (/^H[1-6]$/.test(tag)) return "heading";
    return "text";
  }

  function findFallbackDomTextIndex(figEl, domLeaves, used, cursor) {
    var settings = (figEl && figEl.settings) || {};
    var wantedText = normalizeComparableText(settings.text);
    var wantedKind = figmaTextKind(figEl);

    function matches(index, requireText) {
      if (index < 0 || index >= domLeaves.length || used[index]) return false;
      var domEl = domLeaves[index];
      if (domTextKind(domEl) !== wantedKind) return false;
      if (!requireText || !wantedText) return true;
      return normalizeComparableText(domEl.innerText || "") === wantedText;
    }

    if (wantedText) {
      for (var i = cursor; i < domLeaves.length; i++) {
        if (matches(i, true)) return i;
      }
      for (var j = 0; j < cursor; j++) {
        if (matches(j, true)) return j;
      }
    }

    for (var k = cursor; k < domLeaves.length; k++) {
      if (matches(k, false)) return k;
    }
    for (var m = 0; m < cursor; m++) {
      if (matches(m, false)) return m;
    }

    for (var n = cursor; n < domLeaves.length; n++) {
      if (!used[n]) return n;
    }
    for (var p = 0; p < cursor; p++) {
      if (!used[p]) return p;
    }

    return -1;
  }

  /* ── Contrast-check ────────────────────────────────────────── */

  /**
   * Geeft true terug als textColor voldoende contrast heeft tegen bgColor
   * (contrast ratio ≥ minRatio, WCAG-definitie).
   * Gebruikt D.getLuminance() uit overrides.js.
   */
  function hasGoodContrast(textColor, bgColor, minRatio) {
    if (!textColor || !bgColor) return true; // onbekend → niet blokkeren
    if (String(textColor).toLowerCase().indexOf("gradient") !== -1) return true;
    if (String(bgColor).toLowerCase().indexOf("gradient") !== -1) return true;
    if (
      /^rgba?\(/i.test(String(textColor)) ||
      /^rgba?\(/i.test(String(bgColor))
    ) {
      return true;
    }
    if (typeof D.getLuminance !== "function") return true;
    minRatio = minRatio || 3.0;
    var L1 = D.getLuminance(textColor);
    var L2 = D.getLuminance(bgColor);
    if (isNaN(L1) || isNaN(L2)) return true;
    var lighter = Math.max(L1, L2);
    var darker = Math.min(L1, L2);
    return (lighter + 0.05) / (darker + 0.05) >= minRatio;
  }

  /**
   * Bepaal de effectieve achtergrondkleur van het iframe:
   * 1. secBg (uit Figma JSON settings.background) — meest accuraat
   * 2. body inline style
   * 3. fallback: wit
   */
  function effectiveBg(doc, secBg) {
    if (secBg) return secBg;
    var bodyBg = doc.body && doc.body.style && doc.body.style.backgroundColor;
    if (bodyBg && bodyBg !== "transparent" && bodyBg !== "") return bodyBg;
    return "#ffffff";
  }

  function effectiveTextBgForEl(doc, el, secBg) {
    if (!doc || !el) return effectiveBg(doc, secBg);
    var win = doc.defaultView;
    var cur = el;
    while (cur && cur.nodeType === 1) {
      if (win && typeof win.getComputedStyle === "function") {
        var bg = win.getComputedStyle(cur).backgroundColor;
        if (
          bg &&
          bg !== "transparent" &&
          bg !== "rgba(0, 0, 0, 0)" &&
          bg !== "rgba(0,0,0,0)"
        ) {
          return bg;
        }
      }
      cur = cur.parentElement;
    }
    return effectiveBg(doc, secBg);
  }

  function toCssBackground(value) {
    if (!value) return value;
    return String(value).replace(
      /linear-gradient\(\s*(-?\d+(?:\.\d+)?)deg\s*,/gi,
      function (_match, deg) {
        var cssDeg = (parseFloat(deg) + 180) % 360;
        if (cssDeg < 0) cssDeg += 360;
        cssDeg = Math.round(cssDeg * 1000) / 1000;
        return "linear-gradient(" + cssDeg + "deg,";
      },
    );
  }

  function findSectionAnchor(doc) {
    return (
      doc.body.querySelector(".brxe-section") ||
      doc.body.querySelector(".brxe-container") ||
      doc.body.querySelector("section") ||
      doc.body.firstElementChild
    );
  }

  function applyBackgroundValue(iframe, el, rawBg, options) {
    if (!el || !rawBg) return false;
    var bg = toCssBackground(rawBg);
    var isGradient = bg.toLowerCase().indexOf("gradient") !== -1;
    var bgProp = isGradient ? "background-image" : "background-color";
    var removeProp = isGradient ? "background-color" : "background-image";

    el.style.setProperty(bgProp, bg, "important");
    el.style.removeProperty(removeProp);
    if (options && options.markSection) el.dataset.aisbSecBg = "1";
    else el.dataset.aisbBg = "1";

    var edit = { prop: bgProp, value: bg };
    if (options && options.cascade) edit.cascade = options.cascade;
    D._registerEdit(iframe, "css", el, edit);
    return true;
  }

  function normalizeCssLength(value) {
    if (value === undefined || value === null) return null;
    if (typeof value === "object") {
      if (value.value !== undefined) {
        var unit = value.unit || "px";
        value = value.value + unit;
      } else {
        return null;
      }
    }
    if (typeof value === "number") {
      if (!isFinite(value)) return null;
      return value + "px";
    }

    var str = String(value).trim();
    if (!str) return null;
    var lower = str.toLowerCase();
    if (lower === "hug" || lower === "hug contents" || lower === "auto")
      return null;
    if (lower === "fill" || lower === "fill container") return "100%";
    if (/^-?\d+(?:\.\d+)?$/.test(str)) return str + "px";
    if (
      /^-?\d+(?:\.\d+)?(?:px|%|rem|em|vh|vw|vmin|vmax|ch|ex|cm|mm|in|pt|pc)$/i.test(
        str,
      )
    )
      return str;
    if (/^(?:calc|min|max|clamp|var)\(/i.test(str)) return str;
    return null;
  }

  function getSettingValue(settings, names) {
    for (var i = 0; i < names.length; i++) {
      var key = names[i];
      if (
        settings[key] !== undefined &&
        settings[key] !== null &&
        settings[key] !== ""
      ) {
        return settings[key];
      }
    }
    return null;
  }

  function getDimensionValue(settings, prop) {
    var aliases =
      prop === "width" ? ["width", "_width", "w"] : ["height", "_height", "h"];
    var raw = getSettingValue(settings, aliases);
    if (raw === null && settings.size && settings.size[prop] !== undefined)
      raw = settings.size[prop];
    if (
      raw === null &&
      settings.dimensions &&
      settings.dimensions[prop] !== undefined
    )
      raw = settings.dimensions[prop];
    if (raw === null && settings.rect && settings.rect[prop] !== undefined)
      raw = settings.rect[prop];
    return normalizeCssLength(raw);
  }

  // Figma API geeft absolute pixel waarden direct op het element (el.width / el.height),
  // niet in settings. Dit leest ze op als fallback als settings niets oplevert.
  function getDimensionValueFromEl(el, settings, prop) {
    var fromSettings = getDimensionValue(settings, prop);
    if (fromSettings !== null) return fromSettings;
    var topLevel = el[prop];
    if (topLevel !== undefined && topLevel !== null)
      return normalizeCssLength(topLevel);
    return null;
  }

  function applyDimensionValue(iframe, el, prop, value) {
    if (!IMPORT_LAYOUT_DIMENSIONS || !el || !value) return false;
    return applyCssDimensionValue(iframe, el, prop, value);
  }

  function applyCssDimensionValue(iframe, el, prop, value) {
    if (!el || !value) return false;
    el.style.setProperty(prop, value, "important");
    el.dataset.aisbSize = "1";
    D._registerEdit(iframe, "css", el, { prop: prop, value: value });
    return true;
  }

  function applyPaddingSettings(iframe, el, settings, force) {
    if (!settings) return 0;
    if (!force && !IMPORT_LAYOUT_DIMENSIONS) return 0;
    var changed = 0;
    var pad = settings.padding || settings._padding || null;
    if (!pad) return changed;

    if (typeof pad === "string" || typeof pad === "number") {
      var padVal = normalizeCssLength(pad);
      if (padVal && applyCssDimensionValue(iframe, el, "padding", padVal))
        changed++;
      return changed;
    }

    if (typeof pad === "object") {
      var sides = {
        top: "padding-top",
        right: "padding-right",
        bottom: "padding-bottom",
        left: "padding-left",
      };
      Object.keys(sides).forEach(function (side) {
        var v = normalizeCssLength(pad[side] !== undefined ? pad[side] : null);
        if (v && applyCssDimensionValue(iframe, el, sides[side], v)) changed++;
      });
    }

    return changed;
  }

  function normalizeBorderRadiusValue(value) {
    if (value === undefined || value === null) return "";
    if (typeof value === "number") return value + "px";
    if (typeof value === "string") {
      var str = value.trim();
      if (!str) return "";
      return /^-?\d+(?:\.\d+)?$/.test(str) ? str + "px" : str;
    }
    if (typeof value !== "object") return "";

    var scalarKeys = ["raw", "value", "css"];
    for (var si = 0; si < scalarKeys.length; si++) {
      if (value[scalarKeys[si]] !== undefined) {
        var scalar = normalizeBorderRadiusValue(value[scalarKeys[si]]);
        if (scalar) return scalar;
      }
    }

    var keys = ["top", "right", "bottom", "left"];
    var values = keys.map(function (key) {
      return normalizeBorderRadiusValue(value[key]);
    });
    if (!values.join("")) {
      keys = ["topLeft", "topRight", "bottomRight", "bottomLeft"];
      values = keys.map(function (key) {
        return normalizeBorderRadiusValue(value[key]);
      });
    }
    if (!values.join("")) return "";

    var fallback = values.find(function (part) {
      return !!part;
    });
    values = values.map(function (part) {
      return part || fallback;
    });

    if (
      values[0] === values[1] &&
      values[0] === values[2] &&
      values[0] === values[3]
    ) {
      return values[0];
    }
    if (values[0] === values[2] && values[1] === values[3]) {
      return values[0] + " " + values[1];
    }
    if (values[1] === values[3]) {
      return values[0] + " " + values[1] + " " + values[2];
    }
    return values.join(" ");
  }

  function getBorderRadiusValue(settings) {
    if (!settings) return "";
    var directKeys = ["_borderRadius", "borderRadius", "border-radius"];
    for (var i = 0; i < directKeys.length; i++) {
      if (settings[directKeys[i]] !== undefined) {
        var direct = normalizeBorderRadiusValue(settings[directKeys[i]]);
        if (direct) return direct;
      }
    }
    if (settings._border && settings._border.radius !== undefined) {
      return normalizeBorderRadiusValue(settings._border.radius);
    }
    return "";
  }

  function applyBorderRadiusSettings(iframe, el, settings, force) {
    if (!settings) return 0;
    if (!force && !IMPORT_LAYOUT_DIMENSIONS) return 0;
    var radius = getBorderRadiusValue(settings);
    if (!radius) return 0;
    el.style.setProperty("border-radius", radius, "important");
    D._registerEdit(iframe, "css", el, {
      prop: "border-radius",
      value: radius,
    });
    return 1;
  }

  function applyElementDimensions(iframe, el, settings) {
    if (!settings) return 0;
    var changed = 0;
    var width = getDimensionValue(settings, "width");
    var height = getDimensionValue(settings, "height");
    if (width && applyDimensionValue(iframe, el, "width", width)) changed++;
    if (height && applyDimensionValue(iframe, el, "height", height)) changed++;

    // min-width / min-height
    var minW = normalizeCssLength(
      settings["min-width"] || settings._minWidth || settings.minWidth || null,
    );
    var minH = normalizeCssLength(
      settings["min-height"] ||
        settings._minHeight ||
        settings.minHeight ||
        null,
    );
    if (minW && applyDimensionValue(iframe, el, "min-width", minW)) changed++;
    if (minH && applyDimensionValue(iframe, el, "min-height", minH)) changed++;

    changed += applyPaddingSettings(iframe, el, settings);
    changed += applyBorderRadiusSettings(iframe, el, settings, true);

    return changed;
  }

  function isVectorLikeNode(el) {
    if (!el) return false;
    var label = String(el.label || "").toLowerCase();
    var children = el.children || [];
    return (
      children.length === 0 &&
      (label === "vector" || label.indexOf("vector") !== -1)
    );
  }

  function isFigmaRenderableNode(el) {
    if (!el || isVectorLikeNode(el)) return false;
    return (
      el.name === "container" ||
      el.name === "block" ||
      el.name === "image" ||
      el.name === "text" ||
      el.name === "heading"
    );
  }

  function isDomRenderableNode(el) {
    if (!el || el.nodeType !== 1 || SKIP[el.tagName]) return false;
    return el.matches(
      ".brxe-section,.brxe-container,.brxe-block,.brxe-div,.brxe-button,.brxe-image,.brxe-text,.brxe-heading," +
        "section,header,footer,img,h1,h2,h3,h4,h5,h6,p,a,button,li,label,blockquote",
    );
  }

  function collectFigmaRenderableChildren(el, idx) {
    var result = [];
    (el.children || []).forEach(function (cid) {
      var child = idx[String(cid)];
      if (isFigmaRenderableNode(child)) result.push(child);
    });
    return result;
  }

  function collectDomRenderableChildren(el) {
    return Array.from(el.children || []).filter(isDomRenderableNode);
  }

  function applyLayoutStyles(sectionEl, idx, iframe, doc) {
    var anchor = findSectionAnchor(doc);
    if (!anchor) return { backgrounds: 0, sizes: 0 };
    var changed = { backgrounds: 0, sizes: 0 };

    function walk(figEl, domEl, isRoot) {
      if (!figEl || !domEl) return;
      var settings = figEl.settings || {};
      if (figEl.name === "container" || figEl.name === "block") {
        changed.sizes += applyElementDimensions(iframe, domEl, settings);
      }
      // Descendant backgrounds from Figma are never safe to replay on the
      // live Bricks tree: the Figma-side hierarchy almost never matches the
      // DOM hierarchy 1-on-1, so a block-level background lands on the wrong
      // wrapper (e.g. the heading gets a coloured pill). Section background
      // is handled separately via applySectionBg.
      if (
        IMPORT_DESCENDANT_BACKGROUNDS &&
        !isRoot &&
        settings.background &&
        figEl.name !== "image" &&
        !isVectorLikeNode(figEl)
      ) {
        if (applyBackgroundValue(iframe, domEl, settings.background))
          changed.backgrounds++;
      }

      var figKids = collectFigmaRenderableChildren(figEl, idx);
      var domKids = collectDomRenderableChildren(domEl);
      figKids.forEach(function (figChild, i) {
        walk(figChild, domKids[i], false);
      });
    }

    walk(sectionEl, anchor, true);
    return changed;
  }

  /* ── Sectie-achtergrond ─────────────────────────────────────── */

  function applySectionBg(iframe, doc, secBg) {
    var anchor = findSectionAnchor(doc);
    if (!anchor || !secBg) return false;

    var bg = toCssBackground(secBg);
    var isGradient = bg.toLowerCase().indexOf("gradient") !== -1;
    var bgProp = isGradient ? "background-image" : "background-color";
    var removeProp = isGradient ? "background-color" : "background-image";

    applyBackgroundValue(iframe, anchor, secBg, {
      cascade: "section",
      markSection: true,
    });

    doc.body.style.setProperty(bgProp, bg, "important");
    doc.body.style.removeProperty(removeProp);
    doc.body.dataset.aisbSecBg = "1";
    return true;
  }

  /* ── Knop-achtergronden ─────────────────────────────────────── */

  function applyButtonBgs(sectionEl, idx, iframe, doc) {
    var changed = { backgrounds: 0, sizes: 0 };
    if (!IMPORT_BUTTON_STYLES) return changed;

    function findButtonInnerByBricksId(bricksId) {
      var wrap = findDomElementByBricksId(doc, bricksId);
      if (!wrap) return null;
      if (
        wrap.matches &&
        wrap.matches(".bricks-button,button,a[class*='button'],a")
      ) {
        return wrap;
      }
      return wrap.querySelector(".bricks-button,button,a[class*='button'],a");
    }

    function walk(el) {
      if (!el) return;
      var s = el.settings || {};

      if (el.name === "button" && s.bricksId) {
        var innerBtn = findButtonInnerByBricksId(s.bricksId);
        var border = s._border || {};
        var borderColor =
          border.color && (border.color.raw || border.color.hex)
            ? border.color.raw || border.color.hex
            : null;
        var borderWidth =
          border.width &&
          (border.width.top ||
            border.width.right ||
            border.width.bottom ||
            border.width.left)
            ? border.width.top ||
              border.width.right ||
              border.width.bottom ||
              border.width.left
            : null;
        var borderStyle = border.style || (borderColor ? "solid" : null);

        if (innerBtn) {
          if (
            s.background &&
            applyBackgroundValue(iframe, innerBtn, s.background)
          ) {
            changed.backgrounds++;
          }
          if (borderColor) {
            innerBtn.style.setProperty(
              "border-color",
              borderColor,
              "important",
            );
            D._registerEdit(iframe, "css", innerBtn, {
              prop: "border-color",
              value: borderColor,
            });
          }
          if (borderWidth) {
            innerBtn.style.setProperty(
              "border-width",
              borderWidth,
              "important",
            );
            D._registerEdit(iframe, "css", innerBtn, {
              prop: "border-width",
              value: borderWidth,
            });
          }
          if (borderStyle) {
            innerBtn.style.setProperty(
              "border-style",
              borderStyle,
              "important",
            );
            D._registerEdit(iframe, "css", innerBtn, {
              prop: "border-style",
              value: borderStyle,
            });
          }
          changed.sizes += applyBorderRadiusSettings(iframe, innerBtn, s, true);
          changed.sizes += applyPaddingSettings(iframe, innerBtn, s, true);
        }
      }

      (el.children || []).forEach(function (cid) {
        walk(idx[String(cid)]);
      });
    }
    walk(sectionEl);
    return changed;
  }

  function getImportedSectionUuid(sectionEl) {
    if (!sectionEl) return "";
    var settings = sectionEl.settings || {};
    var uuid = settings.aiSectionUuid || settings.aisbSectionUuid || "";
    if (uuid) return String(uuid).trim();

    var label = String(sectionEl.label || "");
    var match = label.match(/\[uuid:([^\]]+)\]/i);
    return match ? String(match[1] || "").trim() : "";
  }

  /**
   * Resolve a Bricks DOM wrapper by its stable bricksId (e.g. "brxe-abc123").
   * Bricks renders every element as a div with `id="brxe-..."`. For text
   * leaves we then descend into the actual paragraph / heading inside.
   */
  function findDomElementByBricksId(doc, bricksId) {
    if (!doc || !bricksId) return null;
    var id = String(bricksId).trim();
    if (!id) return null;
    var wrap = null;
    try {
      wrap = doc.getElementById(id);
    } catch (e) {
      wrap = null;
    }
    if (!wrap) {
      try {
        wrap = doc.querySelector("." + CSS.escape(id));
      } catch (e) {}
    }
    return wrap;
  }

  function findDomTextLeafByBricksId(doc, bricksId) {
    var wrap = findDomElementByBricksId(doc, bricksId);
    if (!wrap) return null;
    // For text/heading/button wrappers, the actual text element is either
    // the wrapper itself or its first text-bearing descendant.
    var tag = wrap.tagName;
    if (
      tag === "H1" ||
      tag === "H2" ||
      tag === "H3" ||
      tag === "H4" ||
      tag === "H5" ||
      tag === "H6" ||
      tag === "P" ||
      tag === "A" ||
      tag === "BUTTON" ||
      tag === "LI" ||
      tag === "LABEL"
    ) {
      return wrap;
    }
    var inner = wrap.querySelector(
      "h1,h2,h3,h4,h5,h6,p,a,button,li,label,figcaption,blockquote",
    );
    return inner || wrap;
  }

  function findDomImageByBricksId(doc, bricksId) {
    var wrap = findDomElementByBricksId(doc, bricksId);
    if (!wrap) return null;
    if (wrap.tagName === "IMG") return wrap;
    return wrap.querySelector("img");
  }

  function isUnsafeLayoutPatch(op) {
    return !!(
      op &&
      op.type === "css" &&
      op.prop &&
      UNSAFE_LAYOUT_PROPS[String(op.prop)]
    );
  }

  function clearUnsafeLayoutPatches(iframe, doc) {
    if (!iframe || !doc || !doc.body) return;

    var postId = String(iframe._sectionPostId || "");
    var existing = [];

    if (postId && Array.isArray(D._savedPatches[postId])) {
      existing = existing.concat(D._savedPatches[postId]);
      D._savedPatches[postId] = D._savedPatches[postId].filter(function (op) {
        return !isUnsafeLayoutPatch(op);
      });
    }

    if (Array.isArray(iframe._aisbPatch)) {
      existing = existing.concat(iframe._aisbPatch);
      iframe._aisbPatch = iframe._aisbPatch.filter(function (op) {
        return !isUnsafeLayoutPatch(op);
      });
    }

    existing.forEach(function (op) {
      if (!isUnsafeLayoutPatch(op) || !op.selector) return;
      var el = doc.body.querySelector(op.selector);
      if (!el) return;
      el.style.removeProperty(op.prop);
      el.removeAttribute("data-aisb-size");
    });
  }

  /* ── Hoofdfunctie ───────────────────────────────────────────── */

  function extractImportContent(figmaData) {
    if (Array.isArray(figmaData)) return figmaData;
    if (!figmaData || typeof figmaData !== "object") return [];

    if (Array.isArray(figmaData.content)) return figmaData.content;
    if (Array.isArray(figmaData.elements)) return figmaData.elements;
    if (figmaData.template && Array.isArray(figmaData.template.content)) {
      return figmaData.template.content;
    }
    if (figmaData.data && Array.isArray(figmaData.data.content)) {
      return figmaData.data.content;
    }
    if (figmaData.result && Array.isArray(figmaData.result.content)) {
      return figmaData.result.content;
    }

    return [];
  }

  function applyFigmaImport(figmaData) {
    var content = extractImportContent(figmaData);
    if (!content.length) {
      if (figmaData && typeof figmaData === "object" && figmaData.pages) {
        showToast(
          "Dit lijkt AI Builder export JSON, niet de Figma Bricks export.",
          true,
        );
        return;
      }
      if (
        figmaData &&
        typeof figmaData === "object" &&
        (figmaData.source === "figma-export" || figmaData.version)
      ) {
        showToast(
          "De Figma export bevat geen elementen. Exporteer de juiste pagina of selectie opnieuw.",
          true,
        );
        return;
      }
      showToast("Geen elementen gevonden in de JSON.", true);
      return;
    }

    var idx = buildIndex(content);

    var sectionEls = content.filter(function (el) {
      return (
        (el.name === "container" || el.name === "section") &&
        el.label &&
        /\[tpl:\d+\]/.test(el.label)
      );
    });
    if (!sectionEls.length) {
      showToast("Geen [tpl:XXX] labels gevonden in de JSON.", true);
      return;
    }

    // ── Bouw een queue per bricks_template_id (in canvas-volgorde) ──
    // De Figma plugin gebruikt de bricks_template_id voor [tpl:XXX],
    // terwijl _sectionPostId de ai_wireframe_id is. Dezelfde template
    // kan op meerdere pagina's staan → queue zodat de 1e [tpl:312] de
    // 1e header-iframe krijgt, de 2e [tpl:312] de 2e, etc.
    var iframeQueues = {};
    var iframeByUuid = {};
    (D.allIframes || []).forEach(function (f) {
      var uuid = String((f._sectionData && f._sectionData.uuid) || "");
      if (uuid && !iframeByUuid[uuid]) iframeByUuid[uuid] = f;

      var btId = String(
        (f._sectionData && f._sectionData.bricks_template_id) ||
          f._sectionPostId ||
          "",
      );
      if (!btId) return;
      if (!iframeQueues[btId]) iframeQueues[btId] = [];
      iframeQueues[btId].push(f);
    });
    var iframeQueuePos = {};

    var totalSections = sectionEls.length;
    var appliedSections = 0;
    var notMatched = 0;
    var textChanged = 0;
    var textMismatched = 0;
    var imgChanged = 0;
    var bgChanged = 0;
    var sizeChanged = 0;

    sectionEls.forEach(function (sectionEl) {
      var tplMatch = sectionEl.label.match(/\[tpl:(\d+)\]/);
      if (!tplMatch) return;
      var tplId = tplMatch[1];
      var sectionUuid = getImportedSectionUuid(sectionEl);

      // Match imported sections to the original AI section UUID when present.
      // Fallback to the old template-id queue for legacy exports.
      var iframe = sectionUuid ? iframeByUuid[sectionUuid] : null;
      if (!iframe) {
        var queue = iframeQueues[tplId] || [];
        var qPos = iframeQueuePos[tplId] || 0;
        iframe = queue[qPos];
        iframeQueuePos[tplId] = qPos + 1;
      }

      if (!iframe) {
        notMatched++;
        return;
      }
      if (!iframe._loaded) {
        notMatched++;
        return;
      }

      var doc = iframe.contentDocument;
      if (!doc || !doc.body) {
        notMatched++;
        return;
      }

      clearUnsafeLayoutPatches(iframe, doc);

      appliedSections++;

      // ── 1. Sectie-achtergrond ──────────────────────────────
      var secBg = sectionEl.settings && sectionEl.settings.background;
      if (secBg && applySectionBg(iframe, doc, secBg)) bgChanged++;
      var layoutChanges = applyLayoutStyles(sectionEl, idx, iframe, doc);
      bgChanged += layoutChanges.backgrounds;
      sizeChanged += layoutChanges.sizes;

      // ── 2. Tekst & heading (positieel) ─────────────────────
      var figmaTexts = collectFigmaTextLeaves(sectionEl, idx);
      var hasStableTextIds = figmaTexts.some(function (figEl) {
        return !!String(
          (figEl.settings && figEl.settings.bricksId) || "",
        ).trim();
      });
      if (hasStableTextIds) {
        figmaTexts = figmaTexts.filter(function (figEl) {
          return !!String(
            (figEl.settings && figEl.settings.bricksId) || "",
          ).trim();
        });
      }
      var domLeaves = collectDomTextLeaves(doc);
      var usedDomLeaves = {};
      var domCursor = 0;


      figmaTexts.forEach(function (figEl, i) {
        var sLookup = figEl.settings || {};
        var bricksId = sLookup.bricksId || "";
        var domEl = null;
        var domLeafIndex = -1;
        if (bricksId) {
          domEl = findDomTextLeafByBricksId(doc, bricksId);
          if (domEl) domLeafIndex = domLeaves.indexOf(domEl);
        }
        if (!domEl) {
          domLeafIndex = findFallbackDomTextIndex(
            figEl,
            domLeaves,
            usedDomLeaves,
            domCursor,
          );
          if (domLeafIndex >= 0) domEl = domLeaves[domLeafIndex];
        }
        if (!domEl) {
          textMismatched++;
          return;
        }

        if (domLeafIndex >= 0) {
          usedDomLeaves[domLeafIndex] = true;
          domCursor = domLeafIndex + 1;
        }

        var s = figEl.settings || {};
        var newText =
          s.text !== undefined && s.text !== null ? String(s.text) : null;


        if (newText !== null) {
          if (typeof D._applyTextPatch === "function") {
            D._applyTextPatch(domEl, newText, doc);
          } else {
            var hasInlineKids = Array.from(domEl.children).some(function (c) {
              return INLINE[c.tagName];
            });
            if (hasInlineKids) {
              // Vervang alle inline-children door een enkelvoudige tekst
              domEl.innerText = newText;
            }
          }
          D._registerEdit(iframe, "text", domEl, { text: newText });
          textChanged++;
        }

        // Stijlen — kleur alleen toepassen als die voldoende contrast heeft
        // tegen de daadwerkelijke element-achtergrond (knoppen hebben vaak
        // een andere bg dan de sectie zelf).
        var bg = effectiveTextBgForEl(doc, domEl, secBg);
        if (s.color && hasGoodContrast(s.color, bg)) {
          domEl.style.setProperty("color", s.color, "important");
          D._registerEdit(iframe, "css", domEl, {
            prop: "color",
            value: s.color,
          });
        }
        if (IMPORT_TEXT_TYPOGRAPHY && s.fontSize)
          D._registerEdit(iframe, "css", domEl, {
            prop: "font-size",
            value: s.fontSize,
          });
        if (IMPORT_TEXT_TYPOGRAPHY && s.fontFamily)
          D._registerEdit(iframe, "css", domEl, {
            prop: "font-family",
            value: s.fontFamily + ",sans-serif",
          });
        if (IMPORT_TEXT_TYPOGRAPHY && s.fontWeight)
          D._registerEdit(iframe, "css", domEl, {
            prop: "font-weight",
            value: String(s.fontWeight),
          });
        if (IMPORT_TEXT_TYPOGRAPHY && s.lineHeight)
          D._registerEdit(iframe, "css", domEl, {
            prop: "line-height",
            value: String(s.lineHeight),
          });
        if (IMPORT_TEXT_TYPOGRAPHY && s.textAlign)
          D._registerEdit(iframe, "css", domEl, {
            prop: "text-align",
            value: s.textAlign,
          });
        if (IMPORT_LAYOUT_DIMENSIONS) {
          sizeChanged += applyElementDimensions(iframe, domEl, s);
        }
      });

      // ── 3. Afbeeldingen (positieel) ────────────────────────
      var figmaImgs = collectFigmaImageLeaves(sectionEl, idx);
      var domImgs = Array.from(doc.body.querySelectorAll("img")).filter(
        function (img) {
          return !img.getAttribute("data-aisb-logo");
        },
      );

      figmaImgs.forEach(function (figImgEl, i) {
        var s = figImgEl.settings || {};
        var rawSrc =
          s.src || s.url || s.imageUrl || s.imageSrc || s.image || "";
        // Bricks slaat afbeeldingen op als object { url: "...", id: 123 } — extract de URL.
        var src =
          rawSrc && typeof rawSrc === "object"
            ? rawSrc.url || rawSrc.src || rawSrc.full || ""
            : rawSrc;
        if (!src) return;
        var domImg = null;
        if (s.bricksId) domImg = findDomImageByBricksId(doc, s.bricksId);
        if (!domImg) domImg = domImgs[i];
        if (!domImg) return;

        // Cache buster toevoegen bij http(s) URL's om te voorkomen dat
        // de browser een oude gepachte versie uit de cache laadt.
        if (src.indexOf("http") === 0) {
          var sep = src.indexOf("?") !== -1 ? "&" : "?";
          src += sep + "t=" + new Date().getTime();
        }

        D._registerEdit(iframe, "img", domImg, { src: src });
        sizeChanged += applyElementDimensions(iframe, domImg, s);
        imgChanged++;
      });

      // ── 4. Knop-achtergronden ──────────────────────────────
      var buttonChanges = applyButtonBgs(sectionEl, idx, iframe, doc);
      bgChanged += buttonChanges.backgrounds;
      sizeChanged += buttonChanges.sizes;

      // Pas alle geregistreerde patches NU toe via het bewezen codepath
      D.applyStoredEdits(iframe);
    });

    // Opslaan-knop dirty markeren
    var saveBtn = document.getElementById("aisb-design-save-btn");
    if (
      saveBtn &&
      (textChanged > 0 || imgChanged > 0 || bgChanged > 0 || sizeChanged > 0)
    ) {
      saveBtn.classList.add("is-dirty");
    }

    var msg =
      "Import: " + appliedSections + "/" + totalSections + " secties verwerkt.";
    if (textChanged > 0)
      msg += " " + textChanged + " tekstelementen bijgewerkt.";
    if (imgChanged > 0) msg += " " + imgChanged + " afbeeldingen bijgewerkt.";
    if (bgChanged > 0) msg += " " + bgChanged + " achtergronden bijgewerkt.";
    if (sizeChanged > 0) msg += " " + sizeChanged + " afmetingen bijgewerkt.";
    if (textMismatched > 0)
      msg += " (" + textMismatched + " posities niet gevonden — zie console.)";
    if (notMatched > 0)
      msg += " " + notMatched + " secties niet gevonden in canvas.";

    showToast(msg);
  }

  /* ── Toast ───────────────────────────────────────────────────── */

  function showToast(msg, isError) {
    var existing = document.getElementById("aisb-import-toast");
    if (existing) existing.remove();
    var t = document.createElement("div");
    t.id = "aisb-import-toast";
    t.className = "aisb-import-toast" + (isError ? " is-error" : "");
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () {
      t.classList.add("is-visible");
      setTimeout(function () {
        t.classList.remove("is-visible");
        setTimeout(function () {
          t.remove();
        }, 400);
      }, 6000);
    });
  }

  /* ── Paste-modal ─────────────────────────────────────────────── */

  function openImportModal() {
    var existing = document.getElementById("aisb-import-modal");
    if (existing) {
      existing.querySelector("textarea").value = "";
      resetHint(existing);
      existing.classList.add("is-open");
      setTimeout(function () {
        existing.querySelector("textarea").focus();
      }, 50);
      return;
    }

    var modal = document.createElement("div");
    modal.id = "aisb-import-modal";
    modal.className = "aisb-import-modal";
    modal.innerHTML =
      '<div class="aisb-import-dialog">' +
      '<div class="aisb-import-dialog-header">' +
      '<span class="aisb-import-dialog-title">Import Figma JSON</span>' +
      '<button class="aisb-import-dialog-close" type="button">&#10005;</button>' +
      "</div>" +
      '<p class="aisb-import-dialog-hint">Plak de Brixies JSON die de Figma-plugin heeft gegenereerd.</p>' +
      '<textarea class="aisb-import-textarea" placeholder=\'{ "content": [ ... ] }\'></textarea>' +
      '<div class="aisb-import-dialog-footer">' +
      '<button class="aisb-import-btn-apply" type="button">&#10003; Toepassen</button>' +
      '<button class="aisb-import-btn-cancel" type="button">Annuleren</button>' +
      "</div>" +
      "</div>";

    document.body.appendChild(modal);

    modal
      .querySelector(".aisb-import-dialog-close")
      .addEventListener("click", closeModal);
    modal
      .querySelector(".aisb-import-btn-cancel")
      .addEventListener("click", closeModal);
    modal.addEventListener("click", function (e) {
      if (e.target === modal) closeModal();
    });

    var escFn = function (e) {
      if (e.key === "Escape") {
        closeModal();
        document.removeEventListener("keydown", escFn);
      }
    };
    document.addEventListener("keydown", escFn);

    modal
      .querySelector(".aisb-import-btn-apply")
      .addEventListener("click", function () {
        var raw = modal.querySelector("textarea").value.trim();
        if (!raw) return;
        try {
          var data = JSON.parse(raw);
          closeModal();
          applyFigmaImport(data);
        } catch (err) {
          console.error("[AISB import] JSON parse fout:", err);
          var hint = modal.querySelector(".aisb-import-dialog-hint");
          hint.textContent =
            "Ongeldige JSON — controleer de inhoud en probeer opnieuw.";
          hint.style.color = "#ef4444";
        }
      });

    modal.classList.add("is-open");
    setTimeout(function () {
      modal.querySelector("textarea").focus();
    }, 50);
  }

  function resetHint(modal) {
    var h = modal.querySelector(".aisb-import-dialog-hint");
    if (h) {
      h.textContent =
        "Plak de Brixies JSON die de Figma-plugin heeft gegenereerd.";
      h.style.color = "";
    }
  }

  function closeModal() {
    var m = document.getElementById("aisb-import-modal");
    if (m) m.classList.remove("is-open");
  }

  var importBtn = document.getElementById("aisb-design-figma-import-btn");
  if (importBtn) importBtn.addEventListener("click", openImportModal);
})();
