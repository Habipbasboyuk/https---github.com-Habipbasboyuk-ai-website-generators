/**
 * design/images.js — Afbeeldingen injecteren in de preview-iframes.
 *
 * Verantwoordelijk voor:
 *   - buildImageMap()  — maakt een kaart van pagina+sectie-index naar afbeelding-URLs
 *   - injectImages()   — vervangt <img> tags in één iframe met de gidsgekoppelde foto's
 */
(function () {
  "use strict";

  const D = window.AISB_Design;
  if (!D) return;

  /* ── Afbeeldingskaart bouwen ─────────────────────────────────── */

  D.buildImageMap = function () {
    const guide = D.guide;
    if (!guide.images || !guide.images.length) return {};
    const map = {};
    let imgIdx = 0;
    D.wireframePages.forEach((page) => {
      (page.sections || []).forEach((s, sIdx) => {
        const count = s.media_count || 0;
        const urls = [];
        for (let i = 0; i < count; i++) {
          if (imgIdx < guide.images.length)
            urls.push(
              guide.images[imgIdx++].full ||
                guide.images[imgIdx - 1].thumb ||
                "",
            );
        }
        // sleutel = paginaslug:sectie-index (matcht _localSectionIdx op de iframe)
        if (urls.length) map[page.slug + ":" + sIdx] = urls;
      });
    });
    return map;
  };

  /* ── Afbeeldingen in één iframe injecteren ───────────────────── */

  D.injectImages = function (iframe) {
    const guide = D.guide;
    if (!guide.images || !guide.images.length) return;
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (!doc) return;
      // Gebruik _localSectionIdx (per pagina) als sleutel in de kaart
      const localIdx =
        typeof iframe._localSectionIdx === "number"
          ? iframe._localSectionIdx
          : iframe._sectionIdx;
      const urls = D.buildImageMap()[iframe._pageSlug + ":" + localIdx];
      if (!urls || !urls.length) return;
      const imgs = doc.querySelectorAll("img");
      let ui = 0;
      imgs.forEach((img) => {
        if (ui < urls.length) {
          img.src = urls[ui];
          img.srcset = "";
          img.style.objectFit = "cover";
          ui++;
        }
      });
    } catch (e) {
      /* cross-origin */
    }
  };
})();
