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

  window.AISB_Design = D;
})();
