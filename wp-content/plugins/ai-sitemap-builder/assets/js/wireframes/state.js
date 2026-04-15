/**
 * Shared application namespace, state, DOM refs, templates and patterns.
 * Must be loaded first — all other wireframe modules depend on this.
 */
window.AISB_WF_App = (function () {
  const root = document.querySelector("[data-aisb-wireframes]");
  if (!root) return null;

  const q = (sel) => root.querySelector(sel);

  return {
    root,
    q,

    state: {
      projectId: parseInt(root.getAttribute("data-project-id") || "0", 10) || 0,
      sitemapId: parseInt(root.getAttribute("data-sitemap-id") || "0", 10) || 0,
      pageSlug: "",
      model: null,
      pages: [],
      pageModels: {},
    },

    el: {
      pages: q("[data-aisb-wf-pages]"),
      title: q("[data-aisb-wf-page-title]"),
      sub: q("[data-aisb-wf-page-sub]"),
      sections: q("[data-aisb-wf-sections]"),
      status: q("[data-aisb-wf-status]"),
      compiled: q("[data-aisb-wf-compiled]"),
      whiteboard: q("[data-aisb-wf-whiteboard]"),
      expanded: q("[data-aisb-wf-expanded]"),
      canvasInner: null,
      loader: null,
    },

    btn: {
      generate: q("[data-aisb-wf-generate]"),
      generateAll: q("[data-aisb-wf-generate-all]"),
      save: q("[data-aisb-wf-save]"),
      saveAll: q("[data-aisb-wf-save-all]"),
      shufflePage: q("[data-aisb-wf-shuffle-page]"),
      compile: q("[data-aisb-wf-compile]"),
      closeExpanded: q("[data-aisb-wf-close-expanded]"),
    },

    tpl: {
      pageCard: root.querySelector('[data-tpl="page-card"]'),
      sectionCard: root.querySelector('[data-tpl="section-card"]'),
      sectionLabel: root.querySelector('[data-tpl="section-label"]'),
    },

    canvas: { tx: 0, ty: 0, scale: 1 },
    isPanning: false,
    panStart: { x: 0, y: 0, tx: 0, ty: 0 },
  };
})();
