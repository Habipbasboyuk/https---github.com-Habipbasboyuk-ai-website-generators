(function () {
  const root = document.querySelector("[data-aisb-style-guide]");
  if (!root) return;

  const projectId =
    parseInt(root.getAttribute("data-project-id") || "0", 10) || 0;

  const elStatus = root.querySelector("[data-aisb-sg-status]");
  const elSwatches = root.querySelector("[data-aisb-sg-swatches]");
  const elType = root.querySelector("[data-aisb-sg-type]");
  const elComponents = root.querySelector("[data-aisb-sg-components]");
  const btnGenerate = root.querySelector("[data-aisb-sg-generate]");
  const btnSave = root.querySelector("[data-aisb-sg-save]");

  let guide = {};

  function setStatus(msg, kind) {
    elStatus.innerHTML = msg
      ? `<span class="${kind === "err" ? "aisb-error" : "aisb-ok"}">${escHtml(msg)}</span>`
      : "";
  }

  function escHtml(str) {
    return String(str || "").replace(
      /[&<>"']/g,
      (s) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        })[s],
    );
  }

  function qs(obj) {
    return Object.keys(obj)
      .map((k) => encodeURIComponent(k) + "=" + encodeURIComponent(obj[k]))
      .join("&");
  }

  async function post(action, data) {
    const res = await fetch(AISB_SG.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: qs(Object.assign({ action, nonce: AISB_SG.nonce }, data || {})),
    });
    return res.json();
  }

  /* ─── Render helpers ───────────────────────────────────────── */

  function renderSwatches(colours) {
    if (!Array.isArray(colours) || !colours.length) {
      elSwatches.innerHTML =
        '<div class="aisb-sg-empty-state">No colours defined yet.</div>';
      return;
    }
    elSwatches.innerHTML = colours
      .map(
        (c) => `
      <div class="aisb-sg-swatch">
        <div class="aisb-sg-swatch-block" style="background:${escHtml(c.hex || "#ccc")};"></div>
        <div class="aisb-sg-swatch-label">${escHtml(c.name || c.hex || "")}</div>
      </div>
    `,
      )
      .join("");
  }

  function renderTypography(typeScale) {
    if (!Array.isArray(typeScale) || !typeScale.length) {
      elType.innerHTML =
        '<div class="aisb-sg-empty-state">No typography defined yet.</div>';
      return;
    }
    elType.innerHTML = typeScale
      .map(
        (t) => `
      <div class="aisb-sg-type-row">
        <div class="aisb-sg-type-meta">${escHtml(t.label || "")}</div>
        <div class="aisb-sg-type-sample ${escHtml(t.cls || "body")}"
             style="${t.fontFamily ? "font-family:" + escHtml(t.fontFamily) + ";" : ""}">
          ${escHtml(t.sample || t.label || "The quick brown fox")}
        </div>
      </div>
    `,
      )
      .join("");
  }

  function renderComponents(components) {
    if (!Array.isArray(components) || !components.length) {
      elComponents.innerHTML =
        '<div class="aisb-sg-empty-state">No components defined yet.</div>';
      return;
    }
    elComponents.innerHTML = components
      .map(
        (c) => `
      <div class="aisb-sg-component-card">
        <div class="aisb-sg-component-name">${escHtml(c.name || "")}</div>
        <div class="aisb-sg-component-preview">${c.preview_html || ""}</div>
      </div>
    `,
      )
      .join("");
  }

  function renderGuide() {
    renderSwatches(guide.colours || []);
    renderTypography(guide.typography || []);
    renderComponents(guide.components || []);
  }

  /* ─── Load ─────────────────────────────────────────────────── */

  async function loadGuide() {
    if (!projectId) return;
    const out = await post("aisb_get_style_guide", { project_id: projectId });
    if (out && out.success) {
      guide = out.data.style_guide || {};
      renderGuide();
    } else {
      setStatus("Could not load style guide.", "err");
    }
  }

  /* ─── Buttons ──────────────────────────────────────────────── */

  btnGenerate &&
    btnGenerate.addEventListener("click", async () => {
      setStatus("Generating style guide… (not yet implemented)", "ok");
      // TODO: call aisb_generate_style_guide AJAX action
    });

  btnSave &&
    btnSave.addEventListener("click", async () => {
      setStatus("Saving…", "ok");
      const out = await post("aisb_save_style_guide", {
        project_id: projectId,
        style_guide_json: JSON.stringify(guide),
      });
      if (out && out.success) {
        setStatus("Saved.", "ok");
      } else {
        setStatus(
          (out && out.data && out.data.message) || "Save failed.",
          "err",
        );
      }
    });

  loadGuide();
})();
