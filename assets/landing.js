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

  var STRIP_ICONS = ["i-clock", "i-shield", "i-tg"];
  var V2_KEYS = ["heroBadge", "heroTitle", "heroAccent", "heroLead", "regButton", "ctaNote", "demoButton", "contactButton", "strip", "itemsLead", "items", "steps", "faq", "finalText"];
  var FEAT_ICONS = ["i-calc", "i-check-cal", "i-inbox", "i-sliders"];

  function render(d, defaults) {
    // Тексты, сохранённые до нового дизайна (без плашки над заголовком): берём из них только списки и ссылки
    if (defaults && d.heroBadge == null) d = { telegram: d.telegram, demoSlug: d.demoSlug, items: d.items, steps: d.steps, faq: d.faq };
    // Тексты, сохранённые до бесплатного периода и регистрации: первый экран, шаги и вопросы берём новые
    if (defaults && (+d.v || 0) < 2) V2_KEYS.forEach(function (k) { delete d[k]; });
    // поля, которых нет в сохранённой версии, берём из текстов по умолчанию
    Object.keys(defaults || {}).forEach(function (k) { if (d[k] == null || d[k] === "" || (Array.isArray(d[k]) && !d[k].length)) d[k] = defaults[k]; });
    ["heroBadge", "heroTitle", "heroAccent", "heroLead", "ctaNote", "itemsTitle", "itemsLead", "stepsTitle", "priceTitle", "priceFreeTitle", "priceFreeText", "pricePaidTitle", "pricePaidSum", "pricePaidText", "priceNote", "faqTitle", "finalTitle", "finalText", "footer", "footerTagline"].forEach(function (k) { text(k, d[k]); });
    text("regButtonText", d.regButton);
    var left = parseInt(d.seatsLeft, 10), total = parseInt(d.seatsTotal, 10) || 5;
    if (left > 0) { $("priceSeats").hidden = false; $("priceSeats").textContent = "Осталось мест по этой цене: " + Math.min(left, total) + " из " + total; }
    text("demoButtonText", d.demoButton); text("finalDemoText", d.finalDemoButton); text("finalContactText", d.finalContactButton);
    text("loginText", d.loginLink || "Вход для агентств");
    var tg = safeUrl(d.telegram, "https://t.me/rodionfrontend");
    ["tgFoot", "finalContactButton"].forEach(function (id) { $(id).href = tg; });
    var demo = /^[a-z0-9-]{2,40}$/.test(d.demoSlug || "") ? d.demoSlug : "demo";
    var demoHref = location.hostname.endsWith("github.io") ? "zapis.html?a=" + demo : demo;
    ["demoButton", "finalDemoButton", "dcButton"].forEach(function (id) { $(id).href = demoHref; });
    $("strip").innerHTML = (d.strip || []).slice(0, 3).map(function (x, i) {
      return '<div><svg aria-hidden="true"><use href="#' + STRIP_ICONS[i % 3] + '"/></svg><span>' + esc(x.title) + "</span></div>";
    }).join("");
    $("items").innerHTML = (d.items || []).map(function (x, i) {
      return '<article class="f-card"><span class="f-ico"><svg aria-hidden="true"><use href="#' + FEAT_ICONS[i % FEAT_ICONS.length] + '"/></svg></span><h3>' + esc(x.title) + "</h3><p>" + esc(x.text) + "</p></article>";
    }).join("");
    $("steps").innerHTML = (d.steps || []).map(function (x) { return "<li><b>" + esc(x.title) + "</b><span>" + esc(x.text) + "</span></li>"; }).join("");
    $("faq").innerHTML = (d.faq || []).map(function (x) { return "<details><summary>" + esc(x.q) + "</summary><p>" + esc(x.a) + "</p></details>"; }).join("");
  }

  /* ---------- скриншоты панели с подписями ---------- */
  var SHOTS = [
    { tab: "Заявки", img: "orders", pins: [
      [3.6, 24, "Все заявки в одном списке: дата, время, состав, допы и сумма"],
      [50.3, 27.2, "Телефон и адрес клиента сразу, без переписки"],
      [77.2, 29.2, "Статус меняется в одно нажатие"],
      [77.2, 57.1, "Отметка, что предоплата получена"],
      [73.6, 15.6, "Все заявки таблицей для Excel"]] },
    { tab: "Расписание", img: "schedule", pins: [
      [3.6, 47.7, "Загрузка по часам: сколько выездов свободно"],
      [33.4, 24.7, "Закрыть час или весь день, если вы заняты"],
      [66.4, 24, "Заказ по телефону: время сразу закрывается на странице"],
      [97.2, 53, "Закрытое время возвращается одной кнопкой"]] },
    { tab: "Цены и услуги", img: "prices", pins: [
      [3.6, 29.3, "Свои составы с фото"],
      [33.3, 23.7, "Названия и подписи — какие нужно"],
      [80.2, 23.7, "Плашка «Чаще всего выбирают» на нужном составе"],
      [3.6, 74.3, "Программы и их длительность"]] }
  ];
  var shotCur = 0;
  function drawShot() {
    var s = SHOTS[shotCur];
    $("shotTabs").innerHTML = SHOTS.map(function (x, i) {
      return '<button type="button" role="tab" data-shot="' + i + '" aria-selected="' + (i === shotCur) + '">' + esc(x.tab) + "</button>";
    }).join("");
    $("shotImg").src = "assets/img/panel/" + s.img + ".webp";
    $("shotImg").alt = "Панель управления, раздел «" + s.tab + "»";
    // Положение задаём через style из скрипта: атрибут style в разметке запрещён политикой безопасности страницы
    var pins = $("shotPins"); pins.textContent = "";
    s.pins.forEach(function (p, i) { var el = document.createElement("span"); el.className = "pin"; el.textContent = i + 1; el.style.left = p[0] + "%"; el.style.top = p[1] + "%"; pins.appendChild(el); });
    $("shotNotes").innerHTML = s.pins.map(function (p) { return "<li>" + esc(p[2]) + "</li>"; }).join("");
  }
  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-shot]") : null;
    if (b) { shotCur = +b.dataset.shot; drawShot(); }
  });
  if ($("shotTabs")) drawShot();

  /* ---------- живая витрина: можно потрогать календарь и время ---------- */
  var MONTH = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
  var demo = { day: 5, time: "12:30" };
  var BUSY = [1, 2, 3, 9, 16, 22, 23, 30];
  function priceFor(day) { return day >= 31 ? 9000 : day >= 26 ? 6500 : day >= 19 ? 5500 : 4500; }
  function fmt(n) { return n.toLocaleString("ru-RU") + " ₽"; }
  function drawDemo() {
    var html = "<span></span>"; // 1 декабря 2026 — вторник
    for (var d = 1; d <= 31; d++) {
      var busy = BUSY.indexOf(d) > -1;
      var weekend = [5, 6, 12, 13, 19, 20, 26, 27].indexOf(d) > -1;
      html += '<button type="button" data-d="' + d + '" class="' + (busy ? "busy" : (d === demo.day ? "on" : (weekend ? "free" : ""))) + '"' + (busy ? ' disabled aria-label="' + d + ' декабря, всё занято"' : ' aria-label="' + d + ' декабря"') + ">" + d + "</button>";
    }
    $("dcDays").innerHTML = html;
    $("dcTimes").innerHTML = ["10:00", "12:30", "15:00", "18:00"].map(function (t) {
      return '<button type="button" data-t="' + t + '" class="' + (t === demo.time ? "on" : "") + '">' + t + "</button>";
    }).join("");
    $("dcWhen").textContent = demo.day + " " + MONTH[11] + ", " + demo.time;
    $("dcTotal").textContent = fmt(priceFor(demo.day));
  }
  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("#dcDays button, #dcTimes button") : null;
    if (!b || b.disabled) return;
    if (b.dataset.d) demo.day = +b.dataset.d; else demo.time = b.dataset.t;
    drawDemo();
  });
  drawDemo();

  // Сначала тексты из админки (сервер), недостающие — из текстов по умолчанию
  getJson("assets/landing.json").then(function (defaults) {
    return getJson("api/site.php").then(function (d) { if (!d || !d.heroTitle) throw new Error("empty"); render(d, defaults); })
      .catch(function () { render(JSON.parse(JSON.stringify(defaults)), null); });
  });
})();
