/**
 * Utility functions and data processing.
 * Depends on: app-init.js
 */
(function () {
  "use strict";

  const app = window.AISBApp;
  if (!app) return;

  /* ── Helpers ────────────────────────────────────────────── */

  const setStatus = (html) => {
    app.statusEl.innerHTML = html || "";
  };

  const esc = (s) =>
    (s ?? "")
      .toString()
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");

  const deepClone = (obj) => JSON.parse(JSON.stringify(obj));

  const stripTransient = (obj) => {
    if (Array.isArray(obj)) return obj.map(stripTransient);
    if (obj && typeof obj === "object") {
      const out = {};
      for (const k in obj) {
        if (!Object.prototype.hasOwnProperty.call(obj, k)) continue;
        if (k.startsWith("_")) continue;
        out[k] = stripTransient(obj[k]);
      }
      return out;
    }
    return obj;
  };

  const stableStringify = (obj) => {
    const norm = (v) => {
      if (Array.isArray(v)) return v.map(norm);
      if (v && typeof v === "object") {
        const out = {};
        Object.keys(v)
          .sort()
          .forEach((k) => {
            out[k] = norm(v[k]);
          });
        return out;
      }
      return v;
    };
    return JSON.stringify(norm(obj));
  };

  const slugMap = (data) => {
    const pages = Array.isArray(data?.sitemap) ? data.sitemap : [];
    const map = {};
    pages.forEach((p) => {
      if (p && typeof p === "object" && p.slug) map[p.slug] = p;
    });
    return map;
  };

  const computeAutoLabel = (baseline, current) => {
    const baseMap = slugMap(baseline || {});
    const curMap = slugMap(current || {});
    const baseSlugs = new Set(Object.keys(baseMap));
    const curSlugs = new Set(Object.keys(curMap));

    const added = Array.from(curSlugs)
      .filter((s) => !baseSlugs.has(s))
      .sort();
    const removed = Array.from(baseSlugs)
      .filter((s) => !curSlugs.has(s))
      .sort();

    if (added.length) return added.map((s) => `+${s}`).join(" ");
    if (removed.length) return removed.map((s) => `-${s}`).join(" ");

    const changed = [];
    Array.from(curSlugs).forEach((slug) => {
      if (!baseSlugs.has(slug)) return;
      const a = stripTransient(baseMap[slug]);
      const b = stripTransient(curMap[slug]);
      if (stableStringify(a) !== stableStringify(b)) changed.push(slug);
    });

    if (changed.length) return changed.sort().join(", ");
    return "No changes";
  };

  const setLoading = (loading) => {
    app.btnGen.disabled = !!loading;
    app.btnGen.textContent = loading ? "Generating…" : "Generate sitemap";
  };

  const updateWireframesLinks = () => {
    if (!app.state.projectId || !app.state.sitemapId) return;
    const wfUrlObj = new URL(window.location.href);
    wfUrlObj.searchParams.set("aisb_step", "2");
    wfUrlObj.searchParams.set("aisb_project", app.state.projectId);
    wfUrlObj.searchParams.set("aisb_sitemap", app.state.sitemapId);
    const wfUrl = wfUrlObj.toString();

    if (app.step2TabEl) app.step2TabEl.href = wfUrl;
    if (app.btnGoWireframes) {
      app.btnGoWireframes.href = wfUrl;
      app.btnGoWireframes.style.display = "";
    }
  };

  /* ── Slug helpers ───────────────────────────────────────── */

  const normalizeSlug = (s) =>
    (s ?? "").toString().trim().replace(/^\/+/, "").replace(/\/+$/, "");

  const slugify = (s) =>
    normalizeSlug(
      (s ?? "")
        .toString()
        .trim()
        .toLowerCase()
        .replace(/['"]/g, "")
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-+|-+$/g, ""),
    ) || "page-" + Math.random().toString(16).slice(2, 8);

  const coerceType = (type, name) => {
    if (app.SECTION_TYPES.includes(type)) return type;
    const n = (name || "").toString().trim().toLowerCase();
    if (n === "navbar") return "Headers";
    if (n === "hero") return "Hero Sections";
    if (n === "footer") return "Footers";
    if (n === "cta") return "CTA Sections";
    if (n === "faq") return "FAQ Sections";
    if (n === "social proof") return "Testimonial Sections";
    if (n === "process") return "Process Sections";
    if (n === "services overview") return "Feature Sections";
    if (n === "blog hero") return "Blog Sections";
    if (n === "blog list") return "Blog Sections";
    if (n === "content") return "Content Sections";
    return "Content Sections";
  };

  /* ── Data processing ────────────────────────────────────── */

  const ensureRequiredSections = (page) => {
    const pt = page.page_type || "Other";
    const slug = page.slug || "";
    const isBlogListing = pt === "Blog" && !slug.includes("post");

    let sections = Array.isArray(page.sections) ? page.sections : [];
    const has = new Set(
      sections.map((s) => (s.section_name || "").toString().toLowerCase()),
    );

    const add = (name, purpose, kc, section_type) => {
      const k = name.toLowerCase();
      if (has.has(k)) return;
      sections.push({
        section_name: name,
        section_type: coerceType(section_type, name),
        purpose,
        key_content: kc,
      });
      has.add(k);
    };

    const normalizeSections = () => {
      sections = (Array.isArray(sections) ? sections : [])
        .map((s) => {
          const section_name = (s?.section_name ?? "").toString();
          const purpose = (s?.purpose ?? "").toString();
          const key_content = Array.isArray(s?.key_content)
            ? s.key_content
            : [];
          const section_type = coerceType(
            (s?.section_type ?? "").toString().trim(),
            section_name,
          );
          return { section_name, section_type, purpose, key_content };
        })
        .filter((s) => s.section_name.trim() !== "");
    };

    normalizeSections();
    has.clear();
    sections.forEach((s) => has.add((s.section_name || "").toLowerCase()));

    if (isBlogListing) {
      sections = [];
      has.clear();
      add(
        "Navbar",
        "Primary navigation and key CTAs.",
        ["Logo", "Menu items", "CTA button"],
        "Headers",
      );
      add(
        "Blog Hero",
        "Introduce the blog and highlight featured content.",
        ["Title", "Intro", "Featured posts"],
        "Blog Sections",
      );
      add(
        "Blog List",
        "List all blog posts with filters/search.",
        ["Post cards", "Categories/tags", "Pagination"],
        "Blog Sections",
      );
      add(
        "Footer",
        "Secondary navigation, trust, and contact details.",
        ["Links", "Contact info", "Legal", "Social links"],
        "Footers",
      );
      page.sections = sections;
      return page;
    }

    const min = 5,
      max = 10;
    const pads = [
      [
        "Services overview",
        "Preview key offerings.",
        ["Service cards", "Benefits", "CTA"],
        "Feature Sections",
      ],
      [
        "Social proof",
        "Build trust quickly.",
        ["Testimonials", "Logos", "Ratings"],
        "Testimonial Sections",
      ],
      [
        "Process",
        "Explain how it works.",
        ["Steps", "Timeline", "What to expect"],
        "Process Sections",
      ],
      [
        "FAQ",
        "Answer common objections.",
        ["Pricing", "Scope", "Support"],
        "FAQ Sections",
      ],
      [
        "CTA",
        "Drive the next step.",
        ["Call booking", "Contact link", "Offer summary"],
        "CTA Sections",
      ],
    ];
    for (const p of pads) {
      if (sections.length >= min) break;
      add(p[0], p[1], p[2], p[3]);
    }

    if (sections.length > max) {
      const req = ["navbar", "hero", "footer"];
      const reqArr = [];
      const other = [];
      for (const s of sections) {
        const k = (s.section_name || "").toString().toLowerCase();
        if (req.includes(k)) reqArr.push(s);
        else other.push(s);
      }
      sections = reqArr.concat(other.slice(0, max - reqArr.length));
    }

    page.sections = sections;
    return page;
  };

  const ensureHierarchy = (pages) => {
    let home = pages.find(
      (p) => p.page_type === "Home" || normalizeSlug(p.slug) === "home",
    );
    if (!home) {
      home = {
        page_title: "Home",
        nav_label: "Home",
        slug: "home",
        page_type: "Home",
        priority: "Core",
        parent_slug: null,
        sections: [],
        seo: {},
      };
      pages.unshift(home);
    }
    home.slug = "home";
    home.parent_slug = null;
    ensureRequiredSections(home);

    pages.forEach((p) => {
      p.slug =
        normalizeSlug(p.slug) || slugify(p.page_title || p.nav_label || "page");
    });

    const bySlug = {};
    pages.forEach((p) => {
      bySlug[p.slug] = p;
    });

    pages.forEach((p) => {
      if (p.slug === "home" || p.page_type === "Home") {
        p.parent_slug = null;
        return;
      }
      p.parent_slug = normalizeSlug(p.parent_slug) || "home";
      if (p.parent_slug === p.slug) p.parent_slug = "home";
      if (!bySlug[p.parent_slug]) p.parent_slug = "home";
      ensureRequiredSections(p);
    });

    const visitedGlobal = new Set();
    const visiting = new Set();

    const dfsCheck = (slug) => {
      if (slug === "home") return;
      if (visiting.has(slug)) {
        const node = bySlug[slug];
        if (node) node.parent_slug = "home";
        return;
      }
      if (visitedGlobal.has(slug)) return;
      visitedGlobal.add(slug);
      visiting.add(slug);

      const node = bySlug[slug];
      if (node) {
        const parent = normalizeSlug(node.parent_slug || "");
        if (!parent || parent === slug || !bySlug[parent])
          node.parent_slug = "home";
        else dfsCheck(parent);
      }
      visiting.delete(slug);
    };

    pages.forEach((p) => dfsCheck(p.slug));
    return pages;
  };

  const buildIndex = (pages) => {
    const bySlug = {};
    pages.forEach((p) => {
      const slug = normalizeSlug(p.slug || "");
      if (!slug) return;
      bySlug[slug] = {
        ...p,
        slug,
        parent_slug: p.parent_slug ? normalizeSlug(p.parent_slug) : null,
        nav_label: p.nav_label || p.page_title || slug,
        _x: p._x ?? null,
        _y: p._y ?? null,
        _userMoved: p._userMoved === true,
      };
    });
    return bySlug;
  };

  const buildTree = (bySlug) => {
    const childrenByParent = {};
    Object.values(bySlug).forEach((p) => {
      const parent = p.parent_slug || null;
      if (!childrenByParent[parent]) childrenByParent[parent] = [];
      childrenByParent[parent].push(p);
    });

    const prioRank = { Core: 0, Support: 1, Optional: 2 };
    const sortFn = (a, b) => {
      const aHome = a.page_type === "Home" ? -1 : 0;
      const bHome = b.page_type === "Home" ? -1 : 0;
      if (aHome !== bHome) return aHome - bHome;
      const pa = prioRank[a.priority] ?? 9;
      const pb = prioRank[b.priority] ?? 9;
      if (pa !== pb) return pa - pb;
      return (a.nav_label || a.page_title || "").localeCompare(
        b.nav_label || b.page_title || "",
      );
    };

    Object.keys(childrenByParent).forEach((k) =>
      childrenByParent[k].sort(sortFn),
    );

    const roots = (childrenByParent[null] || []).sort(sortFn);
    const walk = (node) => {
      const kids = childrenByParent[node.slug] || [];
      return { ...node, children: kids.map(walk) };
    };
    return roots.map(walk);
  };

  /* ── Expose on namespace ────────────────────────────────── */

  Object.assign(app, {
    setStatus,
    esc,
    deepClone,
    stripTransient,
    stableStringify,
    slugMap,
    computeAutoLabel,
    setLoading,
    updateWireframesLinks,
    normalizeSlug,
    slugify,
    coerceType,
    ensureRequiredSections,
    ensureHierarchy,
    buildIndex,
    buildTree,
  });
})();
