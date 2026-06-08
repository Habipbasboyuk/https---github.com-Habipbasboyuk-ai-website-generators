/**
 * design/figma-export.js - "Export to Figma" knop in de designtoolbar.
 *
 * Verzamelt alle pagina's, secties, patches (inclusief ongeslagen) en de
 * stijlgids in een JSON-bestand en downloadt die als bestand.
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  /**
   * Bouw een gecombineerde patch-map: postId → patch-array.
   * Ongeslagen iframe-patches winnen boven opgeslagen server-patches.
   */
  function buildLivePatchMap() {
    const map = {};
    (D.allIframes || []).forEach(function (iframe) {
      const postId = String(iframe._sectionPostId || "");
      if (!postId) return;

      const saved = D._savedPatches[postId] || [];
      const unsaved = iframe._aisbPatch || [];
      if (!saved.length && !unsaved.length) return;

      // Merge: key = type|selector[|prop]
      const byKey = {};
      saved.forEach(function (op) {
        byKey[patchKey(op)] = op;
      });
      unsaved.forEach(function (op) {
        byKey[patchKey(op)] = op;
      });
      map[postId] = Object.values(byKey).map(function (op) {
        return normalizePatchAgainstIframe(op, iframe);
      });
    });

    return map;
  }

  function normalizePatchAgainstIframe(op, iframe) {
    if (!op || op.type !== "text" || !op.selector || !iframe) return op;

    let el = null;
    try {
      const doc = iframe.contentDocument;
      el = doc && doc.body ? doc.body.querySelector(op.selector) : null;
    } catch (err) {
      el = null;
    }
    if (!el) return op;

    const liveText = normalizePatchText(readElementText(el));
    if (!liveText) return op;

    const patchText = normalizePatchText(op.text);
    if (patchText === liveText) return op;

    return Object.assign({}, op, { text: liveText });
  }

  function readElementText(el) {
    if (!el) return "";
    if (typeof el.value === "string") return el.value;
    return el.innerText || el.textContent || "";
  }

  function normalizePatchText(value) {
    return String(value || "")
      .replace(/\r\n?/g, "\n")
      .replace(/[ \t]+\n/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .replace(/[ \t]{2,}/g, " ")
      .trim();
  }

  function patchKey(op) {
    return (
      op.type +
      "|" +
      (op.selector || "") +
      (op.type === "css" ? "|" + (op.prop || "") : "")
    );
  }

  /**
   * Voeg live patches toe aan de exportdata zodat ook ongeslagen wijzigingen
   * meegenomen worden in de JSON.
   */
  function applyLivePatches(exportData, livePatchMap) {
    if (!exportData || !Array.isArray(exportData.pages)) return;

    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        const postId = String(
          section.ai_wireframe_id || section.bricks_template_id || "",
        );
        if (postId && livePatchMap[postId] !== undefined) {
          section.patch = livePatchMap[postId];
        }
      });
    });
  }

  /**
   * Vervang de server-side style_guide door de live guide uit D.guide zodat
   * ongeslagen stijlgids-wijzigingen ook in de export zitten.
   *
   * Synchroniseert ook sectionBg1/sectionBg2 met de daadwerkelijk berekende
   * achtergrondkleuren zoals die in step 4 worden gebruikt (zelfde logica als
   * overrides.js): even secties → palLight, oneven secties → palNeutral|palLight.
   */
  function applyLiveStyleGuide(exportData) {
    if (!exportData || !D.guide) return;

    // Bewaar de door PHP gegenereerde data-URI voor logoUrl (als die er is),
    // zodat Object.assign die niet overschrijft met de raw lokale URL uit D.guide.
    var existingDataUri = "";
    if (
      exportData.style_guide &&
      exportData.style_guide.logoUrl &&
      exportData.style_guide.logoUrl.indexOf("data:") === 0
    ) {
      existingDataUri = exportData.style_guide.logoUrl;
    }

    exportData.style_guide = Object.assign({}, exportData.style_guide, D.guide);

    // Zet de data-URI terug als D.guide.logoUrl geen data-URI heeft.
    if (
      existingDataUri &&
      (!exportData.style_guide.logoUrl ||
        exportData.style_guide.logoUrl.indexOf("data:") !== 0)
    ) {
      exportData.style_guide.logoUrl = existingDataUri;
    }

    // Bereken de werkelijke sectie-achtergrondkleuren vanuit het palet
    // (identieke logica als D.injectStyleGuide in overrides.js).
    var guide = exportData.style_guide;
    var colours = guide.colours && guide.colours.length ? guide.colours : [];
    var find = function (name) {
      return (
        colours.find(function (c) {
          return c.name === name;
        }) || {}
      ).hex;
    };
    var palLight = find("Light") || (colours[4] ? colours[4].hex : "");
    var palNeutral = find("Neutral") || (colours[5] ? colours[5].hex : "");

    // even secties: Light-kleur | sectionBg1-fallback
    var computedBg1 = palLight || guide.sectionBg1 || "";
    // oneven secties: Neutral-kleur | Light-kleur | sectionBg2-fallback
    var computedBg2 = palNeutral || palLight || guide.sectionBg2 || "";

    guide.sectionBg1 = computedBg1;
    guide.sectionBg2 = computedBg2;
  }

  /**
   * Annoteer elke sectie in exportData met de live bg_index én bg_color vanuit
   * de iframes. Dit is nodig omdat step 4 een GLOBALE sectieteller gebruikt
   * (over alle pagina's) voor de afwisselende achtergrond, terwijl de Figma-
   * plugin geen toegang heeft tot die globale volgorde. Door bg_index + bg_color
   * per sectie mee te sturen weet de Figma-plugin exact welke kleur elke sectie
   * moet krijgen, ongeacht paginapositie of drag/drop herordeningen.
   */
  function annotateSectionBgColors(exportData) {
    if (!exportData || !Array.isArray(exportData.pages)) return;

    var guide = exportData.style_guide || {};
    var colours = guide.colours && guide.colours.length ? guide.colours : [];
    var find = function (name) {
      return (
        colours.find(function (c) {
          return c.name === name;
        }) || {}
      ).hex;
    };
    var palLight = find("Light") || (colours[4] ? colours[4].hex : "") || "";
    var palNeutral =
      find("Neutral") || (colours[5] ? colours[5].hex : "") || palLight;

    var bgFor = function (bgIdx) {
      return bgIdx % 2 === 0
        ? palLight || guide.sectionBg1 || ""
        : palNeutral || palLight || guide.sectionBg2 || "";
    };

    // Map uuid → _bgIndex vanuit de live iframes.
    var bgByUuid = {};
    (D.allIframes || []).forEach(function (iframe) {
      var uuid =
        (iframe._sectionData && iframe._sectionData.uuid) ||
        (iframe.closest &&
          iframe.closest("[data-uuid]") &&
          iframe.closest("[data-uuid]").dataset.uuid) ||
        "";
      if (!uuid) {
        // Fallback: zoek uuid via de wrap
        var wrap = iframe.parentElement;
        if (wrap && wrap.dataset && wrap.dataset.uuid) uuid = wrap.dataset.uuid;
      }
      if (uuid && typeof iframe._bgIndex === "number") {
        bgByUuid[uuid] = iframe._bgIndex;
      }
    });

    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        var uuid = section.uuid || "";
        var bgIdx =
          uuid && typeof bgByUuid[uuid] === "number"
            ? bgByUuid[uuid]
            : typeof section.bg_index === "number"
              ? section.bg_index
              : null;
        if (bgIdx !== null) {
          section.bg_index = bgIdx;
          section.bg_color = bgFor(bgIdx);
        }

        // Als de gebruiker de achtergrond handmatig heeft overschreven via een
        // CSS-patch met cascade:"section", gebruik dan die waarde als bg_color.
        var patches = section.patch;
        if (Array.isArray(patches)) {
          for (var pi = 0; pi < patches.length; pi++) {
            var op = patches[pi];
            if (
              op &&
              op.type === "css" &&
              op.cascade === "section" &&
              (op.prop === "background-color" || op.prop === "background") &&
              op.value
            ) {
              section.bg_color = op.value;
              break;
            }
          }
        }
      });
    });
  }

  const SKIP_TEXT_EXPORT = {
    SCRIPT: 1,
    STYLE: 1,
    NOSCRIPT: 1,
    SVG: 1,
    PATH: 1,
    IFRAME: 1,
  };

  const INLINE_TEXT_EXPORT = {
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

  function collectDomTextLeaves(doc) {
    var result = [];

    function visibleChildren(el) {
      return Array.from(el.children).filter(function (child) {
        return !SKIP_TEXT_EXPORT[child.tagName];
      });
    }

    function isInlineOnly(el) {
      var kids = visibleChildren(el);
      return (
        kids.length === 0 ||
        kids.every(function (child) {
          return INLINE_TEXT_EXPORT[child.tagName];
        })
      );
    }

    function walk(el) {
      if (!el || el.nodeType !== 1) return;
      if (SKIP_TEXT_EXPORT[el.tagName]) return;

      var text = (el.innerText || "").trim();
      if (!text) return;

      var tag = el.tagName;

      // Bricks CTA buttons and BUTTON elements use settings.text in Brixies —
      // they are never assigned from text_styles. Including them shifts all
      // subsequent positions by 1, so we skip them here. Button backgrounds are
      // handled separately by resolveLiveButtonBackgrounds.
      if (tag === "BUTTON") {
        visibleChildren(el).forEach(walk);
        return;
      }
      if (tag === "A" && el.classList.contains("bricks-button")) return;

      var isTextUnit =
        tag === "H1" ||
        tag === "H2" ||
        tag === "H3" ||
        tag === "H4" ||
        tag === "H5" ||
        tag === "H6" ||
        tag === "P" ||
        tag === "A" ||
        tag === "LI" ||
        tag === "TD" ||
        tag === "TH" ||
        tag === "LABEL" ||
        tag === "FIGCAPTION" ||
        tag === "BLOCKQUOTE";

      if (isTextUnit && isInlineOnly(el)) {
        result.push(el);
        return;
      }

      visibleChildren(el).forEach(walk);
    }

    if (doc && doc.body) walk(doc.body);
    return result;
  }

  function cssColorToHex(value) {
    if (!value) return "";

    var hex = String(value).trim();
    if (hex.indexOf("#") === 0) return hex;

    var match = hex.match(
      /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)(?:\s*,\s*([\d.]+))?\s*\)$/i,
    );
    if (!match) return hex;

    var rgb = [match[1], match[2], match[3]].map(function (part) {
      return Math.max(0, Math.min(255, parseInt(part, 10) || 0))
        .toString(16)
        .padStart(2, "0");
    });
    var out = "#" + rgb.join("");

    if (match[4] !== undefined && Number(match[4]) < 1) {
      out += Math.round(Math.max(0, Math.min(1, Number(match[4]))) * 255)
        .toString(16)
        .padStart(2, "0");
    }

    return out;
  }

  function domTextBricksId(el) {
    if (!el || !el.closest) return "";
    var wrap = el.closest("[id^='brxe-']");
    if (!wrap || !wrap.id) return "";
    return String(wrap.id).replace(/^brxe-/, "");
  }

  function buildSectionLookup(exportData) {
    var lookup = { byUuid: {}, byPostId: {} };
    if (!exportData || !Array.isArray(exportData.pages)) return lookup;

    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        if (!section) return;
        if (section.uuid) lookup.byUuid[String(section.uuid)] = section;
        var postId = section.ai_wireframe_id || section.bricks_template_id;
        if (postId) lookup.byPostId[String(postId)] = section;
      });
    });

    return lookup;
  }

  function getIframeSectionUuid(iframe) {
    var uuid =
      (iframe._sectionData && iframe._sectionData.uuid) ||
      (iframe.closest &&
        iframe.closest("[data-uuid]") &&
        iframe.closest("[data-uuid]").dataset.uuid) ||
      "";

    if (!uuid) {
      var wrap = iframe.parentElement;
      if (wrap && wrap.dataset && wrap.dataset.uuid) uuid = wrap.dataset.uuid;
    }

    return uuid;
  }

  function annotateLiveTextContent(exportData) {
    if (!exportData || !Array.isArray(exportData.pages)) return;

    var lookup = buildSectionLookup(exportData);

    (D.allIframes || []).forEach(function (iframe) {
      var uuid = getIframeSectionUuid(iframe);
      var postId = String(iframe._sectionPostId || "");
      var section =
        (uuid && lookup.byUuid[String(uuid)]) ||
        (postId && lookup.byPostId[postId]) ||
        null;
      if (!section) return;

      var doc = iframe.contentDocument;
      var win = iframe.contentWindow || (doc && doc.defaultView);
      if (!doc || !doc.body || !win) return;
      var radiusPatchBySelector = buildBorderRadiusPatchMap(section.patch);
      var existingContent = section.content || {};
      var existingTexts = Array.isArray(existingContent.texts)
        ? existingContent.texts.slice()
        : [];
      var existingTextStyles = Array.isArray(existingContent.text_styles)
        ? existingContent.text_styles.slice()
        : [];

      var textStyles = collectDomTextLeaves(doc).map(function (el) {
        var computed = win.getComputedStyle(el);
        var selector = D._buildElementSelector
          ? D._buildElementSelector(el, doc.body)
          : "";
        var bricksId = domTextBricksId(el);
        var entry = {
          id: bricksId,
          text: (el.innerText || "").trim(),
          color: cssColorToHex(computed.color),
          fontSize: computed.fontSize || "",
          fontFamily: computed.fontFamily || "",
          fontWeight: computed.fontWeight || "",
          lineHeight: computed.lineHeight || "",
          textAlign: computed.textAlign || "",
        };
        var radius = getBorderRadiusStyle(
          computed,
          radiusPatchBySelector[selector],
        );
        if (radius) Object.assign(entry, radius);
        return entry;
      });
      if (!textStyles.length) return;

      if (existingTexts.length > textStyles.length) {
        for (var ti = 0; ti < existingTexts.length; ti++) {
          if (textStyles[ti]) continue;
          textStyles[ti] = Object.assign(
            { text: existingTexts[ti] || "" },
            existingTextStyles[ti] || {},
          );
        }
      }

      var elementStyles = collectDomElementBorderStyles(
        doc,
        win,
        radiusPatchBySelector,
      );

      section.content = section.content || {};
      section.content.texts = textStyles.map(function (style) {
        return style.text;
      });
      section.content.text_styles = textStyles;
      section.content.text_colors = textStyles.map(function (style) {
        return style.color || "";
      });
      if (elementStyles.length) {
        section.content.element_styles = elementStyles;
        section.content.border_radii = elementStyles.map(function (style) {
          return {
            selector: style.selector || "",
            tag: style.tag || "",
            prop: "borderRadius",
            value: style.borderRadius || "",
          };
        });
      }
    });
  }

  function expandAccordionsForFigma(exportData) {
    if (!exportData || !Array.isArray(exportData.pages)) return;

    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        if (!section || !Array.isArray(section.bricks_elements)) return;
        expandFlatAccordionElements(section.bricks_elements);
      });
    });
  }

  function expandFlatAccordionElements(elements) {
    var byId = {};
    var childrenByParent = {};

    elements.forEach(function (el, index) {
      if (!el) return;
      var id = String(el.id || "");
      if (id) byId[id] = { el: el, index: index };
      var parent = String(el.parent || "");
      if (parent && parent !== "0") {
        if (!childrenByParent[parent]) childrenByParent[parent] = [];
        childrenByParent[parent].push(id);
      }
    });

    elements.forEach(function (el) {
      if (!el) return;

      if (el.name === "accordion-nested") {
        // Rename to "block" so Brixies/Figma plugin treats it as a plain
        // container instead of applying its built-in collapsed accordion rendering.
        el.name = "block";
        appendElementClass(el, "aisb-figma-accordion");

        var accordionId = String(el.id || "");
        var itemIds = [];
        if (Array.isArray(el.children)) {
          el.children.forEach(function (id) {
            if (id !== undefined && id !== null) itemIds.push(String(id));
          });
        }
        (childrenByParent[accordionId] || []).forEach(function (id) {
          itemIds.push(String(id));
        });
        itemIds = Array.from(new Set(itemIds.filter(Boolean)));

        itemIds.forEach(function (itemId) {
          var item = byId[itemId] && byId[itemId].el;
          if (!item) return;
          item.settings = item.settings || {};
          item.settings._display = "flex";
          item.settings.display = "flex";
          item.settings._direction = "column";
          item.settings.flexDirection = "column";

          getDescendantIds(itemId, childrenByParent).forEach(
            function (descendantId) {
              var descendant = byId[descendantId] && byId[descendantId].el;
              if (descendant) expandAccordionDescendantForFigma(descendant);
            },
          );
        });
      }

      if (
        el.name === "accordion" &&
        el.settings &&
        Array.isArray(el.settings.items)
      ) {
        // Rename so Brixies doesn't apply its collapsed rendering
        el.name = "block";
        appendElementClass(el, "aisb-figma-accordion");
        el.settings.items.forEach(function (item) {
          if (!item) return;
          item.open = true;
          item.expanded = true;
        });
      }
    });
  }

  /**
   * Hard fallback for Figma: FAQ sections are rebuilt as plain blocks.
   * Opening accordions is not enough because the Figma plugin can still apply
   * its own collapsed rendering. Static blocks make every answer editable.
   */
  function flattenFaqSectionsForFigma(exportData) {
    if (!exportData || !Array.isArray(exportData.pages)) return;

    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        if (!section || String(section.type || "") !== "faq") return;
        if (!Array.isArray(section.bricks_elements)) return;

        var originalElements = section.bricks_elements;
        var lookup = buildFlatElementLookup(originalElements);
        var pairs = extractFaqPairs(originalElements, lookup);
        if (!pairs.length) return;

        var intro = extractFaqIntroItems(originalElements, pairs, lookup);
        var styleCursor = buildTextStyleCursor(section);
        var rebuilt = buildStaticFaqElements(
          section,
          originalElements,
          intro,
          pairs,
          styleCursor,
        );

        section.bricks_elements = rebuilt.elements;
        section.content = section.content || {};
        section.content.texts = rebuilt.textStyles.map(function (style) {
          return style.text || "";
        });
        section.content.text_styles = rebuilt.textStyles;
        section.content.text_colors = rebuilt.textStyles.map(function (style) {
          return style.color || "";
        });
        section.content.element_styles = [];
        section.content.border_radii = [];
      });
    });
  }

  function buildFlatElementLookup(elements) {
    var byId = {};
    var childrenByParent = {};

    elements.forEach(function (el) {
      if (!el) return;
      var id = String(el.id || "");
      if (id) byId[id] = el;
      var parent = String(el.parent || "");
      if (parent && parent !== "0") {
        if (!childrenByParent[parent]) childrenByParent[parent] = [];
        childrenByParent[parent].push(id);
      }
    });

    return { byId: byId, childrenByParent: childrenByParent };
  }

  function extractFaqPairs(elements, lookup) {
    var pairs = [];
    var seenWrappers = {};

    elements.forEach(function (wrapper) {
      if (!isFaqAnswerWrapperElement(wrapper)) return;
      var wrapperId = String(wrapper.id || "");
      if (wrapperId && seenWrappers[wrapperId]) return;
      if (wrapperId) seenWrappers[wrapperId] = true;

      var parentId = String(wrapper.parent || "");
      var wrapperDescendants = getDescendantIds(
        wrapperId,
        lookup.childrenByParent,
      );
      var answerIds = [wrapperId].concat(wrapperDescendants);
      var answerTexts = answerIds
        .map(function (id) {
          return lookup.byId[id];
        })
        .filter(isTextLikeElement)
        .map(function (el) {
          return cleanFigmaText(el.settings && el.settings.text);
        })
        .filter(Boolean);

      var question = "";
      var questionEl = null;
      if (parentId) {
        var wrapperIdSet = {};
        answerIds.forEach(function (id) {
          wrapperIdSet[id] = true;
        });
        var parentDescendants = getDescendantIds(
          parentId,
          lookup.childrenByParent,
        );
        questionEl = parentDescendants
          .map(function (id) {
            return lookup.byId[id];
          })
          .filter(function (el) {
            return el && !wrapperIdSet[String(el.id || "")];
          })
          .find(isFaqQuestionElement);
        if (questionEl)
          question = cleanFigmaText(
            questionEl.settings && questionEl.settings.text,
          );
      }

      var answer = uniqueStrings(answerTexts).join("\n");
      if (!question || !answer) return;

      pairs.push({
        question: question,
        answer: answer,
        questionEl: questionEl,
        answerEl:
          answerIds
            .map(function (id) {
              return lookup.byId[id];
            })
            .find(isTextLikeElement) || wrapper,
        sourceIds: [parentId, wrapperId].concat(wrapperDescendants),
      });
    });

    return pairs;
  }

  function extractFaqIntroItems(elements, pairs, lookup) {
    var usedIds = {};
    pairs.forEach(function (pair) {
      (pair.sourceIds || []).forEach(function (id) {
        if (id) usedIds[id] = true;
      });
      if (pair.questionEl && pair.questionEl.id)
        usedIds[String(pair.questionEl.id)] = true;
      if (pair.answerEl && pair.answerEl.id)
        usedIds[String(pair.answerEl.id)] = true;
    });

    var intro = [];
    elements.forEach(function (el) {
      if (!isTextLikeElement(el)) return;
      var id = String(el.id || "");
      if (usedIds[id]) return;
      if (isDescendantOfUsed(el, usedIds, lookup.byId)) return;

      var text = cleanFigmaText(el.settings && el.settings.text);
      if (!text) return;
      if (
        intro.some(function (item) {
          return item.text === text && item.name === el.name;
        })
      )
        return;

      intro.push({ text: text, el: el });
    });

    return intro.slice(0, 4);
  }

  function isDescendantOfUsed(el, usedIds, byId) {
    var parent = String((el && el.parent) || "");
    while (parent && parent !== "0") {
      if (usedIds[parent]) return true;
      parent = String((byId[parent] && byId[parent].parent) || "");
    }
    return false;
  }

  function buildStaticFaqElements(
    section,
    originalElements,
    intro,
    pairs,
    styleCursor,
  ) {
    var root = cloneSimpleElement(
      originalElements.find(function (el) {
        return (
          el &&
          el.name === "section" &&
          (!el.parent || String(el.parent) === "0")
        );
      }) ||
        originalElements[0] ||
        {},
    );
    root.id = root.id || "aisb_faq_section";
    root.name = "section";
    root.parent = 0;
    root.children = ["aisb_faq_container_" + root.id];
    root.settings = root.settings || {};
    delete root.settings._cssGlobalClasses;

    var elements = [root];
    var textStyles = [];
    var containerId = root.children[0];
    var leftId = "aisb_faq_left_" + root.id;
    var rightId = "aisb_faq_list_" + root.id;

    elements.push({
      id: containerId,
      name: "container",
      parent: root.id,
      children: [leftId, rightId],
      settings: {
        _display: "grid",
        _gridTemplateColumns: "repeat(2, minmax(0, 1fr))",
        _gridGap: "var(--space-m)",
        "_gridTemplateColumns:tablet_portrait": "repeat(1, minmax(0, 1fr))",
        _padding: { top: "3rem", bottom: "3rem", left: "", right: "" },
      },
    });

    var leftChildren = intro.map(function (_item, index) {
      return "aisb_faq_intro_" + root.id + "_" + index;
    });

    elements.push({
      id: leftId,
      name: "block",
      parent: containerId,
      children: leftChildren,
      settings: {
        _display: "flex",
        _direction: "column",
        _rowGap: "var(--space-s)",
        _padding: { top: "3rem", bottom: "3rem", left: "", right: "" },
      },
      label: "FAQ Intro",
    });

    intro.forEach(function (item, index) {
      var id = leftChildren[index];
      elements.push(
        makeStaticTextElement(
          id,
          leftId,
          item.text,
          item.el,
          index === 0 ? "h2" : "p",
        ),
      );
      var introStyle = nextTextStyle(
        styleCursor,
        item.text,
        item.el,
        index === 0 ? "heading" : "body",
      );
      introStyle.id = id;
      textStyles.push(introStyle);
    });

    var rightChildren = pairs.map(function (_pair, index) {
      return "aisb_faq_card_" + root.id + "_" + index;
    });

    elements.push({
      id: rightId,
      name: "block",
      parent: containerId,
      children: rightChildren,
      settings: {
        _display: "flex",
        _direction: "column",
        _rowGap: "var(--space-m)",
        _padding: { top: "3rem", bottom: "3rem", left: "", right: "" },
      },
      label: "FAQ List",
    });

    pairs.forEach(function (pair, index) {
      var cardId = rightChildren[index];
      var questionId = "aisb_faq_q_" + root.id + "_" + index;
      var answerId = "aisb_faq_a_" + root.id + "_" + index;
      elements.push({
        id: cardId,
        name: "block",
        parent: rightId,
        children: [questionId, answerId],
        settings: {
          _display: "flex",
          _direction: "column",
          _rowGap: "var(--space-xs)",
          _padding: {
            top: "var(--space-s)",
            bottom: "var(--space-s)",
            left: "0",
            right: "0",
          },
          _border: {
            width: { bottom: "1px" },
            style: "solid",
            color: { raw: "#08264533" },
          },
        },
        label: "FAQ Item",
      });
      elements.push(
        makeStaticTextElement(
          questionId,
          cardId,
          pair.question,
          pair.questionEl,
          "h3",
        ),
      );
      elements.push(
        makeStaticTextElement(
          answerId,
          cardId,
          pair.answer,
          pair.answerEl,
          "p",
        ),
      );
      var qStyle = nextTextStyle(
        styleCursor,
        pair.question,
        pair.questionEl,
        "question",
      );
      qStyle.id = questionId;
      textStyles.push(qStyle);
      var aStyle = nextTextStyle(
        styleCursor,
        pair.answer,
        pair.answerEl,
        "answer",
      );
      aStyle.id = answerId;
      textStyles.push(aStyle);
    });

    return { elements: elements, textStyles: textStyles };
  }

  function cloneSimpleElement(el) {
    return JSON.parse(JSON.stringify(el || {}));
  }

  function makeStaticTextElement(id, parent, text, sourceEl, tag) {
    var settings = cloneSimpleElement((sourceEl && sourceEl.settings) || {});
    delete settings._cssGlobalClasses;
    delete settings._attributes;
    delete settings._hidden;
    settings.text = text;
    settings.tag = tag || settings.tag || "p";
    settings._display = "block";
    settings.display = "block";
    settings._visibility = "visible";
    settings.visibility = "visible";
    settings._opacity = "1";
    settings.opacity = "1";
    settings._overflow = "visible";
    settings.overflow = "visible";
    settings._cssCustom =
      String(settings._cssCustom || "") +
      "\n&{display:block!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}";

    return {
      id: id,
      name: tag && /^h[1-6]$/.test(tag) ? "heading" : "text-basic",
      parent: parent,
      children: [],
      settings: settings,
    };
  }

  function buildTextStyleCursor(section) {
    return {
      styles: ((section.content && section.content.text_styles) || []).map(
        function (style) {
          return Object.assign({}, style, {
            text: cleanFigmaText(style && style.text),
          });
        },
      ),
      used: [],
    };
  }

  function nextTextStyle(cursor, text, sourceEl, role) {
    var cleaned = cleanFigmaText(text);
    var foundIndex = -1;
    for (var i = 0; i < cursor.styles.length; i++) {
      if (cursor.used[i]) continue;
      if (cursor.styles[i].text === cleaned) {
        foundIndex = i;
        break;
      }
    }
    if (foundIndex !== -1) {
      cursor.used[foundIndex] = true;
      return Object.assign({}, cursor.styles[foundIndex], { text: cleaned });
    }

    var fallback = fallbackTextStyle(cleaned, sourceEl, role);
    return fallback;
  }

  function fallbackTextStyle(text, sourceEl, role) {
    var typography =
      (sourceEl && sourceEl.settings && sourceEl.settings._typography) || {};
    var isHeading = role === "heading" || role === "question";
    var guide = D.guide || {};
    var tag = String(
      (sourceEl && sourceEl.settings && sourceEl.settings.tag) || "",
    ).toLowerCase();
    var headingSizes = {
      h1: "64px",
      h2: "48px",
      h3: "36px",
      h4: "28px",
      h5: "22px",
      h6: "18px",
    };
    var defaultFontSize = isHeading ? headingSizes[tag] || "48px" : "18px";
    return {
      text: text,
      color: readColorRaw(typography.color) || "#082645",
      fontSize: typography["font-size"] || defaultFontSize,
      fontFamily:
        typography["font-family"] ||
        typography.fontFamily ||
        (isHeading ? guide.headingFont : guide.bodyFont) ||
        "",
      fontWeight: typography["font-weight"] || (isHeading ? "700" : "400"),
      lineHeight: typography["line-height"] || (isHeading ? "1.12" : "1.6"),
      textAlign: "start",
    };
  }

  function readColorRaw(value) {
    if (!value) return "";
    if (typeof value === "string") return value;
    return value.raw || value.hex || "";
  }

  function isFaqAnswerWrapperElement(el) {
    if (!el || !el.settings) return false;
    var label = String(el.label || "").toLowerCase();
    return (
      label === "answer wrapper" ||
      hasElementAttributeValue(el.settings, "itemprop", "acceptedAnswer") ||
      hasElementAttributeValue(
        el.settings,
        "itemtype",
        "https://schema.org/Answer",
      )
    );
  }

  function isFaqQuestionElement(el) {
    if (!isTextLikeElement(el)) return false;
    var label = String(el.label || "").toLowerCase();
    return (
      label === "question" ||
      hasElementAttributeValue(el.settings || {}, "itemprop", "name")
    );
  }

  function isTextLikeElement(el) {
    return !!(
      el &&
      (el.name === "heading" ||
        el.name === "text" ||
        el.name === "text-basic" ||
        el.name === "button") &&
      el.settings &&
      cleanFigmaText(el.settings.text)
    );
  }

  function cleanFigmaText(value) {
    return String(value || "")
      .replace(/<br\s*\/?>/gi, "\n")
      .replace(/<\/p\s*>/gi, "\n")
      .replace(/<[^>]+>/g, "")
      .replace(/&nbsp;/gi, " ")
      .replace(/&#038;|&amp;/gi, "&")
      .replace(/&quot;/gi, '"')
      .replace(/&#039;|&apos;/gi, "'")
      .replace(/&lt;/gi, "<")
      .replace(/&gt;/gi, ">")
      .replace(/[ \t]+\n/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .trim();
  }

  function uniqueStrings(values) {
    var seen = {};
    return values.filter(function (value) {
      if (seen[value]) return false;
      seen[value] = true;
      return true;
    });
  }

  function expandDropdownsForFigma(exportData) {
    if (!exportData || !Array.isArray(exportData.pages)) return;
    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        if (!section || !Array.isArray(section.bricks_elements)) return;
        expandFlatDropdownElements(section.bricks_elements);
      });
    });
  }

  function expandFlatDropdownElements(elements) {
    elements.forEach(function (el) {
      if (!el) return;
      el.settings = el.settings || {};

      if (el.name === "nav-nested") {
        appendElementClass(el, "brx-open");
        // Rename so Brixies treats it as a plain container
        el.name = "block";
        appendElementClass(el, "aisb-figma-nav-nested");
      }

      if (el.name === "dropdown") {
        // Rename so Brixies doesn't apply collapsed dropdown rendering
        el.name = "block";
        appendElementClass(el, "aisb-figma-dropdown");
      }

      var classString = getElementClassString(el.settings);
      if (
        classString.indexOf("brx-dropdown-content") !== -1 ||
        classString.indexOf("brx-nav-nested-items") !== -1
      ) {
        expandDropdownContentForFigma(el);
      }
    });
  }

  function expandDropdownContentForFigma(el) {
    el.settings = el.settings || {};
    // Remove classes that trigger Bricks default display:none CSS in Figma plugin
    removeElementClass(el, "brx-dropdown-content");
    removeElementClass(el, "brx-nav-nested-items");
    appendElementClass(el, "aisb-figma-expanded-content");
    delete el.settings._hidden;

    el.settings._display = "block";
    el.settings.display = "block";
    el.settings._visibility = "visible";
    el.settings.visibility = "visible";
    el.settings._opacity = "1";
    el.settings.opacity = "1";
    el.settings._overflow = "visible";
    el.settings.overflow = "visible";
    el.settings.ariaHidden = "false";
    el.settings._cssCustom =
      String(el.settings._cssCustom || "") +
      "\n&{display:block!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}";
    setElementAttribute(el.settings, "aria-hidden", "false");
  }

  function expandFormsForFigma(exportData) {
    if (!exportData || !Array.isArray(exportData.pages)) return;
    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        if (!section || !Array.isArray(section.bricks_elements)) return;
        expandFlatFormElements(section.bricks_elements);
      });
    });
  }

  function expandFlatFormElements(elements) {
    var newElements = [];
    elements.forEach(function (el) {
      if (!el || el.name !== "form") return;
      el.name = "block";
      appendElementClass(el, "aisb-figma-form");
      var formId = String(el.id || "");
      if (!Array.isArray(el.children)) el.children = [];
      var fields =
        el.settings && Array.isArray(el.settings.fields)
          ? el.settings.fields
          : [];
      fields.forEach(function (field, idx) {
        if (!field) return;
        var type = field.type || "text";
        var label = (field.label || "").trim(); // explicit label only
        var placeholder = (field.placeholder || "").trim();
        var synthId = "aisb_f_" + (field.id || idx);
        var synthName, synthText;
        if (type === "submit") {
          // Submit → button: settings.text, no text_styles position consumed
          synthName = "button";
          synthText = label || placeholder || field.value || "Verzenden";
        } else if (label) {
          // Labeled field → text: Bricks renders <label> in DOM so a text leaf exists
          synthName = "text";
          synthText = label;
        } else if (placeholder) {
          // Placeholder-only → button: no <label> in DOM, must not consume a text_styles position
          synthName = "button";
          synthText = placeholder;
        } else {
          return;
        }
        var synth = {
          id: synthId,
          name: synthName,
          parent: formId,
          settings: { text: synthText },
          children: [],
        };
        newElements.push(synth);
        el.children.push(synthId); // keep parent→children in sync for Brixies DFS
      });
    });
    newElements.forEach(function (synth) {
      elements.push(synth);
    });
  }

  function getDescendantIds(parentId, childrenByParent) {
    var result = [];
    var stack = (childrenByParent[parentId] || []).slice();

    while (stack.length) {
      var id = String(stack.shift() || "");
      if (!id || result.indexOf(id) !== -1) continue;
      result.push(id);
      (childrenByParent[id] || []).forEach(function (childId) {
        stack.push(String(childId));
      });
    }

    return result;
  }

  function expandAccordionDescendantForFigma(el) {
    el.settings = el.settings || {};
    var classString = getElementClassString(el.settings);

    var isContentWrapper =
      String(classString).indexOf("accordion-content-wrapper") !== -1;
    // Fallback: Bricks stores collapsed state in _hidden — expand any hidden
    // descendant inside an accordion even if the class name differs.
    var isHiddenBlock =
      !isContentWrapper &&
      el.settings._hidden &&
      typeof el.settings._hidden === "object";

    if (isContentWrapper || isHiddenBlock) {
      if (isContentWrapper) {
        removeElementClass(el, "accordion-content-wrapper");
      }
      appendElementClass(el, "aisb-figma-expanded-content");
      delete el.settings._hidden;
      el.settings._display = "flex";
      el.settings.display = "flex";
      el.settings._direction = "column";
      el.settings.flexDirection = "column";
      el.settings._visibility = "visible";
      el.settings.visibility = "visible";
      el.settings._opacity = "1";
      el.settings.opacity = "1";
      el.settings._height = "auto";
      el.settings.height = "auto";
      el.settings._overflow = "visible";
      el.settings.overflow = "visible";
      el.settings.ariaHidden = "false";
      el.settings._cssCustom =
        String(el.settings._cssCustom || "") +
        "\n&{display:flex!important;flex-direction:column!important;height:auto!important;opacity:1!important;visibility:visible!important;overflow:visible!important;}";
      setElementAttribute(el.settings, "aria-hidden", "false");
    }

    if (
      el.settings.customTag === "button" ||
      hasElementAttribute(el.settings, "aria-expanded")
    ) {
      setElementAttribute(el.settings, "aria-expanded", "true");
      el.settings.ariaExpanded = "true";
    }
  }

  function getElementClassString(settings) {
    var parts = [
      settings._cssClasses,
      settings.cssClasses,
      settings.class,
      settings._class,
      settings._hidden && settings._hidden._cssClasses,
      settings._hidden && settings._hidden.cssClasses,
    ];

    if (Array.isArray(settings._attributes)) {
      settings._attributes.forEach(function (attr) {
        if (attr && attr.name === "class") parts.push(attr.value);
      });
    }

    return parts
      .map(function (part) {
        return String(part || "");
      })
      .filter(Boolean)
      .join(" ");
  }

  function appendElementClass(el, className) {
    el.settings = el.settings || {};
    var classes = String(el.settings._cssClasses || "")
      .split(/\s+/)
      .filter(Boolean);
    if (classes.indexOf(className) === -1) classes.push(className);
    el.settings._cssClasses = classes.join(" ");
  }

  function removeElementClass(el, className) {
    if (!el || !el.settings) return;
    ["_cssClasses", "cssClasses", "_class", "class"].forEach(function (key) {
      if (!el.settings[key]) return;
      el.settings[key] = el.settings[key]
        .split(/\s+/)
        .filter(function (c) {
          return c && c !== className;
        })
        .join(" ");
    });
    // Also strip from _hidden sub-object
    if (el.settings._hidden) {
      ["_cssClasses", "cssClasses"].forEach(function (key) {
        if (!el.settings._hidden[key]) return;
        el.settings._hidden[key] = el.settings._hidden[key]
          .split(/\s+/)
          .filter(function (c) {
            return c && c !== className;
          })
          .join(" ");
      });
    }
  }

  function hasElementAttribute(settings, name) {
    return (
      Array.isArray(settings._attributes) &&
      settings._attributes.some(function (attr) {
        return attr && attr.name === name;
      })
    );
  }

  function hasElementAttributeValue(settings, name, value) {
    return (
      Array.isArray(settings._attributes) &&
      settings._attributes.some(function (attr) {
        return attr && attr.name === name && String(attr.value || "") === value;
      })
    );
  }

  function setElementAttribute(settings, name, value) {
    if (!Array.isArray(settings._attributes)) settings._attributes = [];
    var attr = settings._attributes.find(function (item) {
      return item && item.name === name;
    });
    if (attr) {
      attr.value = value;
      return;
    }
    settings._attributes.push({
      id: "aisb_" + name.replace(/[^a-z0-9]/gi, "_"),
      name: name,
      value: value,
    });
  }

  function buildBorderRadiusPatchMap(patches) {
    var map = {};
    if (!Array.isArray(patches)) return map;

    patches.forEach(function (op) {
      if (
        op &&
        op.type === "css" &&
        op.prop === "border-radius" &&
        op.selector
      ) {
        map[op.selector] = String(op.value || "");
      }
    });

    return map;
  }

  function isZeroRadius(value) {
    var normalised = String(value || "")
      .trim()
      .toLowerCase();
    return (
      normalised === "" ||
      normalised === "0" ||
      normalised === "0px" ||
      normalised === "0%" ||
      normalised === "0em" ||
      normalised === "0rem"
    );
  }

  function getBorderRadiusStyle(computed, explicitValue) {
    var topLeft = computed.borderTopLeftRadius || "";
    var topRight = computed.borderTopRightRadius || "";
    var bottomRight = computed.borderBottomRightRadius || "";
    var bottomLeft = computed.borderBottomLeftRadius || "";
    var corners = [topLeft, topRight, bottomRight, bottomLeft];
    var hasVisibleRadius = corners.some(function (value) {
      return !isZeroRadius(value);
    });
    var hasExplicitRadius = explicitValue !== undefined;

    if (!hasVisibleRadius && !hasExplicitRadius) return null;

    var borderRadius =
      explicitValue !== undefined
        ? String(explicitValue)
        : computed.borderRadius;
    if (!borderRadius) {
      if (
        topLeft === topRight &&
        topLeft === bottomRight &&
        topLeft === bottomLeft
      ) {
        borderRadius = topLeft;
      } else if (topLeft === bottomRight && topRight === bottomLeft) {
        borderRadius = topLeft + " " + topRight;
      } else if (topRight === bottomLeft) {
        borderRadius = topLeft + " " + topRight + " " + bottomRight;
      } else {
        borderRadius = corners.join(" ");
      }
    }

    return {
      borderRadius: borderRadius,
      _borderRadius: borderRadius,
      borderTopLeftRadius: topLeft,
      borderTopRightRadius: topRight,
      borderBottomRightRadius: bottomRight,
      borderBottomLeftRadius: bottomLeft,
    };
  }

  function collectDomElementBorderStyles(doc, win, radiusPatchBySelector) {
    if (!doc || !doc.body || !win) return [];

    var result = [];
    Array.from(doc.body.querySelectorAll("*")).forEach(function (el) {
      if (SKIP_TEXT_EXPORT[el.tagName]) return;

      var selector = D._buildElementSelector
        ? D._buildElementSelector(el, doc.body)
        : "";
      var computed = win.getComputedStyle(el);
      var radius = getBorderRadiusStyle(
        computed,
        selector ? radiusPatchBySelector[selector] : undefined,
      );
      if (!radius) return;

      result.push(
        Object.assign(
          {
            selector: selector,
            tag: (el.tagName || "").toLowerCase(),
            id: el.id || "",
            className: String(el.className || ""),
            text: (el.innerText || "").trim().slice(0, 80),
          },
          radius,
        ),
      );
    });

    return result;
  }

  /**
   * Herorden style_guide.images op basis van de daadwerkelijke top-to-bottom
   * volgorde van de iframes in het canvas (step4). Dit weerspiegelt eventuele
   * drag/drop herordeningen die de gebruiker heeft toegepast.
   *
   * Per iframe gebruiken we `_pageSlug + ":" + _localSectionIdx` om in
   * D.buildSectionImageMap() de toegewezen URLs op te zoeken; vervolgens
   * herbouwen we de globale lijst in DOM-volgorde en bouwen we tegelijk de
   * `exportData.pages` array opnieuw zodat ook die in canvas-volgorde staat.
   */
  function reorderImagesForFigma(exportData) {
    if (!D.guide || !D.guide.images || !D.guide.images.length) return;
    if (!Array.isArray(exportData.pages)) return;

    var canvasEl = D.canvasEl;
    if (!canvasEl) return;

    var pageCards = canvasEl.querySelectorAll(".aisb-design-page-card");
    if (!pageCards.length) return;

    // Sectie-image map (sleutel = pageSlug:localSectionIdx → [url,...])
    var sectionMap = D.buildSectionImageMap ? D.buildSectionImageMap() : {};

    // URL → guide image object lookup
    var urlToImg = {};
    D.guide.images.forEach(function (img) {
      if (img.full) urlToImg[img.full] = img;
      if (img.thumb) urlToImg[img.thumb] = img;
    });

    // Lookup: pageSlug → page-object uit exportData
    var pageBySlug = {};
    exportData.pages.forEach(function (p) {
      if (p && p.slug) pageBySlug[p.slug] = p;
    });

    // Lookup binnen een page: uuid → section, en postId → section (fallback)
    function makeSectionLookup(page) {
      var byUuid = {},
        byPostId = {};
      (page.sections || []).forEach(function (sec) {
        if (sec.uuid) byUuid[sec.uuid] = sec;
        var pid = sec.ai_wireframe_id || sec.bricks_template_id;
        if (pid) byPostId[String(pid)] = sec;
      });
      return { byUuid: byUuid, byPostId: byPostId };
    }

    var orderedImages = [];
    var usedKeys = {};
    var orderedPages = [];

    pageCards.forEach(function (card) {
      // Bepaal page slug uit de eerste iframe-wrap binnen deze card
      var firstWrap = card.querySelector(".aisb-design-iframe-wrap");
      if (!firstWrap) return;
      var pageSlug = firstWrap.dataset.pageSlug || "";
      var page = pageBySlug[pageSlug];
      if (!page) return;

      var lookup = makeSectionLookup(page);

      var newSections = [];
      var wraps = card.querySelectorAll(".aisb-design-iframe-wrap");
      wraps.forEach(function (wrap) {
        var iframe = wrap.querySelector("iframe");
        if (!iframe) return;

        // Vind de bijhorende sectie in exportData
        var section = null;
        var uuid =
          wrap.dataset.uuid ||
          (iframe._sectionData && iframe._sectionData.uuid);
        if (uuid && lookup.byUuid[uuid]) {
          section = lookup.byUuid[uuid];
        } else {
          var pid = iframe._sectionPostId;
          if (pid && lookup.byPostId[String(pid)]) {
            section = lookup.byPostId[String(pid)];
          }
        }
        if (section) newSections.push(section);

        // Pak de afbeeldingen voor deze sectie via de originele mapping
        var localIdx =
          typeof iframe._localSectionIdx === "number"
            ? iframe._localSectionIdx
            : iframe._sectionIdx;
        var key = pageSlug + ":" + localIdx;
        var urls = sectionMap[key] || [];
        var imageCount =
          section && section.image_count ? section.image_count : urls.length;

        for (var i = 0; i < imageCount; i++) {
          var url = urls[i];
          if (!url) continue;
          var img = urlToImg[url];
          if (!img) continue;
          var imgKey = img.full || img.thumb || "";
          if (imgKey && !usedKeys[imgKey]) {
            usedKeys[imgKey] = true;
            orderedImages.push(img);
          }
        }
      });

      // Herbouw page met secties in canvas-volgorde
      orderedPages.push(Object.assign({}, page, { sections: newSections }));
    });

    // Voeg eventuele niet-gebruikte images toe (vangnet)
    // Uitgeschakeld: we behouden alleen images die daadwerkelijk in het design
    // gebruikt worden. De overige stockfoto's uit de pool zijn overbodig.
    // D.guide.images.forEach(function (img) {
    //   var key = img.full || img.thumb || "";
    //   if (key && !usedKeys[key]) {
    //     usedKeys[key] = true;
    //     orderedImages.push(img);
    //   }
    // });

    if (orderedImages.length) {
      exportData.style_guide.images = orderedImages;
    }
    if (orderedPages.length) {
      exportData.pages = orderedPages;
    }
  }

  /**
   * Resolve Bricks CSS variable color references to actual hex/rgb values by
   * reading computed styles from a live iframe. This ensures the Figma plugin
   * gets real color values instead of unresolvable CSS var() strings.
   *
   * Bricks stores colors as: { raw: "var(--primary)", id: "...", name: "..." }
   * We resolve the var() part and store the result back in `raw`.
   */
  /**
   * Download een object als JSON-bestand.
   */
  function downloadJson(obj, filename) {
    const json = JSON.stringify(obj, null, 2);
    const blob = new Blob([json], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  /* ── Button listeners ────────────────────────────────────────── */

  function openInteractiveElementsInIframes() {
    var css = [
      // Accordion content wrappers (accordion-nested)
      ".accordion-content-wrapper{display:block!important;height:auto!important;overflow:visible!important;opacity:1!important;visibility:visible!important;}",
      // Accordion item blocks with brx-open
      ".brxe-accordion-nested .block{display:flex!important;flex-direction:column!important;}",
      // Dropdown content
      ".brx-dropdown-content{display:block!important;visibility:visible!important;opacity:1!important;height:auto!important;overflow:visible!important;}",
      // Nav nested mobile menu items list
      ".brx-nav-nested-items{display:flex!important;flex-wrap:wrap!important;visibility:visible!important;opacity:1!important;}",
    ].join("\n");

    (D.allIframes || []).forEach(function (iframe) {
      try {
        var doc = iframe.contentDocument;
        if (!doc || !doc.head) return;
        var prev = doc.getElementById("aisb-figma-expand-css");
        if (prev) prev.parentNode.removeChild(prev);
        var style = doc.createElement("style");
        style.id = "aisb-figma-expand-css";
        style.textContent = css;
        doc.head.appendChild(style);
      } catch (e) {
        // cross-origin iframe — skip
      }
    });

    // Two animation frames so the browser applies the new styles before we read the DOM
    return new Promise(function (resolve) {
      requestAnimationFrame(function () {
        requestAnimationFrame(resolve);
      });
    });
  }

  function closeInteractiveElementsInIframes() {
    (D.allIframes || []).forEach(function (iframe) {
      try {
        var doc = iframe.contentDocument;
        if (!doc) return;
        var el = doc.getElementById("aisb-figma-expand-css");
        if (el) el.parentNode.removeChild(el);
      } catch (e) {}
    });
  }

  function collectBricksElementsById(exportData) {
    var elementMap = {};

    function collectElement(el) {
      if (!el || typeof el !== "object") return;
      if (el.id) {
        var id = String(el.id);
        if (!elementMap[id]) elementMap[id] = [];
        if (elementMap[id].indexOf(el) === -1) elementMap[id].push(el);
      }
      if (Array.isArray(el.children)) {
        el.children.forEach(function (child) {
          if (child && typeof child === "object") collectElement(child);
        });
      }
    }

    exportData.pages.forEach(function (page) {
      (page.sections || []).forEach(function (section) {
        ["bricks_elements", "bricks_elements_bricks"].forEach(function (key) {
          (section[key] || []).forEach(collectElement);
        });
      });
    });
    return elementMap;
  }

  function ensureBricksSettings(el) {
    if (!el) return null;
    if (
      !el.settings ||
      Array.isArray(el.settings) ||
      typeof el.settings !== "object"
    ) {
      el.settings = {};
    }
    return el.settings;
  }

  function resolveLiveBorderRadii(exportData) {
    if (!Array.isArray(exportData.pages)) return;

    var elementMap = collectBricksElementsById(exportData);

    function isCssVar(v) {
      return String(v || "")
        .trim()
        .startsWith("var(");
    }

    function hasAnyCssVar(settings) {
      if (isCssVar(settings._borderRadius) || isCssVar(settings.borderRadius))
        return true;
      var r = settings._border && settings._border.radius;
      if (r && typeof r === "object") {
        return Object.values(r).some(isCssVar);
      }
      return false;
    }

    function isZeroPx(v) {
      var value = String(v || "")
        .trim()
        .toLowerCase();
      return (
        !value ||
        value === "0" ||
        /^0(?:px|%|em|rem|vh|vw)?(?:\s+0(?:px|%|em|rem|vh|vw)?)*$/.test(value)
      );
    }

    function hasVisibleRadius(corners) {
      return !(
        isZeroPx(corners.tl) &&
        isZeroPx(corners.tr) &&
        isZeroPx(corners.br) &&
        isZeroPx(corners.bl)
      );
    }

    function readRadius(win, el) {
      var computed = win.getComputedStyle(el);
      return {
        tl: computed.borderTopLeftRadius || "",
        tr: computed.borderTopRightRadius || "",
        br: computed.borderBottomRightRadius || "",
        bl: computed.borderBottomLeftRadius || "",
      };
    }

    function radiusOwner(el) {
      if (!el || !el.closest) return null;
      if (el.matches && el.matches("[id^='brxe-']")) return el;
      return el.closest("[id^='brxe-']");
    }

    function isSameRadiusOwner(candidate, rootEl) {
      var rootOwner = radiusOwner(rootEl);
      var candidateOwner = radiusOwner(candidate);
      return rootOwner ? candidateOwner === rootOwner : !candidateOwner;
    }

    function getComputedRadius(win, domEl) {
      var direct = readRadius(win, domEl);
      if (hasVisibleRadius(direct)) return direct;

      var preferredSelector = [
        ".bricks-button",
        "button",
        "a[class*='button']",
        "a",
        ".brxe-image img",
        "picture img",
        "figure img",
        "img",
        "video",
      ].join(",");
      var seen = [];
      var preferred = [];

      function addCandidate(candidate) {
        if (!candidate || candidate === domEl) return;
        if (seen.indexOf(candidate) !== -1) return;
        seen.push(candidate);
        if (!isSameRadiusOwner(candidate, domEl)) return;
        preferred.push(candidate);
      }

      if (domEl.firstElementChild) addCandidate(domEl.firstElementChild);
      Array.from(domEl.querySelectorAll(preferredSelector)).forEach(
        addCandidate,
      );

      for (var i = 0; i < preferred.length; i++) {
        var preferredCorners = readRadius(win, preferred[i]);
        if (hasVisibleRadius(preferredCorners)) return preferredCorners;
      }

      var descendants = Array.from(domEl.querySelectorAll("*"));
      var checked = 0;
      for (var di = 0; di < descendants.length && checked < 60; di++) {
        var candidate = descendants[di];
        if (seen.indexOf(candidate) !== -1) continue;
        if (!isSameRadiusOwner(candidate, domEl)) continue;
        checked++;
        var candidateCorners = readRadius(win, candidate);
        if (hasVisibleRadius(candidateCorners)) return candidateCorners;
      }

      return direct;
    }

    function applyRadiusToSettings(settings, corners) {
      var tl = corners.tl,
        tr = corners.tr,
        br = corners.br,
        bl = corners.bl;
      if (isZeroPx(tl) && isZeroPx(tr) && isZeroPx(br) && isZeroPx(bl)) return;

      var fallback = [tl, tr, br, bl].find(function (value) {
        return value && !isZeroPx(value);
      });
      if (fallback) {
        if (!tl) tl = fallback;
        if (!tr) tr = fallback;
        if (!br) br = fallback;
        if (!bl) bl = fallback;
      }

      var resolved =
        tl === tr && tl === br && tl === bl
          ? tl
          : tl + " " + tr + " " + br + " " + bl;

      settings._borderRadius = resolved;
      settings.borderRadius = resolved;

      // Bricks _border.radius: top=top-left, right=top-right,
      // bottom=bottom-right, left=bottom-left.
      if (!settings._border) settings._border = {};
      if (
        !settings._border.radius ||
        typeof settings._border.radius !== "object"
      )
        settings._border.radius = {};
      settings._border.radius.top = tl;
      settings._border.radius.right = tr;
      settings._border.radius.bottom = br;
      settings._border.radius.left = bl;
    }

    (D.allIframes || []).forEach(function (iframe) {
      try {
        var doc = iframe.contentDocument;
        var win = iframe.contentWindow;
        if (!doc || !win) return;

        // Always sync bricks_elements border-radius from live computed styles.
        // This captures both CSS-variable radii (unresolvable by Figma) and
        // border-radius values applied via user CSS patches (section.patch),
        // which Brixies never reads directly.
        doc.querySelectorAll("[id^='brxe-']").forEach(function (domEl) {
          var bricksId = domEl.id.replace(/^brxe-/, "");
          var corners = getComputedRadius(win, domEl);
          if (
            isZeroPx(corners.tl) &&
            isZeroPx(corners.tr) &&
            isZeroPx(corners.br) &&
            isZeroPx(corners.bl)
          ) {
            return;
          }
          (elementMap[bricksId] || []).forEach(function (bricksEl) {
            var settings = ensureBricksSettings(bricksEl);
            if (settings) applyRadiusToSettings(settings, corners);
          });
        });

        // Resolve global_classes: Brixies reads these to compute final styles
        // for elements that reference the class via _cssGlobalClasses. Find any
        // DOM element using the class ID and read its computed radius.
        if (Array.isArray(exportData.global_classes)) {
          exportData.global_classes.forEach(function (cls) {
            if (!cls || !cls.id || !cls.settings) return;
            var domEl = doc.querySelector("." + String(cls.id));
            if (!domEl) return;
            var corners = getComputedRadius(win, domEl);
            applyRadiusToSettings(cls.settings, corners);
          });
        }
      } catch (e) {}
    });
  }

  function resolveLiveButtonBackgrounds(exportData) {
    if (!Array.isArray(exportData.pages)) return;

    var elementMap = collectBricksElementsById(exportData);

    (D.allIframes || []).forEach(function (iframe) {
      try {
        var doc = iframe.contentDocument;
        var win = iframe.contentWindow;
        if (!doc || !win) return;

        doc.querySelectorAll("[id^='brxe-']").forEach(function (domEl) {
          var bricksId = domEl.id.replace(/^brxe-/, "");
          var bricksEls = (elementMap[bricksId] || []).filter(
            function (bricksEl) {
              return bricksEl && bricksEl.name === "button";
            },
          );
          if (!bricksEls.length) return;

          // Background and spacing styling live on the inner .bricks-button element.
          var innerEl = domEl.querySelector(".bricks-button") || domEl;
          var computed = win.getComputedStyle(innerEl);
          var bgRaw = computed.backgroundColor || "";
          var isTransparent =
            !bgRaw || bgRaw === "rgba(0, 0, 0, 0)" || bgRaw === "transparent";

          bricksEls.forEach(function (bricksEl) {
            var settings = ensureBricksSettings(bricksEl);
            if (!settings) return;

            if (!settings._background) settings._background = {};

            if (isTransparent) {
              settings._background.color = { raw: "transparent" };
            } else {
              var hex = cssColorToHex(bgRaw) || bgRaw;
              settings._background.color = { raw: hex };
            }

            var padding =
              settings._padding && typeof settings._padding === "object"
                ? Object.assign({}, settings._padding)
                : {};
            padding.top = computed.paddingTop || padding.top || "";
            padding.right = computed.paddingRight || padding.right || "";
            padding.bottom = computed.paddingBottom || padding.bottom || "";
            padding.left = computed.paddingLeft || padding.left || "";
            settings._padding = padding;
          });
        });
      } catch (e) {}
    });
  }

  async function runExport() {
    const result = await D.post("aisb_export_figma_json", {
      project_id: D.projectId,
    });
    if (!result || !result.success) {
      throw new Error(
        (result && result.data && result.data.message) || "Export failed",
      );
    }
    const exportData = result.data.export;
    applyLivePatches(exportData, buildLivePatchMap());
    applyLiveStyleGuide(exportData);
    // Force accordions/dropdowns open in iframes to capture expanded DOM state,
    // then restore so the user can still toggle them manually afterwards.
    await openInteractiveElementsInIframes();
    expandAccordionsForFigma(exportData);
    expandDropdownsForFigma(exportData);
    expandFormsForFigma(exportData);
    annotateSectionBgColors(exportData);
    annotateLiveTextContent(exportData);
    flattenFaqSectionsForFigma(exportData);
    resolveLiveButtonBackgrounds(exportData);
    resolveLiveBorderRadii(exportData);
    reorderImagesForFigma(exportData);
    closeInteractiveElementsInIframes();
    return exportData;
  }

  D.exportLiveDesign = runExport;

  function withBtnFeedback(btn, workingLabel, successLabel, action) {
    const originalLabel = btn.textContent;
    btn.disabled = true;
    btn.textContent = workingLabel;
    action()
      .then(function () {
        btn.textContent = successLabel;
        btn.classList.add("is-saved");
        setTimeout(function () {
          btn.disabled = false;
          btn.textContent = originalLabel;
          btn.classList.remove("is-saved");
        }, 2500);
      })
      .catch(function (err) {
        console.error("[AISB] figma-export error:", err);
        btn.disabled = false;
        btn.textContent = "⚠ Fout bij export";
        setTimeout(function () {
          btn.textContent = originalLabel;
        }, 2500);
      });
  }

  const copyBtn = document.getElementById("aisb-design-figma-copy-btn");
  const dlBtn = document.getElementById("aisb-design-figma-download-btn");

  function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      return navigator.clipboard.writeText(text);
    }
    // Fallback for non-HTTPS (local dev)
    return new Promise(function (resolve, reject) {
      try {
        const ta = document.createElement("textarea");
        ta.value = text;
        ta.style.cssText = "position:fixed;top:-9999px;left:-9999px;opacity:0";
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        const ok = document.execCommand("copy");
        document.body.removeChild(ta);
        ok ? resolve() : reject(new Error("execCommand copy failed"));
      } catch (e) {
        reject(e);
      }
    });
  }

  if (copyBtn) {
    copyBtn.addEventListener("click", function () {
      withBtnFeedback(copyBtn, "⏳ Bezig…", "✓ Gekopieerd!", async function () {
        const data = await runExport();
        await copyToClipboard(JSON.stringify(data, null, 2));
      });
    });
  }

  if (dlBtn) {
    dlBtn.addEventListener("click", function () {
      withBtnFeedback(dlBtn, "⏳ Bezig…", "✓ Gedownload!", async function () {
        const data = await runExport();
        downloadJson(data, "figma-export-project-" + D.projectId + ".json");
      });
    });
  }
})();
