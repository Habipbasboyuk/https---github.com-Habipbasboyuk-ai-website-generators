/**
 * Canvas: pan/zoom, layout algorithm, edge drawing, position helpers, fitToView.
 * Depends on: app-init.js, app-utils.js
 */
(function () {
  "use strict";

  const app = window.AISBApp;
  if (!app) return;

  const { canvasEl, viewportEl, edgesSvg, nodesEl, view, state } = app;

  /* ── Transform helpers ──────────────────────────────────── */

  const clamp = (v, a, b) => Math.max(a, Math.min(b, v));

  const drawEdges = () => {
    if (!state.edges || !state.edges.length) {
      edgesSvg.innerHTML = "";
      return;
    }
    const w = canvasEl.clientWidth;
    const h = canvasEl.clientHeight;
    edgesSvg.setAttribute("width", w);
    edgesSvg.setAttribute("height", h);
    edgesSvg.setAttribute("viewBox", `0 0 ${w} ${h}`);

    const parts = [];
    for (const e of state.edges) {
      const a = getNodeAnchor(e.from, "bottom");
      const b = getNodeAnchor(e.to, "top");
      if (!a || !b) continue;
      const d = orthoPath(a, b);
      parts.push(
        `<path d="${d}" fill="none" stroke="rgba(0,0,0,.25)" stroke-width="2" />`,
      );
      parts.push(
        `<circle cx="${a.x}" cy="${a.y}" r="3" fill="rgba(0,0,0,.35)" />`,
      );
      parts.push(
        `<circle cx="${b.x}" cy="${b.y}" r="3" fill="rgba(0,0,0,.35)" />`,
      );
    }
    edgesSvg.innerHTML = parts.join("");
  };

  const applyTransform = () => {
    viewportEl.style.transform = `translate(${view.tx}px, ${view.ty}px) scale(${view.scale})`;
    requestAnimationFrame(drawEdges);
  };

  const zoomTo = (newScale, clientX, clientY) => {
    const rect = canvasEl.getBoundingClientRect();
    const cx = clientX - rect.left;
    const cy = clientY - rect.top;

    const prev = view.scale;
    const next = clamp(newScale, 0.35, 2.2);
    if (Math.abs(next - prev) < 0.0001) return;

    const wx = (cx - view.tx) / prev;
    const wy = (cy - view.ty) / prev;

    view.scale = next;
    view.tx = cx - wx * next;
    view.ty = cy - wy * next;
    applyTransform();
  };

  /* ── Pan events ─────────────────────────────────────────── */

  canvasEl.addEventListener("mousedown", (e) => {
    const onNode = e.target.closest(".aisb-node-card");
    if (onNode) return;
    app.isPanning = true;
    app.panStart = { x: e.clientX, y: e.clientY, tx: view.tx, ty: view.ty };
    canvasEl.style.cursor = "grabbing";
  });

  window.addEventListener("mousemove", (e) => {
    if (!app.isPanning) return;
    const dx = e.clientX - app.panStart.x;
    const dy = e.clientY - app.panStart.y;
    view.tx = app.panStart.tx + dx;
    view.ty = app.panStart.ty + dy;
    applyTransform();
  });

  window.addEventListener("mouseup", () => {
    if (!app.isPanning) return;
    app.isPanning = false;
    canvasEl.style.cursor = "";
  });

  canvasEl.addEventListener(
    "wheel",
    (e) => {
      if (e.ctrlKey) {
        e.preventDefault();
        const delta = -e.deltaY;
        const factor = delta > 0 ? 1.08 : 0.92;
        zoomTo(view.scale * factor, e.clientX, e.clientY);
        return;
      }
      e.preventDefault();
      view.tx -= e.deltaX;
      view.ty -= e.deltaY;
      applyTransform();
    },
    { passive: false },
  );

  /* ── Zoom buttons ───────────────────────────────────────── */

  app.btnZoomIn?.addEventListener("click", () =>
    zoomTo(
      view.scale * 1.12,
      canvasEl.getBoundingClientRect().left + canvasEl.clientWidth / 2,
      canvasEl.getBoundingClientRect().top + canvasEl.clientHeight / 2,
    ),
  );
  app.btnZoomOut?.addEventListener("click", () =>
    zoomTo(
      view.scale * 0.88,
      canvasEl.getBoundingClientRect().left + canvasEl.clientWidth / 2,
      canvasEl.getBoundingClientRect().top + canvasEl.clientHeight / 2,
    ),
  );

  /* ── Node position helpers ──────────────────────────────── */

  const getNodePos = (slug) => {
    const p = state.bySlug[slug];
    return { x: p?._x ?? 0, y: p?._y ?? 0 };
  };

  const setNodePos = (slug, x, y, markUserMoved = false) => {
    const p = state.bySlug[slug];
    if (!p) return;
    p._x = x;
    p._y = y;
    if (markUserMoved) p._userMoved = true;

    const idx = state.pages.findIndex(
      (pp) => app.normalizeSlug(pp.slug) === slug,
    );
    if (idx >= 0) {
      state.pages[idx]._x = x;
      state.pages[idx]._y = y;
      if (markUserMoved) state.pages[idx]._userMoved = true;
    }
    if (state.data?.sitemap && Array.isArray(state.data.sitemap)) {
      const j = state.data.sitemap.findIndex(
        (pp) => app.normalizeSlug(pp.slug) === slug,
      );
      if (j >= 0) {
        state.data.sitemap[j]._x = x;
        state.data.sitemap[j]._y = y;
        if (markUserMoved) state.data.sitemap[j]._userMoved = true;
      }
    }
  };

  const positionNodeEl = (el, slug) => {
    const pos = getNodePos(slug);
    el.style.left = `${pos.x}px`;
    el.style.top = `${pos.y}px`;
  };

  /* ── Edge geometry ──────────────────────────────────────── */

  const getNodeAnchor = (slug, which) => {
    const el = nodesEl.querySelector(
      `.aisb-node-card[data-slug="${CSS.escape(slug)}"]`,
    );
    if (!el) return null;
    const x = el.offsetLeft || 0;
    const y = el.offsetTop || 0;
    const w = el.offsetWidth || 0;
    const h = el.offsetHeight || 0;
    if (which === "top") return { x: x + w / 2, y: y };
    return { x: x + w / 2, y: y + h };
  };

  const orthoPath = (a, b) => {
    const midY = a.y + Math.max(24, (b.y - a.y) * 0.45);
    const mY = b.y > a.y ? midY : a.y + 30;
    const p1 = { x: a.x, y: a.y };
    const p2 = { x: a.x, y: mY };
    const p3 = { x: b.x, y: mY };
    const p4 = { x: b.x, y: b.y };
    return `M ${p1.x} ${p1.y} L ${p2.x} ${p2.y} L ${p3.x} ${p3.y} L ${p4.x} ${p4.y}`;
  };

  const getEdgeList = () => {
    const edges = [];
    Object.values(state.bySlug).forEach((p) => {
      if (!p.parent_slug) return;
      const parent = app.normalizeSlug(p.parent_slug);
      if (!parent) return;
      edges.push({ from: parent, to: p.slug });
    });
    return edges;
  };

  /* ── Layout algorithm ───────────────────────────────────── */

  const layoutTreeTidy = async () => {
    const roots = state.tree || [];
    if (!roots.length) return;

    const CARD_W = 260;
    const GAP_X = 70;
    const STEP_X = CARD_W + GAP_X;
    const START_X = 80;
    const START_Y = 30;
    const GAP_Y = 90;

    const subtreeUnits = new Map();
    const computeUnits = (node) => {
      const kids = node.children || [];
      if (!kids.length) {
        subtreeUnits.set(node.slug, 1);
        return 1;
      }
      let sum = 0;
      kids.forEach((k) => {
        sum += computeUnits(k);
      });
      subtreeUnits.set(node.slug, Math.max(1, sum));
      return Math.max(1, sum);
    };

    const desiredX = new Map();
    const assignX = (node, unitStart) => {
      const units = subtreeUnits.get(node.slug) || 1;
      const centerUnit = unitStart + units / 2;
      desiredX.set(node.slug, START_X + centerUnit * STEP_X);

      let cursor = unitStart;
      (node.children || []).forEach((child) => {
        const cu = subtreeUnits.get(child.slug) || 1;
        assignX(child, cursor);
        cursor += cu;
      });
    };

    let rootCursor = 0;
    roots.forEach((r) => {
      rootCursor += computeUnits(r);
    });
    rootCursor = 0;
    roots.forEach((r) => {
      const u = subtreeUnits.get(r.slug) || 1;
      assignX(r, rootCursor);
      rootCursor += u;
    });

    Object.values(state.bySlug).forEach((p) => {
      if (p._userMoved) return;
      const x = desiredX.get(p.slug);
      if (typeof x === "number") p._x = x;
    });

    app.renderCanvas({ skipLayout: true });
    await new Promise((r) => requestAnimationFrame(r));

    const depthMap = new Map();
    const walkDepth = (n, d) => {
      if (!depthMap.has(d)) depthMap.set(d, []);
      depthMap.get(d).push(n.slug);
      (n.children || []).forEach((c) => walkDepth(c, d + 1));
    };
    roots.forEach((r) => walkDepth(r, 0));

    const depths = Array.from(depthMap.keys()).sort((a, b) => a - b);

    const rowY = new Map();
    rowY.set(0, START_Y);

    for (let i = 1; i < depths.length; i++) {
      const prevD = depths[i - 1];
      const prevY = rowY.get(prevD) ?? START_Y;
      let maxBottom = prevY;

      (depthMap.get(prevD) || []).forEach((slug) => {
        const el = nodesEl.querySelector(
          `.aisb-node-card[data-slug="${CSS.escape(slug)}"]`,
        );
        if (!el) return;
        const p = state.bySlug[slug];
        const y = p?._y ?? prevY;
        const bottom = y + el.offsetHeight;
        if (bottom > maxBottom) maxBottom = bottom;
      });

      rowY.set(depths[i], maxBottom + GAP_Y);
    }

    depths.forEach((d) => {
      const y = rowY.get(d) ?? START_Y;
      (depthMap.get(d) || []).forEach((slug) => {
        const p = state.bySlug[slug];
        if (!p || p._userMoved) return;
        p._y = y;
      });
    });

    app.renderCanvas({ skipLayout: true });
    requestAnimationFrame(drawEdges);
  };

  /* ── Fit to view ────────────────────────────────────────── */

  const fitToView = () => {
    const cards = Array.from(nodesEl.querySelectorAll(".aisb-node-card"));
    if (!cards.length) return;

    let minX = Infinity,
      minY = Infinity,
      maxX = -Infinity,
      maxY = -Infinity;
    cards.forEach((el) => {
      minX = Math.min(minX, el.offsetLeft);
      minY = Math.min(minY, el.offsetTop);
      maxX = Math.max(maxX, el.offsetLeft + el.offsetWidth);
      maxY = Math.max(maxY, el.offsetTop + el.offsetHeight);
    });

    const padding = 50;
    const worldW = maxX - minX + padding * 2;
    const worldH = maxY - minY + padding * 2;

    const cw = canvasEl.clientWidth;
    const ch = canvasEl.clientHeight;

    const scale = clamp(Math.min(cw / worldW, ch / worldH), 0.35, 1.4);
    view.scale = scale;

    const centerWorldX = (minX + maxX) / 2;
    const centerWorldY = (minY + maxY) / 2;
    view.tx = cw / 2 - centerWorldX * scale;
    view.ty = ch / 2 - centerWorldY * scale;

    applyTransform();
  };

  app.btnFit?.addEventListener("click", fitToView);

  /* ── Expose on namespace ────────────────────────────────── */

  Object.assign(app, {
    clamp,
    applyTransform,
    zoomTo,
    drawEdges,
    getNodePos,
    setNodePos,
    positionNodeEl,
    getNodeAnchor,
    orthoPath,
    getEdgeList,
    layoutTreeTidy,
    fitToView,
  });
})();
