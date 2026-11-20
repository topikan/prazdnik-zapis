(function () {
  "use strict";
  // Если сайт открыли по http, переходим на https (хостинг за прокси не всегда делает это сам)
  if (location.protocol === "http:" && !/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)) { location.replace("https://" + location.host + location.pathname + location.search + location.hash); return; }
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function safeUrl(u, fallback) { return /^https:\/\//.test(u || "") ? u : fallback; }
  function getJson(url) { return fetch(url, { cache: "no-store" }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); }

  function render(d) {
    ["heroTitle", "heroLead", "demoButton", "contactButton", "itemsTitle", "stepsTitle", "faqTitle", "finalTitle", "finalDemoButton", "finalContactButton", "footer"].forEach(function (k) { if ($(k)) $(k).textContent = d[k] || ""; });
    var tg = safeUrl(d.telegram, "https://t.me/rodionfrontend");
    ["tgTop", "contactButton", "finalContactButton"].forEach(function (id) { $(id).href = tg; });
    $("footer").href = safeUrl(d.footerLink, "https://rodionfrontend.ru");
    var demo = /^[a-z0-9-]{2,40}$/.test(d.demoSlug || "") ? d.demoSlug : "demo";
    var demoHref = location.hostname.endsWith("github.io") ? "zapis.html?a=" + demo : demo;
    $("demoButton").href = demoHref; $("finalDemoButton").href = demoHref;
    $("items").innerHTML = (d.items || []).map(function (x) { return '<div class="panel l-item"><h3>' + esc(x.title) + "</h3><p>" + esc(x.text) + "</p></div>"; }).join("");
    $("steps").innerHTML = (d.steps || []).map(function (x) { return '<li class="panel"><b>' + esc(x.title) + "</b><span>" + esc(x.text) + "</span></li>"; }).join("");
    $("faq").innerHTML = (d.faq || []).map(function (x) { return "<details><summary>" + esc(x.q) + "</summary><p>" + esc(x.a) + "</p></details>"; }).join("");
  }

  // Сначала текст из админки (сервер), если его нет — тексты по умолчанию
  getJson("api/site.php").then(function (d) { if (!d || !d.heroTitle) throw new Error("empty"); return d; })
    .catch(function () { return getJson("assets/landing.json"); })
    .then(render);
})();
