// helpers.js — Gedeelde hulpfuncties voor de Style Guide wizard
// Dit bestand bevat algemene utility-functies die door alle andere scripts
// worden gebruikt: statusberichten, HTML-escaping, querystring-bouw en AJAX.
//
// Laadvolgorde: core.js → helpers.js → colours.js / typography.js / images.js → init.js
// Alle functies worden op het gedeelde SG-object (window.AISB_StyleGuide) geplaatst.

(function () {
  "use strict";

  const SG = window.AISB_StyleGuide;
  if (!SG) return;

  /* ─── Hulpfuncties ──────────────────────────────────────────── */

  // toont een statusbericht in de statusbalk onderaan de wizard
  // parameters:
  //   message — de tekst die getoond wordt (leeg = statusbalk leegmaken)
  //   kind    — "err" voor een rode foutmelding, anders een groene succesmelding
  // de tekst wordt via escapeHtml beveiligd tegen XSS
  SG.setStatus = function (message, kind) {
    const statusElement = SG.el.status;
    if (!statusElement) return;
    statusElement.innerHTML = message
      ? '<span class="' +
        (kind === "err" ? "aisb-error" : "aisb-ok") +
        '">' +
        SG.escapeHtml(message) +
        "</span>"
      : "";
  };

  // beveiligt een string tegen XSS door HTML-speciale tekens te vervangen
  // door hun veilige HTML-entities (&amp; &lt; &gt; &quot; &#039;)
  // wordt overal gebruikt waar gebruikersinvoer in HTML wordt geplaatst
  SG.escapeHtml = function (text) {
    return String(text || "").replace(
      /[&<>"']/g,
      (char) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[char],
    );
  };

  // zet een JavaScript-object om naar een URL-gecodeerde querystring
  // voorbeeld: { action: "save", id: 5 } → "action=save&id=5"
  // wordt gebruikt door SG.post() om AJAX-data te versturen
  SG.toQueryString = function (params) {
    return Object.keys(params)
      .map(
        (key) =>
          encodeURIComponent(key) + "=" + encodeURIComponent(params[key]),
      )
      .join("&");
  };

  // stuurt een POST-verzoek naar de WordPress AJAX-handler (admin-ajax.php)
  // parameters:
  //   action — de WordPress AJAX-actie (bijv. "aisb_save_styleguide")
  //   data   — optioneel object met extra velden die meegestuurd worden
  // voegt automatisch de nonce (beveiligingstoken) toe aan elk verzoek
  // geeft een Promise terug die resolvet naar het JSON-antwoord van de server
  SG.post = async function (action, data) {
    const response = await fetch(AISB_SG.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: SG.toQueryString(
        Object.assign({ action, nonce: AISB_SG.nonce }, data || {}),
      ),
    });
    return response.json();
  };
})();
