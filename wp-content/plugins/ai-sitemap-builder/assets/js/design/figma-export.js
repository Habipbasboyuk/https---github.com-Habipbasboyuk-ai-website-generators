/**
 * design/figma-export.js — "Export to Figma" knop in de Design-toolbar.
 *
 * Verzamelt alle pagina's, secties, patches (inclusief ongeslagen) en de
 * style guide in één JSON en downloadt die als bestand.
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

    // Start vanuit opgeslagen patches
    Object.keys(D._savedPatches || {}).forEach(function (postId) {
      map[postId] = (D._savedPatches[postId] || []).slice();
    });

    // Overschrijf / voeg toe met huidige iframe-staat
    (D.allIframes || []).forEach(function (iframe) {
      const postId = String(iframe._sectionPostId || "");
      if (!postId) return;

      const saved = D._savedPatches[postId] || [];
      const unsaved = iframe._aisbPatch || [];
      if (!unsaved.length) return;

      // Merge: key = type|selector[|prop]
      const byKey = {};
      saved.forEach(function (op) {
        byKey[patchKey(op)] = op;
      });
      unsaved.forEach(function (op) {
        byKey[patchKey(op)] = op;
      });
      map[postId] = Object.values(byKey);
    });

    return map;
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
    var computedBg1 = palLight || guide.sectionBg1 || "#ffffff";
    // oneven secties: Neutral-kleur | Light-kleur | sectionBg2-fallback
    var computedBg2 = palNeutral || palLight || guide.sectionBg2 || "#f0f4ff";

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
    var palLight =
      find("Light") || (colours[4] ? colours[4].hex : "") || "#ffffff";
    var palNeutral =
      find("Neutral") || (colours[5] ? colours[5].hex : "") || palLight;

    var bgFor = function (bgIdx) {
      return bgIdx % 2 === 0
        ? palLight || guide.sectionBg1 || "#ffffff"
        : palNeutral || palLight || guide.sectionBg2 || "#f0f4ff";
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
      });
    });
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
    annotateSectionBgColors(exportData);
    reorderImagesForFigma(exportData);
    return exportData;
  }

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
