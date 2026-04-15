/**
 * Infinite canvas: pan, zoom, loader overlay, fitToView.
 */
(function (app) {
  if (!app) return;
  const wb = app.el.whiteboard;
  if (!wb) return;

  // Create canvas inner layer
  const inner = document.createElement("div");
  inner.className = "aisb-wf-canvas-inner";
  wb.appendChild(inner);
  app.el.canvasInner = inner;

  // Loader overlay
  const loader = document.createElement("div");
  loader.className = "aisb-wf-loader";
  loader.hidden = true;
  loader.innerHTML =
    '<div class="aisb-wf-loader-spinner"></div><div class="aisb-wf-loader-text">Generating wireframes…</div>';
  wb.appendChild(loader);
  app.el.loader = loader;

  app.showCanvasLoader = function (msg) {
    if (msg) loader.querySelector(".aisb-wf-loader-text").textContent = msg;
    loader.hidden = false;
    inner.style.display = "none";
  };

  app.hideCanvasLoader = function () {
    loader.hidden = true;
    inner.style.display = "";
  };

  function applyCanvasTransform() {
    inner.style.transform = `translate(${app.canvas.tx}px, ${app.canvas.ty}px) scale(${app.canvas.scale})`;
  }
  app.applyCanvasTransform = applyCanvasTransform;

  function clamp(v, lo, hi) {
    return Math.max(lo, Math.min(hi, v));
  }

  // Pan: mousedown on empty canvas area
  wb.addEventListener("mousedown", (e) => {
    if (e.target.closest(".aisb-wf-page-card")) return;
    app.isPanning = true;
    app.panStart = {
      x: e.clientX,
      y: e.clientY,
      tx: app.canvas.tx,
      ty: app.canvas.ty,
    };
    wb.style.cursor = "grabbing";
    e.preventDefault();
  });

  window.addEventListener("mousemove", (e) => {
    if (!app.isPanning) return;
    app.canvas.tx = app.panStart.tx + (e.clientX - app.panStart.x);
    app.canvas.ty = app.panStart.ty + (e.clientY - app.panStart.y);
    applyCanvasTransform();
  });

  window.addEventListener("mouseup", () => {
    if (!app.isPanning) return;
    app.isPanning = false;
    wb.style.cursor = "";
  });

  // Zoom: Ctrl+wheel or pinch; normal scroll pans (with edge detection)
  wb.addEventListener(
    "wheel",
    (e) => {
      if (e.ctrlKey) {
        e.preventDefault();
        const rect = wb.getBoundingClientRect();
        const cx = e.clientX - rect.left;
        const cy = e.clientY - rect.top;
        const prev = app.canvas.scale;
        const delta = -e.deltaY * 0.003;
        const next = clamp(prev + delta, 0.25, 2);
        if (Math.abs(next - prev) < 0.0001) return;
        const wx = (cx - app.canvas.tx) / prev;
        const wy = (cy - app.canvas.ty) / prev;
        app.canvas.scale = next;
        app.canvas.tx = cx - wx * next;
        app.canvas.ty = cy - wy * next;
        applyCanvasTransform();
      } else {
        const cards = inner.querySelectorAll(".aisb-wf-page-card");
        if (!cards.length) return;
        const vp = wb.getBoundingClientRect();
        let minY = Infinity,
          maxY = -Infinity;
        for (const c of cards) {
          minY = Math.min(minY, c.offsetTop);
          maxY = Math.max(maxY, c.offsetTop + c.offsetHeight);
        }
        const contentTop = app.canvas.ty + minY * app.canvas.scale;
        const contentBot = app.canvas.ty + maxY * app.canvas.scale;
        if (e.deltaY > 0 && contentBot <= vp.height + 2) return;
        if (e.deltaY < 0 && contentTop >= -2) return;

        e.preventDefault();
        app.canvas.tx -= e.deltaX;
        app.canvas.ty -= e.deltaY;
        applyCanvasTransform();
      }
    },
    { passive: false },
  );

  app.fitToView = function () {
    const rect = wb.getBoundingClientRect();
    const cards = inner.querySelectorAll(".aisb-wf-page-card");
    if (!cards.length) return;

    let minX = Infinity,
      minY = Infinity,
      maxX = -Infinity,
      maxY = -Infinity;
    for (const c of cards) {
      const l = c.offsetLeft;
      const t = c.offsetTop;
      minX = Math.min(minX, l);
      minY = Math.min(minY, t);
      maxX = Math.max(maxX, l + c.offsetWidth);
      maxY = Math.max(maxY, t + c.offsetHeight);
    }

    const contentW = maxX - minX;
    const contentH = maxY - minY;
    const pad = 40;
    const scaleX = (rect.width - pad * 2) / contentW;
    const scaleY = (rect.height - pad * 2) / contentH;
    app.canvas.scale = clamp(Math.min(scaleX, scaleY), 0.25, 1);
    app.canvas.tx =
      (rect.width - contentW * app.canvas.scale) / 2 - minX * app.canvas.scale;
    app.canvas.ty = pad;
    applyCanvasTransform();
  };
})(window.AISB_WF_App);
