(function () {
  const root = document.querySelector("[data-aisb-wireframes]");
  if (!root) return;

  const state = {
    projectId: parseInt(root.getAttribute("data-project-id") || "0", 10) || 0,
    sitemapId: parseInt(root.getAttribute("data-sitemap-id") || "0", 10) || 0,
    pageSlug: "",
    model: null,
    pages: [],
  };

  const elPages = root.querySelector("[data-aisb-wf-pages]");
  const elPagesMeta = root.querySelector("[data-aisb-wf-pages-meta]");
  const elTitle = root.querySelector("[data-aisb-wf-page-title]");
  const elSub = root.querySelector("[data-aisb-wf-page-sub]");
  const elSections = root.querySelector("[data-aisb-wf-sections]");
  const elStatus = root.querySelector("[data-aisb-wf-status]");
  const elCompiled = root.querySelector("[data-aisb-wf-compiled]");
  const elPattern = root.querySelector("[data-aisb-wf-pattern]");

  const btnGenerate = root.querySelector("[data-aisb-wf-generate]");
  const btnGenerateAll = root.querySelector("[data-aisb-wf-generate-all]");
  const btnSave = root.querySelector("[data-aisb-wf-save]");
  const btnShufflePage = root.querySelector("[data-aisb-wf-shuffle-page]");
  const btnCompile = root.querySelector("[data-aisb-wf-compile]");

  const patterns = window.AISB_WF && AISB_WF.patterns ? AISB_WF.patterns : {};
  const sectionTypes =
    window.AISB_WF && AISB_WF.sectionTypes && AISB_WF.sectionTypes.length
      ? AISB_WF.sectionTypes
      : [
          "hero",
          "features",
          "process",
          "testimonials",
          "pricing",
          "faq",
          "cta",
          "content",
          "team",
          "story",
          "values",
          "contact_form",
          "locations",
          "social_proof",
          "footer",
          "header",
          "generic",
        ];
  const patternKeys = Object.keys(patterns);
  elPattern.innerHTML = patternKeys
    .map((k) => `<option value="${k}">${k.replace(/_/g, " ")}</option>`)
    .join("");

  function setStatus(msg, kind) {
    elStatus.innerHTML = msg
      ? `<span class="${kind === "err" ? "aisb-error" : "aisb-ok"}">${escapeHtml(msg)}</span>`
      : "";
  }

  function escapeHtml(str) {
    return String(str || "").replace(
      /[&<>\"']/g,
      (s) =>
        ({
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '\"': "&quot;",
          "'": "&#039;",
        })[s],
    );
  }

  function qs(obj) {
    return Object.keys(obj)
      .map((k) => encodeURIComponent(k) + "=" + encodeURIComponent(obj[k]))
      .join("&");
  }

  async function postWithNonce(action, data, nonce) {
    const res = await fetch(AISB_WF.ajaxUrl, {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      },
      body: qs(Object.assign({ action, nonce: nonce || "" }, data || {})),
    });
    return res.json();
  }

  async function post(action, data) {
    return postWithNonce(action, data, AISB_WF.nonce);
  }

  async function postCore(action, data) {
    return postWithNonce(action, data, AISB_WF.coreNonce);
  }

  async function loadSitemapPages() {
    if (!state.projectId || !state.sitemapId) {
      elPages.innerHTML =
        '<div class="aisb-wf-muted" style="padding:8px;">Open this screen with ?aisb_project=ID&aisb_sitemap=ID</div>';
      return;
    }
    // Reuse existing sitemap endpoint
    // Use core nonce because this endpoint is owned by the core AISB ajax controller.
    const out = await postCore("aisb_get_sitemap_by_id", {
      sitemap_id: state.sitemapId,
    });
    if (!out || !out.success) {
      elPages.innerHTML =
        '<div class="aisb-wf-muted" style="padding:8px;">Failed to load sitemap.</div>';
      return;
    }
    const data = out.data && out.data.data ? out.data.data : out.data || {};

    // The sitemap JSON structure may differ depending on the prompt/version.
    // We normalize it to a flat [{slug,title}] list for the wireframes UI.
    function normalizeSlug(v) {
      return (v || "").toString().trim().replace(/^\//, "");
    }

    function pushUnique(list, item) {
      if (!item || !item.slug) return;
      if (list.some((x) => x.slug === item.slug)) return;
      list.push(item);
    }

    function flattenHierarchy(nodes, out) {
      if (!Array.isArray(nodes)) return;
      nodes.forEach((n) => {
        if (!n || typeof n !== "object") return;
        const slug = normalizeSlug(
          n.slug || n.page_slug || n.url || n.path || "",
        );
        const title = (n.title || n.name || n.label || slug || "").toString();
        if (slug) pushUnique(out, { slug, title });
        const kids = n.children || n.items || n.pages || n.subpages;
        if (Array.isArray(kids)) flattenHierarchy(kids, out);
      });
    }

    const normalized = [];

    // 0) AISB Step 1 output stores pages as a flat array under `data.sitemap`.
    // Page fields commonly include: page_title, nav_label, slug, page_type, parent_slug.
    if (Array.isArray(data.sitemap)) {
      data.sitemap.forEach((p) => {
        const slug = normalizeSlug(
          p && (p.slug || p.page_slug || p.url || p.path),
        );
        const title = (
          (p &&
            (p.page_title ||
              p.nav_label ||
              p.title ||
              p.name ||
              p.label ||
              slug)) ||
          ""
        ).toString();
        if (slug) pushUnique(normalized, { slug, title });
      });
    }

    // 1) Direct pages array (alternative schema)
    if (!normalized.length && Array.isArray(data.pages)) {
      data.pages.forEach((p) => {
        const slug = normalizeSlug(
          p && (p.slug || p.page_slug || p.url || p.path),
        );
        const title = (
          (p && (p.title || p.name || p.label || slug)) ||
          ""
        ).toString();
        if (slug) pushUnique(normalized, { slug, title });
      });
    }

    // 2) Common alternatives
    if (!normalized.length) {
      const alt =
        data.hierarchy ||
        data.tree ||
        data.structure ||
        data.sitemap ||
        data.navigation;
      flattenHierarchy(alt, normalized);
    }

    // 3) Some outputs store hierarchy under e.g. data.data.hierarchy already handled above.

    state.pages = normalized;
    elPagesMeta.textContent = state.pages.length
      ? state.pages.length + " pages"
      : "";
    renderPageList();
    if (state.pages[0]) selectPage(state.pages[0].slug);
  }

  function renderPageList() {
    elPages.innerHTML = state.pages
      .map((p) => {
        const active = p.slug === state.pageSlug;
        return `<div class="aisb-wf-page-btn ${active ? "is-active" : ""}" data-page="${escapeHtml(p.slug)}">
        <div>
          <div style="font-weight:700; font-size:13px;">${escapeHtml(p.title || p.slug)}</div>
          <div class="aisb-wf-muted">/${escapeHtml(p.slug)}</div>
        </div>
      </div>`;
      })
      .join("");
  }

  elPages.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-page]");
    if (!btn) return;
    selectPage(btn.getAttribute("data-page"));
  });

  async function selectPage(slug) {
    state.pageSlug = slug;
    renderPageList();
    const page = state.pages.find((p) => p.slug === slug);
    elTitle.textContent = page ? page.title || slug : slug;
    elSub.textContent = "Loading wireframe...";
    elCompiled.textContent = "";
    const out = await post("aisb_get_wireframe_page", {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: slug,
    });
    if (out && out.success) {
      state.model = out.data.wireframe;
      // default pattern
      elPattern.value = state.model.pattern || "generic";
      elSub.textContent =
        state.model.sections && state.model.sections.length
          ? "Edit sections below."
          : "Generate a wireframe to start editing.";
      renderSections();
      setStatus("", "ok");
    } else {
      setStatus(
        out && out.data && out.data.message ? out.data.message : "Failed",
        "err",
      );
    }
  }

  function defaultPreviewSchema(type) {
    // Lightweight fallback schema so the preview is always informative even without a stored preview_schema.
    const t = (type || "generic").toString();
    const base = {
      type: t,
      elements: [
        { tag: "h2", text: "Section headline" },
        {
          tag: "p",
          text: "Short supporting copy that explains the value proposition in one or two sentences.",
        },
        { tag: "button", text: "Call to action" },
      ],
    };
    if (t === "hero") {
      return {
        type: t,
        layout: "hero-1",
        elements: [
          { tag: "eyebrow", text: "Tagline / Category" },
          { tag: "h1", text: "A clear headline that explains what you do" },
          {
            tag: "p",
            text: "A short paragraph that supports the headline and nudges the visitor to take action.",
          },
          { tag: "button", text: "Primary action" },
          { tag: "button", text: "Secondary action", variant: "secondary" },
          { tag: "media", text: "Hero image / illustration" },
        ],
      };
    }
    if (t === "features" || t === "values" || t === "social_proof") {
      return {
        ...base,
        elements: [
          { tag: "h2", text: "Why choose us" },
          { tag: "p", text: "A one-liner that frames the feature grid." },
          {
            tag: "cards",
            count: 3,
            item: { title: "Feature title", text: "Short description." },
          },
        ],
      };
    }
    if (t === "testimonials") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "What customers say" },
          {
            tag: "cards",
            count: 2,
            item: {
              title: "Name · Company",
              text: '"A short testimonial quote that builds trust."',
            },
          },
        ],
      };
    }
    if (t === "pricing") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "Pricing" },
          { tag: "p", text: "Pick the plan that matches your needs." },
          {
            tag: "cards",
            count: 3,
            item: { title: "Plan name", text: "Key benefits and price." },
          },
        ],
      };
    }
    if (t === "faq") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "Frequently asked questions" },
          {
            tag: "list",
            count: 3,
            item: { title: "Question", text: "Short answer." },
          },
        ],
      };
    }
    if (t === "cta") {
      return {
        type: t,
        elements: [
          { tag: "h2", text: "Ready to get started?" },
          {
            tag: "p",
            text: "A clear CTA line that reduces friction and tells people what happens next.",
          },
          { tag: "button", text: "Get in touch" },
        ],
      };
    }
    if (t === "footer") {
      return {
        type: t,
        elements: [{ tag: "p", text: "© Company name · Links · Contact" }],
      };
    }
    return base;
  }

  function schemaFromSection(section) {
    const s = section || {};
    const schema =
      s.preview_schema && typeof s.preview_schema === "object"
        ? s.preview_schema
        : null;
    return schema || defaultPreviewSchema(s.type || "generic");
  }

  function sectionPreview(schema) {
    const sc = schema || defaultPreviewSchema("generic");
    const type = (sc.type || "generic").toString();
    const els = Array.isArray(sc.elements) ? sc.elements : [];

    // Helpers
    const renderText = (tag, text, extraClass = "") =>
      `<${tag} class="aisb-wf-txt ${extraClass}">${escapeHtml(text || "")}</${tag}>`;

    if (type === "hero") {
      const eyebrow = els.find((e) => e.tag === "eyebrow")?.text || "Tagline";
      const h1 = els.find((e) => e.tag === "h1")?.text || "Hero headline";
      const p = els.find((e) => e.tag === "p")?.text || "Supporting paragraph.";
      const buttons = els.filter((e) => e.tag === "button");
      const primary = buttons[0]?.text || "Primary action";
      const secondary = buttons[1]?.text || "Secondary action";
      return `
        <div class="aisb-wf-hero">
          <div class="aisb-wf-hero-left">
            <div class="aisb-wf-eyebrow">${escapeHtml(eyebrow)}</div>
            <h1 class="aisb-wf-h1">${escapeHtml(h1)}</h1>
            <p class="aisb-wf-p">${escapeHtml(p)}</p>
            <div class="aisb-wf-row">
              <span class="aisb-wf-btnlbl primary">${escapeHtml(primary)}</span>
              <span class="aisb-wf-btnlbl">${escapeHtml(secondary)}</span>
            </div>
          </div>
          <div class="aisb-wf-hero-right">
            <div class="aisb-wf-media">Hero image</div>
          </div>
        </div>
      `;
    }

    // Generic stacked sections
    let out = '<div class="aisb-wf-skel">';
    for (const e of els) {
      if (!e || !e.tag) continue;
      if (e.tag === "h1") out += renderText("h2", e.text, "aisb-wf-h1");
      else if (e.tag === "h2") out += renderText("h3", e.text, "aisb-wf-h2");
      else if (
        e.tag === "h3" ||
        e.tag === "h4" ||
        e.tag === "h5" ||
        e.tag === "h6"
      )
        out += renderText("h4", e.text, "aisb-wf-h3");
      else if (e.tag === "p") out += renderText("p", e.text, "aisb-wf-p");
      else if (e.tag === "button")
        out += `<span class="aisb-wf-btnlbl ${e.variant === "secondary" ? "" : "primary"}">${escapeHtml(e.text || "Button")}</span>`;
      else if (e.tag === "media")
        out += `<div class="aisb-wf-media">${escapeHtml(e.text || "Media")}</div>`;
      else if (e.tag === "cards") {
        const n = Math.max(1, Math.min(6, parseInt(e.count || 3, 10) || 3));
        out +=
          '<div class="aisb-wf-cards">' +
          Array.from({ length: n })
            .map(() => {
              const title = e.item?.title || "Card title";
              const txt = e.item?.text || "Short description.";
              return `<div class="aisb-wf-card"><div class="aisb-wf-cardtitle">${escapeHtml(title)}</div><div class="aisb-wf-cardtxt">${escapeHtml(txt)}</div></div>`;
            })
            .join("") +
          "</div>";
      } else if (e.tag === "list") {
        const n = Math.max(1, Math.min(6, parseInt(e.count || 3, 10) || 3));
        out +=
          '<div class="aisb-wf-list">' +
          Array.from({ length: n })
            .map(() => {
              const title = e.item?.title || "Item";
              const txt = e.item?.text || "";
              return `<div class="aisb-wf-listitem"><div class="aisb-wf-cardtitle">${escapeHtml(title)}</div><div class="aisb-wf-cardtxt">${escapeHtml(txt)}</div></div>`;
            })
            .join("") +
          "</div>";
      }
    }
    out += "</div>";
    return out;
  }

  function bricksTemplatePreview(section) {
    const id = section.ai_wireframe_id || section.bricks_template_id;
    if (!id)
      return '<div class="aisb-wf-bricks-notfound">No Bricks template assigned.</div>';
    const title = section.bricks_template_title || "Template #" + id;
    const sc = section.bricks_shortcode || '[bricks_template id="' + id + '"]';
    const ttype = section.bricks_template_ttype
      ? `<div class="aisb-wf-bricks-type" style="display:inline-block; margin-right:8px;">${escapeHtml(section.bricks_template_ttype)}</div>`
      : "";
    const iframeUrl = (AISB_WF.previewUrl || "") + id;

    return `
    <div style="background:#fff; border:none; border-radius:0; overflow:hidden; position:relative; display:flex; flex-direction:column;">
      <div style="width:100%; position:relative;">
        <iframe src="${escapeHtml(iframeUrl)}" loading="lazy" class="aisb-bricks-iframe" style="width:100%; height:400px; border:none; display:block;" title="Bricks Preview" scrolling="no"></iframe>
      </div>
    </div>`;
  }

  // Auto-resize iframes + handle edit responses
  window.addEventListener("message", function (e) {
    if (!e.data || !e.data.type) return;

    if (e.data.type === "aisb_iframe_height" && e.data.height) {
      const iframes = document.querySelectorAll("iframe.aisb-bricks-iframe");
      for (let i = 0; i < iframes.length; i++) {
        if (iframes[i].contentWindow === e.source) {
          iframes[i].style.height = e.data.height + "px";
          break;
        }
      }
    }

    if (e.data.type === "aisb_edited_content") {
      // Find which section this iframe belongs to
      const iframes = document.querySelectorAll("iframe.aisb-bricks-iframe");
      let sectionCard = null;
      for (let i = 0; i < iframes.length; i++) {
        if (iframes[i].contentWindow === e.source) {
          sectionCard = iframes[i].closest("[data-uuid]");
          break;
        }
      }
      if (!sectionCard || !state.model) return;
      const uuid = sectionCard.getAttribute("data-uuid");
      const section = (state.model.sections || []).find((s) => s.uuid === uuid);
      if (!section || !(section.ai_wireframe_id || section.bricks_template_id))
        return;

      const changes = e.data.changes || [];
      if (changes.length === 0) {
        setStatus("No changes detected.", "ok");
        return;
      }

      // Save via AJAX
      post("aisb_save_section_text", {
        bricks_template_id:
          section.ai_wireframe_id || section.bricks_template_id,
        changes: JSON.stringify(changes),
      }).then((out) => {
        if (out && out.success) {
          setStatus(
            "Saved " +
              (out.data.changed || 0) +
              " text change(s). Refreshing...",
            "ok",
          );
          // Reload the iframe to show updated content
          const iframe = sectionCard.querySelector("iframe.aisb-bricks-iframe");
          if (iframe) {
            iframe.src = iframe.src;
          }
        } else {
          setStatus(
            "Save failed: " +
              ((out && out.data && out.data.message) || "Unknown error"),
            "err",
          );
        }
      });
    }
  });

  function renderSections() {
    const model = state.model || {};
    const sections = Array.isArray(model.sections) ? model.sections : [];
    if (!sections.length) {
      elSections.innerHTML =
        '<div class="aisb-wf-muted">No sections yet.</div>';
      return;
    }
    elSections.innerHTML = sections
      .map((s, idx) => {
        const type = (s.type || "generic").toString();
        const locked = !!s.locked;
        const schema = schemaFromSection(s);
        const lockTxt = locked ? "Unlock" : "Lock";
        const isBricks = !!s.bricks_template_id || !!s.ai_wireframe_id;
        const previewId = s.ai_wireframe_id || s.bricks_template_id;
        const bricksBadge = s.ai_wireframe_id
          ? `<span style="display:inline-block;margin-left:8px;padding:1px 7px;border-radius:999px;font-size:11px;background:#6d28d9;color:#fff;font-weight:600;vertical-align:middle;">AI #${escapeHtml(String(s.ai_wireframe_id))}</span>`
          : isBricks
            ? `<span style="display:inline-block;margin-left:8px;padding:1px 7px;border-radius:999px;font-size:11px;background:#111;color:#fff;font-weight:600;vertical-align:middle;">Bricks #${escapeHtml(String(s.bricks_template_id))}</span>`
            : "";
        const score =
          !isBricks && s.match_score !== undefined && s.match_score !== null
            ? " · score " + Math.round(parseFloat(s.match_score) * 10) / 10
            : "";
        const tags = !isBricks && s.match_tags ? " · " + s.match_tags : "";
        const bodyHtml = isBricks
          ? bricksTemplatePreview(s)
          : sectionPreview(schemaFromSection(s));
        return `
      <div class="aisb-wf-section" data-uuid="${escapeHtml(s.uuid)}">
        <div class="aisb-wf-section-toolbar">
          <button class="aisb-wf-tbtn" data-act="up" ${idx === 0 ? "disabled" : ""}  title="Move up">↑</button>
          <button class="aisb-wf-tbtn" data-act="down" ${idx === sections.length - 1 ? "disabled" : ""}  title="Move down">↓</button>
          <button class="aisb-wf-tbtn" data-act="shuffle" ${locked ? "disabled" : ""} title="Shuffle layout">⟳</button>
          <button class="aisb-wf-tbtn ${locked ? "active" : ""}" data-act="lock" title="${lockTxt}">🔒</button>
          <button class="aisb-wf-tbtn" data-act="edit" title="Edit text">✏️</button>
          <button class="aisb-wf-tbtn" data-act="dup" title="Duplicate">⧉</button>
          <button class="aisb-wf-tbtn" data-act="del" title="Delete" style="color:#f87171;">✕</button>
        </div>
        <div class="aisb-wf-body">${bodyHtml}</div>
      </div>`;
      })
      .join("");
  }

  elSections.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-act]");
    if (!btn) return;
    const card = e.target.closest("[data-uuid]");
    if (!card) return;
    const uuid = card.getAttribute("data-uuid");
    const act = btn.getAttribute("data-act");
    if (!state.model) return;
    const sections = state.model.sections || [];
    const idx = sections.findIndex((s) => s.uuid === uuid);
    if (idx < 0) return;

    if (act === "up" && idx > 0) {
      const tmp = sections[idx - 1];
      sections[idx - 1] = sections[idx];
      sections[idx] = tmp;
      renderSections();
      return;
    }
    if (act === "down" && idx < sections.length - 1) {
      const tmp = sections[idx + 1];
      sections[idx + 1] = sections[idx];
      sections[idx] = tmp;
      renderSections();
      return;
    }
    if (act === "del") {
      sections.splice(idx, 1);
      renderSections();
      return;
    }
    if (act === "dup") {
      const clone = JSON.parse(JSON.stringify(sections[idx]));
      clone.uuid =
        crypto && crypto.randomUUID
          ? crypto.randomUUID()
          : "dup_" + Math.random().toString(16).slice(2);
      clone.locked = false;
      sections.splice(idx + 1, 0, clone);
      renderSections();
      return;
    }
    if (act === "lock") {
      sections[idx].locked = !sections[idx].locked;
      renderSections();
      return;
    }
    if (act === "shuffle") {
      setStatus("Shuffling section...", "ok");
      const out = await post("aisb_shuffle_section_layout", {
        project_id: state.projectId,
        sitemap_version_id: state.sitemapId,
        page_slug: state.pageSlug,
        uuid,
      });
      if (out && out.success) {
        state.model = out.data.wireframe;
        renderSections();
        setStatus("Shuffled.", "ok");
      } else {
        setStatus(
          out && out.data && out.data.message
            ? out.data.message
            : "Shuffle failed",
          "err",
        );
      }
      return;
    }
    if (act === "edit") {
      const section = sections[idx];
      if (!section.bricks_template_id) {
        setStatus("Only Bricks template sections can be edited inline.", "err");
        return;
      }
      const iframe = card.querySelector("iframe.aisb-bricks-iframe");
      if (!iframe || !iframe.contentWindow) {
        setStatus("Preview iframe not loaded yet.", "err");
        return;
      }
      const isEditing = card.classList.contains("aisb-editing");
      if (isEditing) {
        // Disable edit mode and save
        card.classList.remove("aisb-editing");
        btn.classList.remove("active");
        iframe.contentWindow.postMessage({ type: "aisb_disable_edit" }, "*");
        iframe.style.overflow = "hidden";
        // Save: ask iframe for updated content, then POST to server
        setStatus("Saving changes...", "ok");
        iframe.contentWindow.postMessage(
          { type: "aisb_get_edited_content" },
          "*",
        );
        // We handle the response via the message listener below
      } else {
        // Enable edit mode
        card.classList.add("aisb-editing");
        btn.classList.add("active");
        iframe.style.overflow = "auto";
        iframe.contentWindow.postMessage({ type: "aisb_enable_edit" }, "*");
        setStatus(
          "Edit mode: click on text in the preview to change it. Click ✏️ again to save.",
          "ok",
        );
      }
      return;
    }
  });

  elSections.addEventListener("change", async (e) => {
    const sel = e.target.closest('[data-act="type"]');
    if (!sel) return;
    const card = e.target.closest("[data-uuid]");
    if (!card) return;
    const uuid = card.getAttribute("data-uuid");
    const newType = sel.value;
    setStatus("Replacing section type...", "ok");
    const out = await post("aisb_replace_section_type", {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug,
      uuid,
      new_type: newType,
    });
    if (out && out.success) {
      state.model = out.data.wireframe;
      renderSections();
      setStatus("Replaced.", "ok");
    } else {
      setStatus(
        out && out.data && out.data.message
          ? out.data.message
          : "Replace failed",
        "err",
      );
    }
  });

  btnGenerate.addEventListener("click", async () => {
    if (!state.pageSlug) return;
    setStatus("Generating wireframe...", "ok");
    const out = await post("aisb_generate_wireframe_page", {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug,
      pattern: elPattern.value,
    });
    if (out && out.success) {
      state.model = out.data.wireframe;
      renderSections();
      setStatus("Generated.", "ok");
    } else {
      setStatus(
        out && out.data && out.data.message
          ? out.data.message
          : "Generate failed",
        "err",
      );
    }
  });

  btnGenerateAll?.addEventListener("click", async () => {
    if (!state.pages || state.pages.length === 0) {
      setStatus("No pages found in sitemap.", "err");
      return;
    }
    if (
      !confirm(
        "This will generate wireframes for ALL pages sequentially. Existing un-saved wireframe edits could be overridden. Continue?",
      )
    ) {
      return;
    }

    btnGenerateAll.disabled = true;
    btnGenerate.disabled = true;

    const initialSlug = state.pageSlug;
    let successCount = 0;
    let failCount = 0;

    for (const p of state.pages) {
      state.pageSlug = p.slug;

      // Update UI active state visually
      const allBtns = elPagesList.querySelectorAll(".aisb-wf-page-btn");
      allBtns.forEach((b) => b.classList.remove("is-active"));
      const activeBtn = elPagesList.querySelector(
        `[data-aisb-wf-page-btn="${p.slug}"]`,
      );
      if (activeBtn) activeBtn.classList.add("is-active");

      elPageTitle.textContent = p.title;
      elPageSub.textContent = p.slug;
      elSections.innerHTML =
        '<div class="aisb-wf-muted" style="padding:16px;">Generating wireframe... Please wait.</div>';

      setStatus(
        `Generating wireframe for ${p.title} (${successCount + failCount + 1}/${state.pages.length})...`,
        "ok",
      );

      try {
        const out = await post("aisb_generate_wireframe_page", {
          project_id: state.projectId,
          sitemap_version_id: state.sitemapId,
          page_slug: p.slug,
          pattern: "generic", // Default to generic for bulk generation to ensure consistency, or could use elPattern.value but generic is safer
        });

        if (out && out.success) {
          state.model = out.data.wireframe;
          renderSections();

          // Auto-save the individual page so it persists
          setStatus(`Saving wireframe for ${p.title}...`, "ok");
          await post("aisb_update_wireframe_page", {
            project_id: state.projectId,
            sitemap_version_id: state.sitemapId,
            page_slug: p.slug,
            model_json: JSON.stringify(state.model),
          });

          successCount++;
        } else {
          failCount++;
          elSections.innerHTML = `<div class="aisb-error" style="padding:16px;">Generation failed for ${p.title}.</div>`;
        }
      } catch (e) {
        failCount++;
        elSections.innerHTML = `<div class="aisb-error" style="padding:16px;">Error mapping ${p.title}.</div>`;
      }
    }

    btnGenerateAll.disabled = false;
    btnGenerate.disabled = false;

    // Load back initial or first page
    state.pageSlug = initialSlug || state.pages[0].slug;
    loadWireframePage(state.pageSlug);

    setStatus(
      `Finished! Generated: ${successCount}. Failed: ${failCount}.`,
      failCount > 0 ? "err" : "ok",
    );
  });

  btnShufflePage.addEventListener("click", async () => {
    // client-side shuffle: call shuffle endpoint per unlocked section
    if (!state.model || !state.model.sections) return;
    for (const s of state.model.sections) {
      if (s.locked) continue;
      const out = await post("aisb_shuffle_section_layout", {
        project_id: state.projectId,
        sitemap_version_id: state.sitemapId,
        page_slug: state.pageSlug,
        uuid: s.uuid,
      });
      if (out && out.success) {
        state.model = out.data.wireframe;
      }
    }
    renderSections();
    setStatus("Shuffled unlocked sections.", "ok");
  });

  btnSave.addEventListener("click", async () => {
    if (!state.model) return;
    setStatus("Saving...", "ok");
    const out = await post("aisb_update_wireframe_page", {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug,
      model_json: JSON.stringify(state.model),
    });
    if (out && out.success) {
      setStatus("Saved.", "ok");
    } else {
      setStatus(
        out && out.data && out.data.message ? out.data.message : "Save failed",
        "err",
      );
    }
  });

  btnCompile.addEventListener("click", async () => {
    if (!state.pageSlug) return;
    setStatus("Compiling...", "ok");
    const out = await post("aisb_compile_wireframe_page", {
      project_id: state.projectId,
      sitemap_version_id: state.sitemapId,
      page_slug: state.pageSlug,
    });
    if (out && out.success) {
      elCompiled.textContent = JSON.stringify(out.data.compiled, null, 2);
      setStatus("Compiled.", "ok");
    } else {
      setStatus(
        out && out.data && out.data.message
          ? out.data.message
          : "Compile failed",
        "err",
      );
    }
  });

  loadSitemapPages();
})();
