(function () {
  var TEXT_TAGS = [
    "H1",
    "H2",
    "H3",
    "H4",
    "H5",
    "H6",
    "P",
    "SPAN",
    "A",
    "LI",
    "BUTTON",
    "LABEL",
    "BLOCKQUOTE",
    "TD",
    "TH",
    "FIGCAPTION",
    "LEGEND",
  ];
  var originalTexts = {};

  function reportHeight() {
    var wrap = document.getElementById("aisb-preview");
    var h = wrap
      ? wrap.getBoundingClientRect().height
      : document.body.scrollHeight;
    window.parent.postMessage({ type: "aisb_iframe_height", height: h }, "*");
  }
  window.addEventListener("load", reportHeight);
  if (window.ResizeObserver) {
    new ResizeObserver(reportHeight).observe(document.body);
  }
  setTimeout(reportHeight, 500);
  setTimeout(reportHeight, 2000);

  window.addEventListener("message", function (e) {
    if (!e.data || !e.data.type) return;

    if (e.data.type === "aisb_enable_edit") {
      document.body.classList.add("aisb-edit-mode");
      document.body.style.overflow = "auto";
      originalTexts = {};
      var wrap = document.getElementById("aisb-preview");
      var els = wrap.querySelectorAll(TEXT_TAGS.join(","));
      var editIdx = 0;
      els.forEach(function (el) {
        var text = (el.textContent || "").trim();
        if (
          text.length > 0 &&
          text.length < 2000 &&
          !el.querySelector(TEXT_TAGS.join(","))
        ) {
          el.setAttribute("contenteditable", "true");
          el.setAttribute("spellcheck", "false");
          el.setAttribute("data-aisb-edit-idx", editIdx);
          originalTexts[editIdx] = el.innerHTML;
          editIdx++;
        }
      });
      reportHeight();
    }

    if (e.data.type === "aisb_disable_edit") {
      document.body.classList.remove("aisb-edit-mode");
      document.body.style.overflow = "hidden";
      var editables = document.querySelectorAll('[contenteditable="true"]');
      editables.forEach(function (el) {
        el.removeAttribute("contenteditable");
        el.removeAttribute("spellcheck");
      });
      reportHeight();
    }

    if (e.data.type === "aisb_get_edited_content") {
      var changes = [];
      var editables = document.querySelectorAll("[data-aisb-edit-idx]");
      editables.forEach(function (el) {
        var idx = el.getAttribute("data-aisb-edit-idx");
        var current = el.innerHTML;
        var original = originalTexts[idx] || "";
        if (current !== original) {
          changes.push({
            original: original,
            updated: current,
          });
        }
      });
      window.parent.postMessage(
        {
          type: "aisb_edited_content",
          changes: changes,
        },
        "*",
      );
    }
  });
})();
