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

      const imgs = doc.querySelectorAll("img");
      if (!imgs.length) return;

      // Sla logo-afbeeldingen over zodat injectImages() het logo niet
      // overschrijft met een willekeurige stockfoto.
      const nonLogoImgs = Array.from(imgs).filter((img) => {
        if (img.getAttribute("data-aisb-logo") === "1") return false;
        if (img.classList.contains("bricks-site-logo")) return false;
        if (img.closest(".brxe-logo")) return false;
        return true;
      });
      if (!nonLogoImgs.length) return;

      // Gebruik _localSectionIdx (per pagina) als sleutel in de kaart
      const localIdx =
        typeof iframe._localSectionIdx === "number"
          ? iframe._localSectionIdx
          : iframe._sectionIdx;
      let urls = D.buildImageMap()[iframe._pageSlug + ":" + localIdx];

      // Fallback: als media_count = 0 maar er zijn wél <img> tags, gebruik
      // de guide-afbeeldingen cyclisch verdeeld op basis van de globale sectie-index.
      if (!urls || !urls.length) {
        const globalIdx =
          typeof iframe._sectionIdx === "number" ? iframe._sectionIdx : 0;
        const allImgs = guide.images;
        const start = globalIdx % allImgs.length;
        const needed = nonLogoImgs.length;
        urls = [];
        for (let i = 0; i < needed; i++) {
          const img = allImgs[(start + i) % allImgs.length];
          urls.push(img.full || img.thumb || "");
        }
      }

      // Als er meer <img> slots zijn dan toegewezen URLs (bijv. een gallerij),
      // vul de extra slots met unieke afbeeldingen uit de volledige pool.
      const allImgs = guide.images;
      if (nonLogoImgs.length > urls.length && allImgs.length > 1) {
        // bereken de globale pool-offset: hoeveel images gingen voor deze sectie?
        let poolOffset = 0;
        outer: for (let pi = 0; pi < D.wireframePages.length; pi++) {
          const pg = D.wireframePages[pi];
          const secs = pg.sections || [];
          for (let si = 0; si < secs.length; si++) {
            if (pg.slug === iframe._pageSlug && si === localIdx) break outer;
            poolOffset += secs[si].media_count || 0;
          }
        }
        while (urls.length < nonLogoImgs.length) {
          const extra = allImgs[(poolOffset + urls.length) % allImgs.length];
          urls.push(extra.full || extra.thumb || "");
        }
      }
      nonLogoImgs.forEach((img, ui) => {
        img.src = urls[ui] || urls[ui % urls.length];
        img.srcset = "";
        img.style.objectFit = "cover";
      });
    } catch (e) {
      /* cross-origin */
    }
  };
})();
