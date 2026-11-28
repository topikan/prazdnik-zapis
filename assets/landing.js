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
  var V7_KEYS = ["heroTitle", "heroAccent", "points", "ctaNote", "priceFreeTitle", "priceFreeText", "pricePaidTitle", "pricePaidText", "faq", "finalTitle", "finalText"]; // v7: круглый год, помесячная оплата
  var V6_KEYS = ["priceFreeText", "pricePaidText", "buyButton"]; // v6: единый блок тарифов
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
    if (defaults && (+d.v || 0) < 6) V6_KEYS.forEach(function (k) { delete d[k]; });
    if (defaults && (+d.v || 0) < 7) V7_KEYS.forEach(function (k) { delete d[k]; });
    // поля, которых нет в сохранённой версии, берём из текстов по умолчанию
    Object.keys(defaults || {}).forEach(function (k) { if (d[k] == null || d[k] === "" || (Array.isArray(d[k]) && !d[k].length)) d[k] = defaults[k]; });
    ["heroBadge", "heroTitle", "heroAccent", "ctaNote", "stepsTitle", "priceTitle", "priceFreeTitle", "priceFreeText", "pricePaidTitle", "pricePaidSum", "pricePaidText", "priceNote", "faqTitle", "finalTitle", "finalText", "footer", "footerTagline"].forEach(function (k) { text(k, d[k]); });
    text("regButtonText", d.regButton);
    $("points").innerHTML = (d.points || []).slice(0, 5).map(function (x) { return "<li>" + esc(x.title) + "</li>"; }).join("");
    text("demoButtonText", d.demoButton); text("finalDemoText", d.finalDemoButton); text("finalContactText", d.finalContactButton);
    text("loginText", d.loginLink || "Вход для агентств");
    var tg = safeUrl(d.telegram, "https://t.me/rodionfrontend");
    ["tgFoot", "finalContactButton"].forEach(function (id) { if ($(id)) $(id).href = tg; });
    text("buyButtonText", d.buyButton || "Подключить агентство");
    var demo = /^[a-z0-9-]{2,40}$/.test(d.demoSlug || "") ? d.demoSlug : "demo";
    var demoHref = location.hostname.endsWith("github.io") ? "zapis.html?a=" + demo : demo;
    ["demoButton", "finalDemoButton"].forEach(function (id) { if ($(id)) $(id).href = demoHref; });
    document.querySelectorAll('a.shot-cta').forEach(function (a) { a.href = demoHref; });
    $("steps").innerHTML = (d.steps || []).map(function (x) { return "<li><b>" + esc(x.title) + "</b><span>" + esc(x.text) + "</span></li>"; }).join("");
    $("faq").innerHTML = (d.faq || []).map(function (x) { return "<details><summary>" + esc(x.q) + "</summary><p>" + esc(x.a) + "</p></details>"; }).join("");
  }

  // цены и даты — из настроек сервиса, чтобы на главной всегда были актуальные
  // метки в текстах главной: даты и цены из «Настроек сервиса»
  var dates = { "[бесплатно до]": "15 декабря", "[конец сезона]": "10 января", "[бесплатно по]": "5 декабря", "[дней бесплатно]": "14",
    "[цена месяц]": "1 500 ₽", "[цена месяц частник]": "700 ₽", "[цена сезон]": "5 000 ₽", "[цена сезон частник]": "2 000 ₽", "[цена сезон скидка]": "3 000 ₽", "[цена сезон скидка частник]": "1 000 ₽" };
  function sub(v) {
    if (typeof v === "string") return v.replace(/\[[^\]]{3,40}\]/g, function (m) { return dates[m] == null ? m : dates[m]; });
    if (Array.isArray(v)) return v.map(sub);
    if (v && typeof v === "object") { var o = {}; Object.keys(v).forEach(function (k) { o[k] = sub(v[k]); }); return o; }
    return v;
  }
  function applyService(s) {
    if (!s || !s.priceEarly) return;
    if (s.freeText) dates["[бесплатно до]"] = s.freeText;
    if (s.seasonText) { dates["[конец сезона]"] = s.seasonText; document.querySelectorAll(".js-season-end").forEach(function (x) { x.textContent = s.seasonText; }); }
    var rub = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " ₽"; };
    if (s.freeUntilText) dates["[бесплатно по]"] = s.freeUntilText;
    if (s.trialDays) dates["[дней бесплатно]"] = String(s.trialDays);
    if (s.priceMonth) { dates["[цена месяц]"] = rub(s.priceMonth); dates["[цена месяц частник]"] = rub(s.soloMonth); }
    dates["[цена сезон]"] = rub(s.priceFull); dates["[цена сезон частник]"] = rub(s.soloFull); dates["[цена сезон скидка]"] = rub(s.priceEarly); dates["[цена сезон скидка частник]"] = rub(s.soloEarly);
    if (s.priceMonth) { text("rateAgency", rub(s.priceMonth)); text("rateSolo", rub(s.soloMonth)); }
    text("rateAgencyFull", "Новогодний сезон — " + rub(s.priceFull) + ", или " + rub(s.priceEarly) + ", если оплатить в первые " + s.earlyHours + " ч");
    text("rateSoloFull", "Новогодний сезон — " + rub(s.soloFull) + ", или " + rub(s.soloEarly) + ", если оплатить в первые " + s.earlyHours + " ч");
  }
  // Прелоадер — только если тексты грузятся дольше полсекунды
  var slowTimer = setTimeout(function () { document.body.classList.add("is-slow"); }, 500);
  function loaded() { clearTimeout(slowTimer); document.body.classList.remove("is-loading", "is-slow"); }
  // Сначала тексты из админки (сервер), недостающие — из текстов по умолчанию
  // сначала даты из настроек, потом тексты: метки [бесплатно до] и [конец сезона] заменяются датами
  var service = getJson("api/service.php").then(applyService).catch(function () {});
  getJson("assets/landing.json").then(function (defaults) {
    return service.then(function () { return getJson("api/site.php"); }).then(function (d) { if (!d || !d.heroTitle) throw new Error("empty"); render(sub(d), sub(defaults)); })
      .catch(function () { render(sub(JSON.parse(JSON.stringify(defaults))), null); });
  }).catch(function (e) { if (window.console) console.error(e); }).then(loaded);
})();
