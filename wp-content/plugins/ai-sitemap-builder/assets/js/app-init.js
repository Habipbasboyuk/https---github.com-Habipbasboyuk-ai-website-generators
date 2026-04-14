/**
 * App initialization: namespace, DOM refs, state, view, flags.
 * Must load first — all other app-*.js files depend on window.AISBApp.
 */
(function () {
  "use strict";

  const root = document.querySelector("[data-aisb]");
  if (!root || !window.AISB) return;

  window.AISBApp = {
    root: root,

    // DOM refs
    promptEl: root.querySelector("#aisb-prompt"),
    languagesEl: root.querySelector("#aisb-languages"),
    pageCountEl: root.querySelector("#aisb-pagecount"),
    btnGen: root.querySelector("[data-aisb-generate]"),
    statusEl: root.querySelector("[data-aisb-status]"),
    outWrap: root.querySelector("[data-aisb-output]"),
    rawEl: root.querySelector("[data-aisb-raw]"),
    summaryEl: root.querySelector("[data-aisb-summary]"),
    counterEl: root.querySelector("[data-aisb-counter]"),
    demoNoteEl: root.querySelector("[data-aisb-demo-note]"),
    btnCopy: root.querySelector("[data-aisb-copy]"),
    btnSave: root.querySelector("[data-aisb-save]"),
    btnApprove: root.querySelector("[data-aisb-approve]"),
    btnReset: root.querySelector("[data-aisb-reset]"),
    btnGoWireframes: root.querySelector("[data-aisb-go-wireframes]"),
    step2TabEl: document.querySelector("[data-aisb-step2-tab]"),
    btnFit: root.querySelector("[data-aisb-fit]"),
    btnZoomIn: root.querySelector("[data-aisb-zoomin]"),
    btnZoomOut: root.querySelector("[data-aisb-zoomout]"),
    btnAddPageTop: root.querySelector("[data-aisb-add-page]"),
    canvasEl: root.querySelector("[data-aisb-canvas]"),
    viewportEl: root.querySelector("[data-aisb-viewport]"),
    edgesSvg: root.querySelector("[data-aisb-edges]"),
    nodesEl: root.querySelector("[data-aisb-nodes]"),
    detailTitleEl: root.querySelector("[data-aisb-detail-title]"),
    detailSubEl: root.querySelector("[data-aisb-detail-sub]"),
    detailBodyEl: root.querySelector("[data-aisb-detail-body]"),

    // Constants
    SECTION_TYPES: Array.isArray(AISB.sectionTypes) ? AISB.sectionTypes : [],

    // State (shared across all modules)
    state: {
      projectId: null,
      sitemapId: null,
      version: 0,
      savingVersion: false,
      structureOnly: false,
      baselineData: null,
      data: null,
      pages: [],
      bySlug: {},
      tree: [],
      activeSlug: null,
      edges: [],
      openInlineFormFor: null,
    },

    // View / pan-zoom state
    view: { tx: 0, ty: 0, scale: 1 },

    // Mutable flags
    isPanning: false,
    panStart: { x: 0, y: 0, tx: 0, ty: 0 },
    secDrag: { active: false, fromIdx: null },
    miniDrag: { active: false, slug: null, fromIdx: null },
    miniJustDragged: false,
    editDebounce: null,
  };

  if (AISB.demoMode && window.AISBApp.demoNoteEl) {
    window.AISBApp.demoNoteEl.style.display = "inline-flex";
  }
})();
