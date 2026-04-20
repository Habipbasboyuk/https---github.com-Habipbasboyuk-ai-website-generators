/**
 * Style Guide — Step 3: Images
 * Upload own images + auto-fill remaining from Unsplash, swap modal.
 */
(function () {
  "use strict";

  const SG = window.AISB_StyleGuide;
  if (!SG) return;

  const el = SG.el;
  const autoImagesContainer = el.autoImagesContainer;

  // Uploaded images array (shape: same as Unsplash images)
  SG.uploadedImages = [];

  /* ─── Count total media slots across wireframes ────────────── */

  function countTotalMedia() {
    let count = 0;
    SG.wireframePages.forEach(function (page) {
      (page.sections || []).forEach(function (s) {
        count += s.media_count || 0;
      });
    });
    return count;
  }

  function updateUploadHint() {
    const total = countTotalMedia();
    const uploaded = SG.uploadedImages.length;
    if (el.uploadTotalNeeded) el.uploadTotalNeeded.textContent = total;
    if (el.uploadHint) {
      el.uploadHint.textContent =
        uploaded + " images uploaded · " + total + " needed total";
    }
  }

  /* ─── Upload handling ──────────────────────────────────────── */

  function handleFiles(files) {
    if (!files || !files.length) return;

    const formData = new FormData();
    formData.append("action", "aisb_upload_images");
    formData.append("nonce", AISB_SG.nonce);
    for (let i = 0; i < files.length; i++) {
      formData.append("images[]", files[i]);
    }

    // Show uploading state
    if (el.uploadHint)
      el.uploadHint.textContent = "Uploading " + files.length + " image(s)…";

    fetch(AISB_SG.ajaxUrl, { method: "POST", body: formData })
      .then(function (r) {
        return r.json();
      })
      .then(function (out) {
        if (!out || !out.success || !out.data.images) {
          if (el.uploadHint)
            el.uploadHint.textContent = "Upload failed. Try again.";
          return;
        }
        // Add to uploaded images array
        out.data.images.forEach(function (img) {
          SG.uploadedImages.push(img);
        });
        renderUploadedGrid();
        updateUploadHint();
      })
      .catch(function () {
        if (el.uploadHint)
          el.uploadHint.textContent = "Upload failed. Try again.";
      });
  }

  SG.renderUploadedGrid = renderUploadedGrid;
  function renderUploadedGrid() {
    if (!el.uploadedGrid || !el.uploadedGridInner) return;
    if (!SG.uploadedImages.length) {
      el.uploadedGrid.style.display = "none";
      return;
    }
    el.uploadedGrid.style.display = "";
    if (el.uploadedCount) {
      el.uploadedCount.textContent = SG.uploadedImages.length + " images";
    }
    let html = "";
    SG.uploadedImages.forEach(function (img, i) {
      html +=
        '<div class="aisb-sg-auto-card aisb-sg-uploaded-card" data-uploaded-index="' +
        i +
        '">' +
        '<img src="' +
        SG.escapeHtml(img.thumb) +
        '" alt="' +
        SG.escapeHtml(img.alt || "") +
        '" loading="lazy">' +
        '<button type="button" class="aisb-sg-upload-remove" data-remove-upload="' +
        i +
        '" title="Remove">&times;</button>' +
        "</div>";
    });
    el.uploadedGridInner.innerHTML = html;

    // Remove handlers
    el.uploadedGridInner
      .querySelectorAll("[data-remove-upload]")
      .forEach(function (btn) {
        btn.addEventListener("click", function (e) {
          e.stopPropagation();
          const idx = parseInt(btn.getAttribute("data-remove-upload"), 10);
          SG.uploadedImages.splice(idx, 1);
          renderUploadedGrid();
          updateUploadHint();
        });
      });
  }

  // Drag & drop on upload zone
  if (el.uploadZone) {
    el.uploadZone.addEventListener("dragover", function (e) {
      e.preventDefault();
      el.uploadZone.classList.add("is-dragover");
    });
    el.uploadZone.addEventListener("dragleave", function () {
      el.uploadZone.classList.remove("is-dragover");
    });
    el.uploadZone.addEventListener("drop", function (e) {
      e.preventDefault();
      el.uploadZone.classList.remove("is-dragover");
      handleFiles(e.dataTransfer.files);
    });
  }

  // File input change
  if (el.uploadInput) {
    el.uploadInput.addEventListener("change", function () {
      handleFiles(el.uploadInput.files);
      el.uploadInput.value = "";
    });
  }

  /* ─── Auto-assign images (uploaded first, then Unsplash) ───── */

  SG.autoAssignImages = async function () {
    if (!autoImagesContainer || SG.imagesLoaded) return;
    const total = countTotalMedia();

    updateUploadHint();

    if (!total) {
      autoImagesContainer.innerHTML =
        '<div class="aisb-sg-empty-state">No images needed — no media elements found in wireframes.</div>';
      return;
    }

    // Always fetch ALL needed images from Unsplash
    let keyword = SG.guide._imageKeyword || "";

    autoImagesContainer.innerHTML =
      '<div class="aisb-sg-empty-state">Loading ' +
      total +
      " images from Unsplash…</div>";

    const out = await SG.post("aisb_get_unsplash_images", {
      project_id: SG.projectId,
      total_needed: total,
    });

    let images = [];
    if (out && out.success && out.data.images && out.data.images.length) {
      images = out.data.images;
      keyword = out.data.keyword || "";
      SG.guide._imageKeyword = keyword;
    }

    // If Unsplash didn't return enough, fill remaining slots from uploads
    if (images.length < total && SG.uploadedImages.length) {
      const remaining = total - images.length;
      images = images.concat(SG.uploadedImages.slice(0, remaining));
    }

    SG.imagesLoaded = true;

    // Render the images grid
    if (images.length) {
      let imgIdx = 0;
      let html = "";
      SG.wireframePages.forEach(function (page) {
        const pageImages = [];
        const pageIndices = [];
        (page.sections || []).forEach(function (s) {
          let count = s.media_count || 0;
          for (let i = 0; i < count; i++) {
            if (imgIdx < images.length) {
              pageImages.push(images[imgIdx]);
              pageIndices.push(imgIdx);
              imgIdx++;
            }
          }
        });
        if (!pageImages.length) return;

        html +=
          '<div class="aisb-sg-auto-group">' +
          '<h5 class="aisb-sg-auto-group-title">' +
          SG.escapeHtml(page.title || page.slug) +
          ' <span class="aisb-sg-auto-group-count">' +
          pageImages.length +
          " images</span></h5>" +
          '<div class="aisb-sg-auto-grid">';
        pageImages.forEach(function (img, i) {
          html +=
            '<div class="aisb-sg-auto-card" data-image-index="' +
            pageIndices[i] +
            '">' +
            '<img src="' +
            SG.escapeHtml(img.thumb) +
            '" alt="' +
            SG.escapeHtml(img.alt || keyword) +
            '" loading="lazy">' +
            '<div class="aisb-sg-auto-credit">' +
            SG.escapeHtml(img.photographer) +
            "</div></div>";
        });
        html += "</div></div>";
      });
      autoImagesContainer.innerHTML = html;
    } else {
      autoImagesContainer.innerHTML =
        '<div class="aisb-sg-empty-state">Could not load images from Unsplash.</div>';
    }

    // Store images in guide for saving + injection
    SG.guide.images = images.slice(0, total);

    // Persist immediately so the save handler always has images even if the
    // user clicks Save & Design before the draft auto-save fires.
    if (SG.saveDraft) SG.saveDraft();

    // Inject images into all loaded iframes
    SG.applyImagesToAllIframes();
  };

  /* ─── Image map + iframe injection ─────────────────────────── */

  function buildImageMap() {
    if (!SG.guide.images || !SG.guide.images.length) return {};
    const map = {};
    let imgIdx = 0;
    SG.wireframePages.forEach(function (page) {
      (page.sections || []).forEach(function (s, sIdx) {
        let count = s.media_count || 0;
        const urls = [];
        for (let i = 0; i < count; i++) {
          if (imgIdx < SG.guide.images.length) {
            urls.push(SG.guide.images[imgIdx++].full);
          }
        }
        if (urls.length) {
          map[page.slug + ":" + sIdx] = urls;
        }
      });
    });
    return map;
  }

  /* ── Compute global image-array base index for a section ──── */
  function getGlobalImageIdxBase(pageSlug, sectionIdx) {
    let base = 0;
    for (let pi = 0; pi < SG.wireframePages.length; pi++) {
      const page = SG.wireframePages[pi];
      const sections = page.sections || [];
      for (let si = 0; si < sections.length; si++) {
        if (page.slug === pageSlug && si === sectionIdx) return base;
        base += sections[si].media_count || 0;
      }
    }
    return base;
  }

  /* ── Create click-to-swap overlays over each injected image ─ */
  function createImageOverlays(iframe) {
    const wrapper = iframe.parentElement;
    if (!wrapper) return;
    // Remove old overlays
    wrapper.querySelectorAll(".aisb-img-click-overlay").forEach(function (o) {
      o.remove();
    });
    if (!SG.guide.images || !SG.guide.images.length) return;
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (!doc) return;
      const base = getGlobalImageIdxBase(iframe._pageSlug, iframe._sectionIdx);
      doc.querySelectorAll("img[data-aisb-img-ui]").forEach(function (img) {
        const ui = parseInt(img.getAttribute("data-aisb-img-ui"), 10);
        if (isNaN(ui)) return;
        const globalIdx = base + ui;
        if (!SG.guide.images[globalIdx]) return;
        const rect = img.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        const overlay = document.createElement("div");
        overlay.className = "aisb-img-click-overlay";
        overlay.setAttribute("data-aisb-overlay-idx", globalIdx);
        overlay.style.cssText =
          "position:absolute;top:" +
          rect.top +
          "px;left:" +
          rect.left +
          "px;width:" +
          rect.width +
          "px;height:" +
          rect.height +
          "px;";
        overlay.title = "Click to swap image";
        overlay.addEventListener("click", function (e) {
          e.stopPropagation();
          openImageSwapModal(globalIdx);
        });
        wrapper.appendChild(overlay);
      });
    } catch (e) {
      /* cross-origin */
    }
  }

  SG.injectImagesIntoIframe = function (iframe) {
    if (!SG.guide.images || !SG.guide.images.length) return;
    try {
      const doc = iframe.contentDocument || iframe.contentWindow.document;
      if (!doc) return;
      const key = iframe._pageSlug + ":" + iframe._sectionIdx;
      const map = buildImageMap();
      const urls = map[key];
      if (!urls || !urls.length) return;

      const imgs = doc.querySelectorAll("img");
      let ui = 0;
      imgs.forEach(function (img) {
        if (ui < urls.length) {
          img.src = urls[ui];
          img.srcset = "";
          img.style.objectFit = "cover";
          img.setAttribute("data-aisb-img-ui", ui);
          ui++;
        }
      });
    } catch (e) {
      /* cross-origin */
    }
    // Build click overlays after injection (runs outside try-catch)
    createImageOverlays(iframe);
  };

  SG.applyImagesToAllIframes = function () {
    SG.allIframes.forEach(function (iframe) {
      if (iframe._loaded) SG.injectImagesIntoIframe(iframe);
    });
  };

  /* ─── Image swap modal ─────────────────────────────────────── */

  let swapModalPage = 1;
  let swapModalKeyword = "";
  let swapModalIdx = -1;

  // Delegated click on image cards
  autoImagesContainer &&
    autoImagesContainer.addEventListener("click", function (e) {
      const card = e.target.closest(".aisb-sg-auto-card[data-image-index]");
      if (!card) return;
      const idx = parseInt(card.getAttribute("data-image-index"), 10);
      if (isNaN(idx) || !SG.guide.images || !SG.guide.images[idx]) return;
      openImageSwapModal(idx);
    });

  function openImageSwapModal(idx) {
    swapModalIdx = idx;
    swapModalPage = 1;
    let swapModalTab = "uploads"; // start on uploads if available, else unsplash
    swapModalKeyword =
      (SG.guide.images[idx] && SG.guide.images[idx].alt) ||
      SG.guide._imageKeyword ||
      "";

    const hasUploads = SG.uploadedImages && SG.uploadedImages.length > 0;
    if (!hasUploads) swapModalTab = "unsplash";

    const overlay = document.createElement("div");
    overlay.className = "aisb-sg-img-modal-overlay";
    overlay.innerHTML =
      '<div class="aisb-sg-img-modal">' +
      '<div class="aisb-sg-img-modal-header">' +
      "<h4>Replace image</h4>" +
      '<button class="aisb-sg-img-modal-close" type="button">&times;</button>' +
      "</div>" +
      '<div class="aisb-sg-img-modal-body">' +
      // Tab bar
      '<div class="aisb-sg-img-modal-tabs">' +
      '<button type="button" class="aisb-sg-img-tab' +
      (swapModalTab === "uploads" ? " is-active" : "") +
      '" data-modal-tab="uploads">Your uploads' +
      (hasUploads ? " (" + SG.uploadedImages.length + ")" : " (0)") +
      "</button>" +
      '<button type="button" class="aisb-sg-img-tab' +
      (swapModalTab === "unsplash" ? " is-active" : "") +
      '" data-modal-tab="unsplash">Unsplash</button>' +
      // Upload button inside modal
      '<label class="aisb-sg-img-tab aisb-sg-img-tab-upload">+ Upload<input type="file" multiple accept="image/*" data-modal-upload-input style="display:none;"></label>' +
      "</div>" +
      // Search bar (only for Unsplash tab)
      '<div class="aisb-sg-img-modal-search" data-modal-search-bar style="margin-bottom:12px;display:' +
      (swapModalTab === "unsplash" ? "flex" : "none") +
      ';gap:8px;">' +
      '<input type="text" class="aisb-sg-img-modal-input" value="' +
      SG.escapeHtml(swapModalKeyword) +
      '" placeholder="Search keyword…" style="flex:1;padding:6px 10px;border:1px solid rgba(0,0,0,.15);border-radius:6px;font-size:13px;">' +
      '<button class="aisb-btn" type="button" data-modal-search style="font-size:13px;padding:6px 14px;">Search</button>' +
      "</div>" +
      '<div class="aisb-sg-img-modal-grid" data-modal-grid></div>' +
      "</div>" +
      '<div class="aisb-sg-img-modal-footer">' +
      '<button class="aisb-btn-secondary" type="button" data-modal-prev disabled>← Previous</button>' +
      '<span data-modal-page-info style="font-size:12px;color:rgba(0,0,0,.4);"></span>' +
      '<button class="aisb-btn-secondary" type="button" data-modal-next>Next →</button>' +
      "</div>" +
      "</div>";

    document.body.appendChild(overlay);

    const searchBar = overlay.querySelector("[data-modal-search-bar]");
    const footerEl = overlay.querySelector(".aisb-sg-img-modal-footer");

    // Close handlers
    overlay
      .querySelector(".aisb-sg-img-modal-close")
      .addEventListener("click", function () {
        overlay.remove();
      });
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) overlay.remove();
    });

    // Tab switching
    overlay.querySelectorAll("[data-modal-tab]").forEach(function (tabBtn) {
      tabBtn.addEventListener("click", function () {
        swapModalTab = tabBtn.getAttribute("data-modal-tab");
        overlay.querySelectorAll("[data-modal-tab]").forEach(function (b) {
          b.classList.toggle(
            "is-active",
            b.getAttribute("data-modal-tab") === swapModalTab,
          );
        });
        searchBar.style.display = swapModalTab === "unsplash" ? "flex" : "none";
        footerEl.style.display = swapModalTab === "unsplash" ? "" : "none";
        if (swapModalTab === "uploads") {
          renderModalUploads(overlay);
        } else {
          swapModalPage = 1;
          loadModalImages(overlay);
        }
      });
    });

    // Upload inside modal
    const modalUploadInput = overlay.querySelector("[data-modal-upload-input]");
    if (modalUploadInput) {
      modalUploadInput.addEventListener("change", function () {
        if (!modalUploadInput.files || !modalUploadInput.files.length) return;
        const formData = new FormData();
        formData.append("action", "aisb_upload_images");
        formData.append("nonce", AISB_SG.nonce);
        for (let i = 0; i < modalUploadInput.files.length; i++) {
          formData.append("images[]", modalUploadInput.files[i]);
        }
        const grid = overlay.querySelector("[data-modal-grid]");
        grid.innerHTML =
          '<div class="aisb-sg-empty-state" style="grid-column:1/-1;">Uploading…</div>';
        fetch(AISB_SG.ajaxUrl, { method: "POST", body: formData })
          .then(function (r) {
            return r.json();
          })
          .then(function (out) {
            if (out && out.success && out.data.images) {
              out.data.images.forEach(function (img) {
                SG.uploadedImages.push(img);
              });
              renderUploadedGrid();
              updateUploadHint();
              // Update the tab count
              const uploadsTab = overlay.querySelector(
                '[data-modal-tab="uploads"]',
              );
              if (uploadsTab)
                uploadsTab.textContent =
                  "Your uploads (" + SG.uploadedImages.length + ")";
            }
            // Switch to uploads tab and show
            swapModalTab = "uploads";
            overlay.querySelectorAll("[data-modal-tab]").forEach(function (b) {
              b.classList.toggle(
                "is-active",
                b.getAttribute("data-modal-tab") === "uploads",
              );
            });
            searchBar.style.display = "none";
            footerEl.style.display = "none";
            renderModalUploads(overlay);
          })
          .catch(function () {
            grid.innerHTML =
              '<div class="aisb-sg-empty-state" style="grid-column:1/-1;">Upload failed.</div>';
          });
        modalUploadInput.value = "";
      });
    }

    // Search handler
    const input = overlay.querySelector(".aisb-sg-img-modal-input");
    overlay
      .querySelector("[data-modal-search]")
      .addEventListener("click", function () {
        swapModalKeyword = input.value.trim() || swapModalKeyword;
        swapModalPage = 1;
        loadModalImages(overlay);
      });
    input.addEventListener("keydown", function (e) {
      if (e.key === "Enter") {
        swapModalKeyword = input.value.trim() || swapModalKeyword;
        swapModalPage = 1;
        loadModalImages(overlay);
      }
    });

    // Pagination
    overlay
      .querySelector("[data-modal-prev]")
      .addEventListener("click", function () {
        if (swapModalPage > 1) {
          swapModalPage--;
          loadModalImages(overlay);
        }
      });
    overlay
      .querySelector("[data-modal-next]")
      .addEventListener("click", function () {
        swapModalPage++;
        loadModalImages(overlay);
      });

    // Initial load
    if (swapModalTab === "uploads") {
      footerEl.style.display = "none";
      renderModalUploads(overlay);
    } else {
      loadModalImages(overlay);
    }
  }

  /* Render uploaded images inside the swap modal */
  function renderModalUploads(overlay) {
    const grid = overlay.querySelector("[data-modal-grid]");
    if (!SG.uploadedImages || !SG.uploadedImages.length) {
      grid.innerHTML =
        '<div class="aisb-sg-empty-state" style="grid-column:1/-1;">No uploaded images yet. Use the + Upload button above.</div>';
      return;
    }
    grid.innerHTML = SG.uploadedImages
      .map(function (img, i) {
        return (
          '<div class="aisb-sg-img-modal-option" data-modal-pick-upload="' +
          i +
          '">' +
          '<img src="' +
          SG.escapeHtml(img.thumb) +
          '" alt="' +
          SG.escapeHtml(img.alt || "") +
          '" loading="lazy">' +
          '<div class="aisb-sg-auto-credit">Uploaded</div>' +
          "</div>"
        );
      })
      .join("");

    // Click to pick an uploaded image
    grid.querySelectorAll("[data-modal-pick-upload]").forEach(function (opt) {
      opt.addEventListener("click", function () {
        const pickIdx = parseInt(
          opt.getAttribute("data-modal-pick-upload"),
          10,
        );
        const picked = SG.uploadedImages[pickIdx];
        if (!picked) return;
        applyPickedImage(picked, overlay);
      });
    });
  }

  /* Apply a picked image (shared by both tabs) */
  function applyPickedImage(picked, overlay) {
    // Replace in guide.images
    SG.guide.images[swapModalIdx] = picked;

    // Update the card thumbnail in the Unsplash grid
    const card = autoImagesContainer.querySelector(
      '[data-image-index="' + swapModalIdx + '"]',
    );
    if (card) {
      const cardImg = card.querySelector("img");
      const cardCredit = card.querySelector(".aisb-sg-auto-credit");
      if (cardImg) {
        cardImg.src = picked.thumb;
        cardImg.alt = picked.alt || "";
      }
      if (cardCredit)
        cardCredit.textContent = picked.photographer || "Uploaded";
    }

    // Update iframes
    SG.applyImagesToAllIframes();

    // Close modal
    overlay.remove();
  }

  async function loadModalImages(overlay) {
    const grid = overlay.querySelector("[data-modal-grid]");
    const prevBtn = overlay.querySelector("[data-modal-prev]");
    const nextBtn = overlay.querySelector("[data-modal-next]");
    const pageInfo = overlay.querySelector("[data-modal-page-info]");

    grid.innerHTML =
      '<div class="aisb-sg-empty-state" style="grid-column:1/-1;">Loading…</div>';

    const out = await SG.post("aisb_search_similar_images", {
      keyword: swapModalKeyword,
      page: swapModalPage,
    });

    if (!out || !out.success || !out.data.images || !out.data.images.length) {
      grid.innerHTML =
        '<div class="aisb-sg-empty-state" style="grid-column:1/-1;">No images found.</div>';
      return;
    }

    const imgs = out.data.images;
    const totalPages = out.data.total_pages || 1;

    grid.innerHTML = imgs
      .map(function (img, i) {
        return (
          '<div class="aisb-sg-img-modal-option" data-modal-pick="' +
          i +
          '">' +
          '<img src="' +
          SG.escapeHtml(img.thumb) +
          '" alt="' +
          SG.escapeHtml(img.alt || "") +
          '" loading="lazy">' +
          '<div class="aisb-sg-auto-credit">' +
          SG.escapeHtml(img.photographer) +
          "</div>" +
          "</div>"
        );
      })
      .join("");

    prevBtn.disabled = swapModalPage <= 1;
    nextBtn.disabled = swapModalPage >= totalPages;
    pageInfo.textContent = "Page " + swapModalPage + " / " + totalPages;

    // Click to pick an image
    grid.querySelectorAll("[data-modal-pick]").forEach(function (opt) {
      opt.addEventListener("click", function () {
        const pickIdx = parseInt(opt.getAttribute("data-modal-pick"), 10);
        const picked = imgs[pickIdx];
        if (!picked) return;
        applyPickedImage(picked, overlay);
      });
    });
  }
})();
