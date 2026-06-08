/**
 * design/core.js - Gedeelde state en helperfuncties voor de designpreview.
 *
 * Wordt als eerste geladen in de keten:
 *   core.js -> overrides.js -> images.js -> canvas.js -> init.js
 *
 * Stelt window.AISB_Design (alias D) beschikbaar zodat alle andere scripts
 * de gedeelde state en helpers kunnen bereiken.
 */
(function () {
  "use strict";

  const root = document.querySelector("[data-design]");
  if (!root) return;

  const projectId =
    parseInt(root.getAttribute("data-design-project") || "0", 10) || 0;
  if (!projectId) return;

  const canvasEl = root.querySelector("[data-design-canvas]");
  if (!canvasEl) return;

  /* ── Gedeelde state ─────────────────────────────────────────── */
  const D = {
    root,
    projectId,
    canvasEl,
    guide: {},
    wireframePages: [],
    allIframes: [],
    _savedPatches: {}, // postId (string) → array van patch-operaties
  };

  /* ── Helpers ────────────────────────────────────────────────── */

  /**
   * Pas spiegeling toe op een iframe.
   * Detecteert welke elementen *daadwerkelijk* flex-direction:row hebben via
   * getComputedStyle en draait alleen díe om — zo werkt het ongeacht hoe
   * diep de kolommen in de Bricks-structuur zitten.
   */
  D._applySectionMirror = function (doc, iframe) {
    const STYLE_ID = "aisb-section-mirror";
    let style = doc.getElementById(STYLE_ID);
    if (!style) {
      style = doc.createElement("style");
      style.id = STYLE_ID;
      doc.head.appendChild(style);
    }

    // Zoek de directe layout-container van elke sectie:
    // alleen de directe kinderen van .brxe-section die flex-row zijn.
    // Zo spiegelen we de kolommen-laag, niet de diepere rijen (knoppen, etc.).
    const win = doc.defaultView;
    const selectors = [];
    if (win) {
      doc.body.querySelectorAll(".brxe-section").forEach(function (section) {
        Array.from(section.children).forEach(function (child) {
          const fdir = win.getComputedStyle(child).flexDirection;
          if (fdir === "row" && child.id) {
            selectors.push("#" + child.id);
          }
        });
      });
    }

    if (selectors.length) {
      style.textContent =
        selectors.join(",") + "{ flex-direction: row-reverse !important; }";
    } else {
      /* Fallback */
      style.textContent =
        ".brxe-section > .brxe-container," +
        ".brxe-section > .brxe-block," +
        ".brxe-section > .brxe-div { flex-direction: row-reverse !important; }";
    }
    iframe._aisbMirrored = true;
  };

  D.escapeHtml = function (text) {
    return String(text || "").replace(
      /[&<>"']/g,
      (c) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[c],
    );
  };

  D.toQueryString = function (params) {
    return Object.keys(params)
      .map((k) => encodeURIComponent(k) + "=" + encodeURIComponent(params[k]))
      .join("&");
  };

  D.post = async function (action, data) {
    const r = await fetch(AISB_DESIGN.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: D.toQueryString(
        Object.assign({ action, nonce: AISB_DESIGN.nonce }, data || {}),
      ),
    });

    const raw = await r.text();
    let parsed = null;

    if (raw) {
      try {
        parsed = JSON.parse(raw);
      } catch (err) {
        if (!r.ok) {
          throw new Error(
            "Request failed (" +
              r.status +
              " " +
              r.statusText +
              "): " +
              raw.slice(0, 300),
          );
        }

        throw new Error(
          "Invalid server response (" + r.status + " " + r.statusText + ")",
        );
      }
    }

    if (!r.ok) {
      const message =
        (parsed && parsed.data && parsed.data.message) ||
        (parsed && parsed.message) ||
        raw ||
        "Request failed (" + r.status + " " + r.statusText + ")";
      const error = new Error(message);
      error.status = r.status;
      error.response = parsed;
      throw error;
    }

    return parsed;
  };

  D.clamp = function (v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
  };

  function hasRichTextMarkup(text) {
    return /<\s*br\s*\/?>|<\s*\/?\s*p\b|<\s*span\b/i.test(String(text || ""));
  }

  function extractSafeInlineColor(el) {
    if (!el || !el.getAttribute) return "";
    var styleAttr = String(el.getAttribute("style") || "");
    var match = styleAttr.match(/(?:^|;)\s*color\s*:\s*([^;]+)/i);
    if (!match) return "";
    var value = String(match[1] || "").trim();
    return /^(#[0-9a-f]{3,8}|rgba?\(\s*[\d\s.,%]+\)|hsla?\(\s*[\d\s.,%]+\)|var\(\s*--[a-z0-9_-]+\s*\)|[a-z-]+)$/i.test(
      value,
    )
      ? value
      : "";
  }

  function appendSanitizedRichText(parent, sourceNode, doc) {
    if (!sourceNode || !parent || !doc) return;

    if (sourceNode.nodeType === 3) {
      parent.appendChild(doc.createTextNode(sourceNode.textContent || ""));
      return;
    }

    if (sourceNode.nodeType !== 1) return;

    var tag = String(sourceNode.tagName || "").toLowerCase();
    if (tag === "br") {
      parent.appendChild(doc.createElement("br"));
      return;
    }

    if (tag === "p") {
      if (parent.childNodes && parent.childNodes.length) {
        parent.appendChild(doc.createElement("br"));
        parent.appendChild(doc.createElement("br"));
      }
      Array.from(sourceNode.childNodes || []).forEach(function (child) {
        appendSanitizedRichText(parent, child, doc);
      });
      return;
    }

    if (tag === "span") {
      var span = doc.createElement("span");
      var color = extractSafeInlineColor(sourceNode);
      if (color) span.style.color = color;
      Array.from(sourceNode.childNodes || []).forEach(function (child) {
        appendSanitizedRichText(span, child, doc);
      });
      parent.appendChild(span);
      return;
    }

    Array.from(sourceNode.childNodes || []).forEach(function (child) {
      appendSanitizedRichText(parent, child, doc);
    });
  }

  D._applyTextPatch = function (el, text, doc) {
    var targetDoc = doc || (el && el.ownerDocument);
    var raw = String(text || "");
    if (!el || !targetDoc) return;

    if (!hasRichTextMarkup(raw)) {
      el.innerText = raw;
      return;
    }

    var template = targetDoc.createElement("template");
    template.innerHTML = raw;

    var fragment = targetDoc.createDocumentFragment();
    Array.from(template.content.childNodes || []).forEach(function (child) {
      appendSanitizedRichText(fragment, child, targetDoc);
    });

    if (!fragment.childNodes || !fragment.childNodes.length) {
      el.innerText = template.content.textContent || raw;
      return;
    }

    while (el.firstChild) el.removeChild(el.firstChild);
    el.appendChild(fragment);
  };

  /**
   * Bouw een CSS-selector van `el` naar de iframe-body.
   * Gebruikt :nth-child zodat de weg stabiel is zolang het Bricks-template
   * dezelfde structuur rendert.
   */
  D._buildElementSelector = function (el, root) {
    const parts = [];
    let cur = el;
    while (cur && cur !== root && cur.parentElement) {
      const p = cur.parentElement;
      const idx = Array.from(p.children).indexOf(cur) + 1;
      parts.unshift(
        (cur.tagName || "DIV").toLowerCase() + ":nth-child(" + idx + ")",
      );
      cur = p;
    }
    return parts.join(" > ");
  };

  /**
   * Registreer een bewerkingsoperatie op een iframe.
   * Duplicaten (zelfde type+selector) worden overschreven zodat de laatste
   * waarde wint.
   */
  D._registerEdit = function (iframe, type, el, data) {
    if (!iframe) return;
    if (!iframe._aisbPatch) iframe._aisbPatch = [];
    const op = Object.assign({ type: type }, data || {});
    if (el) {
      try {
        const bodyEl = iframe.contentDocument && iframe.contentDocument.body;
        op.selector = bodyEl ? D._buildElementSelector(el, bodyEl) : "";
      } catch (e) {
        op.selector = "";
      }
    }
    // Dedupliceer op type + selector (+ prop voor css, zodat kleur/font/grootte etc.
    // elk hun eigen slot hebben en elkaar niet overschrijven).
    const key =
      type +
      "|" +
      (op.selector || "") +
      (type === "css" ? "|" + (op.prop || "") : "");
    const idx = iframe._aisbPatch.findIndex(function (p) {
      const k =
        p.type +
        "|" +
        (p.selector || "") +
        (p.type === "css" ? "|" + (p.prop || "") : "");
      return k === key;
    });
    if (idx >= 0) iframe._aisbPatch[idx] = op;
    else iframe._aisbPatch.push(op);
  };

  /**
   * Pas eerder opgeslagen patches toe op een iframe dat net geladen is.
   * Wordt aangeroepen na injectStyleGuide + injectSectionImages.
   */
  D.applyStoredEdits = function (iframe) {
    const postId = String(iframe._sectionPostId || "");
    const saved = D._savedPatches[postId] || [];
    const pending = (iframe && iframe._aisbPatch) || [];
    // Combineer opgeslagen + nog-niet-opgeslagen patches. In-memory
    // (pending) wint bij conflict, want dat is de meest recente bewerking.
    // Reden: bij sectie-verslepen reload Chrome de iframe → load-event
    // → applyStoredEdits — als we hier alleen _savedPatches lezen verdwijnen
    // ongesaved bewerkingen (bv. zojuist gewijzigde achtergrondkleur).
    const byKey = {};
    saved.forEach(function (op) {
      const k =
        op.type +
        "|" +
        (op.selector || "") +
        (op.type === "css" ? "|" + (op.prop || "") : "");
      byKey[k] = op;
    });
    pending.forEach(function (op) {
      const k =
        op.type +
        "|" +
        (op.selector || "") +
        (op.type === "css" ? "|" + (op.prop || "") : "");
      byKey[k] = op;
    });
    // Sorteer: mirror → text → img → css
    // CSS-patches altijd als LAATSTE toepassen zodat Bricks' interne
    // reactie op innerText-wijzigingen de kleur/stijl-overrides niet
    // kan resetten (Bricks heeft soms MutationObservers die inline
    // styles herstellen wanneer child-nodes veranderen).
    const ORDER = { mirror: 0, text: 1, img: 2, css: 3 };
    const patch = Object.values(byKey).sort(function (a, b) {
      return (ORDER[a.type] ?? 9) - (ORDER[b.type] ?? 9);
    });
    if (!patch.length) return;
    const doc = iframe.contentDocument;
    if (!doc || !doc.body) return;

    patch.forEach(function (op) {
      if (op.type === "mirror") {
        if (op.mirrored) D._applySectionMirror(doc, iframe);
        return;
      }
      if (!op.selector) return;
      const el = doc.body.querySelector(op.selector);
      if (!el) return;
      if (op.type === "text") {
        if (typeof D._applyTextPatch === "function") {
          D._applyTextPatch(el, op.text || "", doc);
        } else {
          el.innerText = op.text || "";
        }
        // Fix: Bricks counter-animatie overschrijft onze waarde na iframe-reload.
        // Bricks parseert countTo in closures VOORDAT applyStoredEdits loopt, en
        // IntersectionObserver roept u() aan die innerText = countFrom (0) zet
        // en het interval herstart. We blokkeren dit op drie manieren:
        //   1) data-attribuut bijwerken (toekomstige re-inits)
        //   2) counterId pre-setten → Bricks controleert (null == counterId)
        //      en slaat setInterval over als counterId al gezet is
        //   3) Waarde herstellen via iframe-setTimeout na async IO-callback
        const _cRoot = el.closest("[data-bricks-counter-options]");
        if (_cRoot) {
          try {
            const _iframeWin = iframe.contentWindow;
            const _lockedText = op.text || "";
            const _cOpts = JSON.parse(
              _cRoot.dataset.bricksCounterOptions || "{}",
            );
            const _cNum = parseFloat(
              String(_lockedText).replace(/[^\d.-]/g, ""),
            );
            if (!isNaN(_cNum)) {
              _cOpts.countTo = _cNum;
              _cOpts.countFrom = _cNum;
              _cRoot.dataset.bricksCounterOptions = JSON.stringify(_cOpts);
            }
            // Stop lopend interval (iframe-context clearInterval)
            if (
              el.dataset.counterId &&
              el.dataset.counterId !== "aisb-locked"
            ) {
              _iframeWin.clearInterval(Number(el.dataset.counterId));
            }
            // Blokkeer: Bricks start interval ALLEEN als (null == counterId)
            el.dataset.counterId = "aisb-locked";
            // Herstel onze waarde na IntersectionObserver-callback (async)
            [50, 200, 600, 1500].forEach(function (delay) {
              _iframeWin.setTimeout(function () {
                el.innerText = _lockedText;
                if (
                  el.dataset.counterId &&
                  el.dataset.counterId !== "aisb-locked"
                ) {
                  _iframeWin.clearInterval(Number(el.dataset.counterId));
                }
                el.dataset.counterId = "aisb-locked";
              }, delay);
            });
          } catch (_e) {}
        }
      } else if (op.type === "css") {
        const isSectionCascade =
          op.cascade === "section" &&
          (op.prop === "background-color" ||
            op.prop === "background-image" ||
            op.prop === "background") &&
          op.value;
        // Direct setProperty overslaan bij sectie-cascade: anders krijgt
        // het outer-root element wel een bg maar GEEN data-aisb-sec-bg
        // marker, waardoor de cascade-walk hem als "user-bg" beschouwt en
        // bij de volgende picker-input overslaat (alleen inner divs
        // veranderen dan nog van kleur — de bug die we hier fixen).
        if (!isSectionCascade && op.prop && op.value !== undefined) {
          el.style.setProperty(op.prop, op.value, "important");
        }
        // Sectie-cascade: zet de background alleen op het geregistreerde
        // section-anker. Child containers/blocks krijgen hun eigen Figma
        // background via aparte patches wanneer ze er zelf een hebben.
        if (isSectionCascade) {
          const target =
            el ||
            doc.body.querySelector(".brxe-section") ||
            doc.body.querySelector("section") ||
            doc.body.firstElementChild;
          if (target) {
            target.style.setProperty(op.prop, op.value, "important");
            if (op.prop === "background-color")
              target.style.removeProperty("background-image");
            if (op.prop === "background-image")
              target.style.removeProperty("background-color");
            target.dataset.aisbSecBg = "1";
          }
          doc.body.style.setProperty(op.prop, op.value, "important");
          if (op.prop === "background-color")
            doc.body.style.removeProperty("background-image");
          if (op.prop === "background-image")
            doc.body.style.removeProperty("background-color");
          doc.body.dataset.aisbSecBg = "1";
        }
      } else if (op.type === "img") {
        if (op.src) {
          el.src = op.src;
          el.srcset = "";
          el.style.objectFit = "cover";
        }
      }
    });

    // Schrijf de gecombineerde patch terug naar iframe._aisbPatch zodat
    // een volgende saveAllEdits() ze opnieuw doorstuurt. Zo gaan patches
    // niet verloren als de server-meta op de een of andere manier gereset
    // is (bv. template-wijziging, cache-flush, tweede refresh).
    if (patch.length) {
      iframe._aisbPatch = patch.slice();
    }
  };

  /**
   * Stuur alle onopgeslagen iframe-patches naar de server.
   * Toont feedback op de opslaan-knop.
   */
  D.saveAllEdits = async function () {
    const btn = document.getElementById("aisb-design-save-btn");

    // Verzamel iframes die wijzigingen hebben
    const toSave = (D.allIframes || [])
      .filter(function (iframe) {
        return iframe._aisbPatch && iframe._aisbPatch.length;
      })
      .map(function (iframe) {
        return {
          post_id: iframe._sectionPostId,
          patch: iframe._aisbPatch,
        };
      });

    // Verzamel pending reorders (per pagina)
    const reorders = D._pendingReorders
      ? Object.keys(D._pendingReorders).map(function (k) {
          return D._pendingReorders[k];
        })
      : [];

    if (!toSave.length && !reorders.length) {
      if (btn) {
        btn.textContent = "\u2713 Niets gewijzigd";
        btn.classList.add("is-saved");
        setTimeout(function () {
          btn.textContent = "\uD83D\uDCBE Opslaan";
          btn.classList.remove("is-saved");
        }, 2000);
      }
      return Promise.resolve();
    }

    if (btn) {
      btn.disabled = true;
      btn.textContent = "\u23F3 Opslaan\u2026";
    }

    try {
      // 1) Patches opslaan (indien aanwezig)
      if (toSave.length) {
        const result = await D.post("aisb_design_save_patch", {
          project_id: D.projectId,
          patches: JSON.stringify(toSave),
        });
        if (!result || !result.success) throw new Error("Patch save failed");
        toSave.forEach(function (item) {
          D._savedPatches[String(item.post_id)] = item.patch;
        });
        // Markeer iframes als opgeslagen zodat hasUnsavedChanges() na opslaan
        // niet meer true teruggeeft.
        (D.allIframes || []).forEach(function (iframe) {
          if (iframe._aisbPatch && iframe._aisbPatch.length) {
            iframe._aisbPatch = [];
          }
        });
      }

      // 2) Reorders opslaan (één POST per pagina)
      for (const r of reorders) {
        const out = await D.post("aisb_design_reorder_sections", {
          project_id: D.projectId,
          sitemap_version_id: r.sitemap_version_id,
          page_slug: r.page_slug,
          uuids: JSON.stringify(r.uuids),
          bg_indices: JSON.stringify(r.bg_indices || {}),
        });
        if (!out || !out.success) throw new Error("Reorder save failed");

        // Synchroniseer page.sections met opgeslagen volgorde
        if (r.page && Array.isArray(r.page.sections)) {
          const byUuid = {};
          r.page.sections.forEach(function (s) {
            if (s && s.uuid) byUuid[s.uuid] = s;
          });
          const next = [];
          r.uuids.forEach(function (u) {
            if (byUuid[u]) next.push(byUuid[u]);
          });
          r.page.sections.forEach(function (s) {
            if (s && s.uuid && r.uuids.indexOf(s.uuid) === -1) next.push(s);
          });
          r.page.sections = next;
        }
      }
      D._pendingReorders = {};

      if (btn) {
        btn.disabled = false;
        btn.textContent = "\u2713 Opgeslagen";
        btn.classList.add("is-saved");
        btn.classList.remove("is-dirty");
        setTimeout(function () {
          btn.textContent = "\uD83D\uDCBE Opslaan";
          btn.classList.remove("is-saved");
        }, 2500);
      }
    } catch (e) {
      console.error("[AISB] save error:", e);
      if (btn) {
        btn.disabled = false;
        btn.textContent = "\u26A0 Fout bij opslaan";
        setTimeout(function () {
          btn.textContent = "\uD83D\uDCBE Opslaan";
        }, 2500);
      }
      throw e; // propageer zodat de modal-handler het kan opvangen
    }
  };

  /**
   * Geeft true als er ongeslagen wijzigingen zijn (patches of herordeningen).
   * Vergelijkt iframe._aisbPatch met D._savedPatches zodat patches die al
   * opgeslagen zijn niet als "dirty" worden gezien na een iframe-herlaad.
   */
  D.hasUnsavedChanges = function () {
    const hasPatches = (D.allIframes || []).some(function (iframe) {
      if (!iframe._aisbPatch || !iframe._aisbPatch.length) return false;
      const postId = String(iframe._sectionPostId || "");
      const saved = D._savedPatches[postId] || [];
      // Beschouw als dirty als de geserialiseerde patch verschilt van opgeslagen
      return JSON.stringify(iframe._aisbPatch) !== JSON.stringify(saved);
    });
    const hasReorders =
      D._pendingReorders && Object.keys(D._pendingReorders).length > 0;
    return hasPatches || hasReorders;
  };

  window.AISB_Design = D;
})();
