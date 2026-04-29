/**
 * design/core.js — Gedeelde state en helperfuncties voor de Design preview.
 *
 * Wordt als eerste geladen in de keten:
 *   core.js → overrides.js → images.js → canvas.js → init.js
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
    return r.json();
  };

  D.clamp = function (v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
  };

  /**
   * Bouw een CSS-selector van `el` naar de iframe-body.
   * Gebruikt :nth-child zodat de weg stabiel is zolang het Bricks-template
   * dezelfde structuur rendert.
   */
  D._cssPath = function (el, root) {
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
  D._trackPatch = function (iframe, type, el, data) {
    if (!iframe) return;
    if (!iframe._aisbPatch) iframe._aisbPatch = [];
    const op = Object.assign({ type: type }, data || {});
    if (el) {
      try {
        const bodyEl = iframe.contentDocument && iframe.contentDocument.body;
        op.selector = bodyEl ? D._cssPath(el, bodyEl) : "";
      } catch (e) {
        op.selector = "";
      }
    }
    // Dedupliceer op type + selector
    const key = type + "|" + (op.selector || "");
    const idx = iframe._aisbPatch.findIndex(function (p) {
      return p.type + "|" + (p.selector || "") === key;
    });
    if (idx >= 0) iframe._aisbPatch[idx] = op;
    else iframe._aisbPatch.push(op);
  };

  /**
   * Pas eerder opgeslagen patches toe op een iframe dat net geladen is.
   * Wordt aangeroepen na injectOverride + injectImages.
   */
  D.applyPatch = function (iframe) {
    const postId = String(iframe._sectionPostId || "");
    const patch = D._savedPatches[postId];
    if (!patch || !patch.length) return;
    const doc = iframe.contentDocument;
    if (!doc || !doc.body) return;

    patch.forEach(function (op) {
      if (op.type === "mirror") {
        if (op.mirrored) {
          const STYLE_ID = "aisb-section-mirror";
          let style = doc.getElementById(STYLE_ID);
          if (!style) {
            style = doc.createElement("style");
            style.id = STYLE_ID;
            doc.head.appendChild(style);
          }
          style.textContent =
            ".brxe-section," +
            ".brxe-section > .brxe-container," +
            ".brxe-container," +
            ".brxe-block," +
            ".brxe-div { flex-direction: row-reverse !important; }";
          iframe._aisbMirrored = true;
        }
        return;
      }
      if (!op.selector) return;
      const el = doc.body.querySelector(op.selector);
      if (!el) return;
      if (op.type === "text") {
        el.innerText = op.text || "";
      } else if (op.type === "css") {
        if (op.prop && op.value !== undefined) {
          el.style.setProperty(op.prop, op.value, "important");
        }
      } else if (op.type === "img") {
        if (op.src) {
          el.src = op.src;
          el.srcset = "";
          el.style.objectFit = "cover";
        }
      }
    });
  };

  /**
   * Stuur alle onopgeslagen iframe-patches naar de server.
   * Toont feedback op de opslaan-knop.
   */
  D.saveAllPatches = async function () {
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

    if (!toSave.length) {
      if (btn) {
        btn.textContent = "\u2713 Niets gewijzigd";
        btn.classList.add("is-saved");
        setTimeout(function () {
          btn.textContent = "\uD83D\uDCBE Opslaan";
          btn.classList.remove("is-saved");
        }, 2000);
      }
      return;
    }

    if (btn) {
      btn.disabled = true;
      btn.textContent = "\u23F3 Opslaan\u2026";
    }

    try {
      const result = await D.post("aisb_design_save_patch", {
        project_id: D.projectId,
        patches: JSON.stringify(toSave),
      });

      if (result && result.success) {
        // Update lokale cache
        toSave.forEach(function (item) {
          D._savedPatches[String(item.post_id)] = item.patch;
        });
        if (btn) {
          btn.disabled = false;
          btn.textContent = "\u2713 Opgeslagen";
          btn.classList.add("is-saved");
          setTimeout(function () {
            btn.textContent = "\uD83D\uDCBE Opslaan";
            btn.classList.remove("is-saved");
          }, 2500);
        }
      } else {
        throw new Error("Server error");
      }
    } catch (e) {
      if (btn) {
        btn.disabled = false;
        btn.textContent = "\u26A0 Fout bij opslaan";
        setTimeout(function () {
          btn.textContent = "\uD83D\uDCBE Opslaan";
        }, 2500);
      }
    }
  };

  window.AISB_Design = D;
})();
