/**
 * Shared helper functions: status messages, AJAX posting.
 */
(function (app) {
  if (!app) return;

  app.setStatus = function (msg, kind) {
    if (!msg) {
      app.el.status.textContent = "";
      return;
    }
    const span = document.createElement("span");
    span.className = kind === "err" ? "aisb-error" : "aisb-ok";
    span.textContent = msg;
    app.el.status.replaceChildren(span);
  };

  function qs(obj) {
    return Object.keys(obj)
      .map((k) => `${encodeURIComponent(k)}=${encodeURIComponent(obj[k])}`)
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

  app.post = function (action, data) {
    return postWithNonce(action, data, AISB_WF.nonce);
  };

  app.postCore = function (action, data) {
    return postWithNonce(action, data, AISB_WF.coreNonce);
  };
})(window.AISB_WF_App);
