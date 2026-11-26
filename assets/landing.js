(function () {
  "use strict";
  // Если сайт открыли по http, переходим на https (хостинг за прокси не всегда делает это сам)
  if (location.protocol === "http:" && !/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)) { location.replace("https://" + location.host + location.pathname + location.search + location.hash); return; }
  var $ = function (id) { return document.getElementById(id); };

  // Кнопка «Отключить анимацию»: выбор запоминается в этом браузере
  (function () {
    var KEY = "bp-no-anim", btn = document.getElementById("animToggle"), off = false;
    try { off = localStorage.getItem(KEY) === "1"; } catch (e) {}
    function apply() {
      document.documentElement.classList.toggle("no-anim", off);
      if (!btn) return;
      btn.setAttribute("aria-pressed", off ? "true" : "false");
      var t = off ? "Включить анимацию" : "Отключить анимацию";
      btn.querySelector("span").textContent = t; btn.title = t + " снега"; btn.setAttribute("aria-label", t);
    }
    apply();
    if (btn) btn.addEventListener("click", function () { off = !off; try { localStorage.setItem(KEY, off ? "1" : "0"); } catch (e) {} apply(); });
  })();
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function safeUrl(u, fallback) { return /^https:\/\/[^\s<>"]+$/.test(u || "") ? u : fallback; }
  function getJson(url) { return fetch(url, { cache: "no-store" }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); }
  function text(id, v) { if ($(id)) $(id).textContent = v || ""; }

  // v4: новый первый экран (пункты вместо подзаголовка), цена со скидкой в первые сутки, онлайн-предоплата
  var V5_KEYS = ["heroBadge", "points", "pricePaidText", "faq", "finalText", "steps"]; // v5: частные аниматоры
  var V4_KEYS = ["heroBadge", "heroTitle", "heroAccent", "heroLead", "ctaNote", "points", "pricePaidTitle", "pricePaidSum", "pricePaidText", "buyButton", "priceNote", "seatsLeft", "seatsTotal", "faq", "finalText"];
  var V3_KEYS = ["strip", "heroLead"]; // «Ни одной двойной брони» заменено на понятный текст
  var V2_KEYS = ["heroBadge", "heroTitle", "heroAccent", "heroLead", "regButton", "ctaNote", "demoButton", "contactButton", "strip", "itemsLead", "items", "steps", "faq", "finalText"];

  function render(d, defaults) {
    // Тексты, сохранённые до нового дизайна (без плашки над заголовком): берём из них только списки и ссылки
    if (defaults && d.heroBadge == null) d = { telegram: d.telegram, demoSlug: d.demoSlug, items: d.items, steps: d.steps, faq: d.faq };
    // Тексты, сохранённые до бесплатного периода и регистрации: первый экран, шаги и вопросы берём новые
    if (defaults && (+d.v || 0) < 2) V2_KEYS.forEach(function (k) { delete d[k]; });
    if (defaults && (+d.v || 0) < 3) V3_KEYS.forEach(function (k) { delete d[k]; });
    if (defaults && (+d.v || 0) < 4) V4_KEYS.forEach(function (k) { delete d[k]; });
    if (defaults && (+d.v || 0) < 5) V5_KEYS.forEach(function (k) { delete d[k]; });
    // поля, которых нет в сохранённой версии, берём из текстов по умолчанию
    Object.keys(defaults || {}).forEach(function (k) { if (d[k] == null || d[k] === "" || (Array.isArray(d[k]) && !d[k].length)) d[k] = defaults[k]; });
    ["heroBadge", "heroTitle", "heroAccent", "ctaNote", "stepsTitle", "priceTitle", "priceFreeTitle", "priceFreeText", "pricePaidTitle", "pricePaidSum", "pricePaidText", "priceNote", "faqTitle", "finalTitle", "finalText", "footer", "footerTagline"].forEach(function (k) { text(k, d[k]); });
    text("regButtonText", d.regButton);
    $("points").innerHTML = (d.points || []).slice(0, 5).map(function (x) { return "<li>" + esc(x.title) + "</li>"; }).join("");
    text("demoButtonText", d.demoButton); text("finalDemoText", d.finalDemoButton); text("finalContactText", d.finalContactButton);
    text("loginText", d.loginLink || "Вход для агентств");
    var tg = safeUrl(d.telegram, "https://t.me/rodionfrontend");
    ["tgFoot", "finalContactButton"].forEach(function (id) { if ($(id)) $(id).href = tg; });
    text("buyButtonText", d.buyButton || "Зарегистрироваться");
    var demo = /^[a-z0-9-]{2,40}$/.test(d.demoSlug || "") ? d.demoSlug : "demo";
    var demoHref = location.hostname.endsWith("github.io") ? "zapis.html?a=" + demo : demo;
    ["demoButton", "finalDemoButton"].forEach(function (id) { if ($(id)) $(id).href = demoHref; });
    document.querySelectorAll('a.shot-cta').forEach(function (a) { a.href = demoHref; });
    $("steps").innerHTML = (d.steps || []).map(function (x) { return "<li><b>" + esc(x.title) + "</b><span>" + esc(x.text) + "</span></li>"; }).join("");
    $("faq").innerHTML = (d.faq || []).map(function (x) { return "<details><summary>" + esc(x.q) + "</summary><p>" + esc(x.a) + "</p></details>"; }).join("");
  }

  // цены — из настроек сервиса, чтобы на главной всегда были актуальные
  getJson("api/service.php").then(function (s) {
    if (!s || !s.priceEarly) return;
    var rub = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " ₽"; };
    text("rateAgency", rub(s.priceEarly)); text("rateAgencyFull", "потом " + rub(s.priceFull));
    text("rateSolo", rub(s.soloEarly)); text("rateSoloFull", "потом " + rub(s.soloFull));
    text("whoAgency", rub(s.priceEarly)); text("whoSolo", rub(s.soloEarly));
  }).catch(function () {});
  // Сначала тексты из админки (сервер), недостающие — из текстов по умолчанию
  getJson("assets/landing.json").then(function (defaults) {
    return getJson("api/site.php").then(function (d) { if (!d || !d.heroTitle) throw new Error("empty"); render(d, defaults); })
      .catch(function () { render(JSON.parse(JSON.stringify(defaults)), null); });
  });
})();
