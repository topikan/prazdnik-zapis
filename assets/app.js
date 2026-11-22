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
  var MONTHS = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];
  var MONTHS_GEN = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
  var DOW_FULL = ["воскресенье", "понедельник", "вторник", "среда", "четверг", "пятница", "суббота"];
  var WHO_ART = { santa: ["i-santa"], duo: ["i-santa", "i-maiden"], trio: ["i-santa", "i-maiden", "i-snowman"] };
  var PROG_ICON = { gift: "i-giftbig", tree: "i-tree", ball: "i-ball" };
  // Библиотека иконок для доп. услуг: все в одном линейном стиле
  var ADDON_ICONS = ["sack", "camera", "letter", "bubbles", "candy", "cake", "balloon", "music", "snowflake", "car", "star", "video", "gift"];

  var cfg = null;
  var slug = "demo";
  var q = new URLSearchParams(location.search).get("a");
  var state = { step: 1, character: null, program: null, kids: 1, date: null, time: null, addons: {}, zone: null, month: 0, children: [{ name: "", age: "" }] };
  var PHOTO_RE = /^uploads\/chars\/[a-z0-9-]+\.(webp|png|jpe?g)$/;
  var months = [];

  /* ---------- утилиты ---------- */
  function rub(n) { return Math.round(n).toLocaleString("ru-RU") + " ₽"; }
  function round100(n) { return Math.round(n / 100) * 100; }
  function pad(n) { return (n < 10 ? "0" : "") + n; }
  function iso(d) { return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()); }
  function parse(s) { var p = s.split("-"); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function minutes(t) { var p = t.split(":"); return +p[0] * 60 + +p[1]; }
  function hhmm(m) { return pad(Math.floor(m / 60) % 24) + ":" + pad(m % 60); }
  function human(s) { var d = parse(s); return d.getDate() + " " + MONTHS_GEN[d.getMonth()] + ", " + DOW_FULL[d.getDay()]; }
  function short(s) { var d = parse(s); return d.getDate() + " " + MONTHS_GEN[d.getMonth()]; }
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function icon(id) { return '<svg viewBox="0 0 64 64" aria-hidden="true"><use href="#' + id + '"/></svg>'; }
  function find(list, id) { for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return null; }
  function plural(n, a, b, c) { var n10 = n % 10, n100 = n % 100; if (n10 === 1 && n100 !== 11) return a; if (n10 >= 2 && n10 <= 4 && (n100 < 10 || n100 >= 20)) return b; return c; }
  function hash(str) { var h = 2166136261; for (var i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619); } h ^= h >>> 16; h = Math.imul(h, 0x85ebca6b); h ^= h >>> 13; h = Math.imul(h, 0xc2b2ae35); h ^= h >>> 16; return (h >>> 0) / 4294967295; }
  // «Сейчас» по часовому поясу агентства, а не по часам телефона клиента
  function agencyNow() {
    var d = new Date(), tz = cfg && cfg.timezone || "Europe/Moscow";
    try {
      var p = {}; new Intl.DateTimeFormat("en-GB", { timeZone: tz, year: "numeric", month: "2-digit", day: "2-digit", hour: "2-digit", minute: "2-digit", hourCycle: "h23" })
        .formatToParts(d).forEach(function (x) { p[x.type] = x.value; });
      return { date: p.year + "-" + p.month + "-" + p.day, min: (+p.hour % 24) * 60 + +p.minute };
    } catch (e) { return { date: iso(d), min: d.getHours() * 60 + d.getMinutes() }; }
  }
  function today() { return parse(agencyNow().date); }

  /* ---------- цены ---------- */
  function dateRule(date) {
    for (var i = 0; i < cfg.dateRules.length; i++) { var r = cfg.dateRules[i]; if (date >= r.from && date <= r.to) return r; }
    return { mult: 1, label: "" };
  }
  function multFor(date, time) {
    var r = dateRule(date), m = r.mult, label = r.label;
    if (time) (cfg.timeRules || []).forEach(function (t) {
      if (t.date === date && minutes(time) >= minutes(t.after) && t.mult > m) { m = t.mult; label = t.label; }
    });
    return { mult: m, label: label };
  }
  function promoActive() { return cfg.promo && cfg.promo.percent && iso(today()) <= cfg.promo.until; }

  // Наценка за спрос: чем меньше свободных составов (пар, трио) на это время, тем дороже
  function surgeFor(free) {
    var share = free / cfg.pairs, best = { plus: 0, label: "" };
    (cfg.demandPricing || []).forEach(function (r) { if (share <= r.freeShareAtMost && r.plus > best.plus) best = r; });
    return best;
  }
  function slotOf(date, time) { var l = slotsFor(date); for (var i = 0; i < l.length; i++) if (l[i].time === time) return l[i]; return null; }
  // Загрузка дня: доля занятых составов по всем часам
  function dayLoad(date) {
    var l = slotsFor(date), busy = 0;
    l.forEach(function (s) { busy += cfg.pairs - s.free; });
    return busy / (l.length * cfg.pairs);
  }
  function loadLevel(load) { return load < 0.4 ? 1 : load < 0.7 ? 2 : 3; }

  function calc(date, time) {
    var base = cfg.prices[state.character] && cfg.prices[state.character][state.program];
    if (base == null) return null;
    var m = date ? multFor(date, time) : { mult: 1, label: "" };
    var prog = find(cfg.programs, state.program);
    var lines = [];
    var program = round100(base * m.mult);
    lines.push([find(cfg.characters, state.character).title + ", " + prog.minutes + " мин" + (m.mult !== 1 ? " (" + m.label.toLowerCase() + ")" : ""), program]);
    var surge = { plus: 0, label: "" };
    if (date && time) { var sl = slotOf(date, time); if (sl) surge = surgeFor(sl.free); }
    else if (date) { // без выбранного времени показываем цену самого дешёвого свободного часа
      var min = null; slotsFor(date).forEach(function (sl) { if (sl.free > 0) { var g = surgeFor(sl.free); if (!min || g.plus < min.plus) min = g; } });
      if (min) surge = min;
    }
    if (surge.plus) lines.push(["Наценка за спрос +" + Math.round(surge.plus * 100) + "%", round100(program * surge.plus)]);
    var extra = Math.max(0, state.kids - cfg.kids.included);
    if (extra) lines.push(["Ещё " + extra + " " + plural(extra, "ребёнок", "ребёнка", "детей") + " сверх " + cfg.kids.included, extra * cfg.kids.extraPrice]);
    if (promoActive()) lines.push(["Скидка за раннее бронирование −" + cfg.promo.percent + "%", -round100(program * cfg.promo.percent / 100)]);
    cfg.addons.forEach(function (a) { if (state.addons[a.id]) lines.push([a.title, a.price]); });
    var z = find(cfg.zones, state.zone);
    if (z && z.price) lines.push(["Выезд: " + z.title.toLowerCase(), z.price]);
    return { total: lines.reduce(function (s, l) { return s + l[1]; }, 0), lines: lines, mult: m.mult, label: m.label, surge: surge };
  }

  /* ---------- слоты (в демо занятость условная) ---------- */
  function freeAt(date, time) {
    if (cfg.occupancy) return Math.max(0, cfg.pairs - ((cfg.occupancy[date] || {})[time] || 0));
    var m = multFor(date, time).mult, p = Math.min(0.92, 0.1 + 0.32 * (m - 1)), free = 0; // демо: условная занятость
    for (var k = 0; k < cfg.pairs; k++) if (hash(slug + date + time + k) > p) free++;
    return free;
  }
  function span() {
    var prog = find(cfg.programs, state.program);
    return Math.max(1, Math.ceil(((prog ? prog.minutes : 30) + (cfg.travelMinutes == null ? 30 : +cfg.travelMinutes)) / 60));
  }
  function slotsFor(date) {
    var from = minutes(cfg.hours.from), to = minutes(cfg.hours.to);
    if (cfg.hours.lastDay && cfg.hours.lastDay.date === date) to = minutes(cfg.hours.lastDay.to);
    var list = [], sp = span();
    // сегодня показываем только часы, до которых осталось не меньше leadHours
    var lead = cfg.leadHours == null ? 3 : +cfg.leadHours, now = agencyNow(), earliest = -1;
    if (date < now.date) return [];
    // минуты от начала этого дня, раньше которых записаться уже нельзя (работает и для запаса больше суток)
    earliest = now.min + lead * 60 - Math.round((parse(date) - parse(now.date)) / 864e5) * 1440;
    for (var t = from; t <= to; t += 60) {
      if (t < earliest) continue;
      var free = freeAt(date, hhmm(t));
      // визит вместе с дорогой может занять несколько часов: все они должны быть свободны
      for (var i = 1; i < sp && free > 0; i++) if (t + i * 60 <= to && freeAt(date, hhmm(t + i * 60)) < 1) free = 0;
      list.push({ time: hhmm(t), end: hhmm(t + 60), free: free });
    }
    return list;
  }
  function dayFree(date) { return slotsFor(date).some(function (s) { return s.free > 0; }); }

  /* ---------- отрисовка ---------- */
  function renderStatic() {
    document.title = "Дед Мороз на дом — " + cfg.name;
    document.querySelectorAll(".js-name").forEach(function (n) { n.textContent = cfg.name; });
    if (cfg.phone) document.querySelectorAll(".js-phone").forEach(function (a) { a.querySelector("span").textContent = cfg.phone; a.href = "tel:" + cfg.phone.replace(/[^\d+]/g, ""); a.hidden = false; });
    // свой логотип агентства вместо снежинки
    if (/^uploads\/logos\/[a-z0-9-]+\.(webp|png|jpe?g)$/.test(cfg.logo || "")) document.querySelectorAll(".logo-mark").forEach(function (svg) {
      var img = document.createElement("img"); img.className = "logo-img"; img.src = cfg.logo; img.alt = ""; svg.replaceWith(img);
    });
    // своя картинка первого экрана; наш Дед Мороз тогда не нужен
    if (/^uploads\/heroes\/[a-z0-9-]+\.(webp|png|jpe?g)$/.test(cfg.heroImage || "")) {
      var hero = $("hero"); hero.classList.add("custom"); hero.style.backgroundImage = "url(" + cfg.heroImage + ")";
    }
    $("demoNote").hidden = !cfg.demo;
    var qs = "?a=" + encodeURIComponent(slug);
    var policy = cfg.legal && /^https:\/\//.test(cfg.legal.policyUrl || "") ? cfg.legal.policyUrl : "privacy" + qs;
    $("consentLink").href = "consent" + qs; $("privacyLink").href = policy;
    document.querySelectorAll(".js-consent").forEach(function (a) { a.href = "consent" + qs; });
    document.querySelectorAll(".js-privacy").forEach(function (a) { a.href = policy; });
    renderBanner();
    renderPortfolio();
    renderGuide();
  }
  // Баннер внизу: текст, кнопку и ссылку агентство задаёт в кабинете; без заголовка баннера нет
  function renderBanner() {
    // в демо баннер ведёт на главную сервиса; у агентств — их текст и ссылка (или баннера нет)
    var b = cfg.banner || (cfg.demo ? { title: "Хотите такую запись для своего агентства?", text: "Родители сами выбирают программу, дату и время, а вам приходит готовая заявка.", button: "Узнать о сервисе", link: "home" } : {});
    var title = b.title == null ? "Подарите детям настоящий Новый год!" : b.title;
    $("banner").hidden = !title;
    if (!title) return;
    $("bannerTitle").textContent = title;
    $("bannerText").textContent = b.text == null ? "Забронируйте визит Деда Мороза и создайте волшебство в каждом доме." : b.text;
    $("bannerBtnText").textContent = b.button || "Забронировать праздник";
    var link = b.link || "";
    var btn = $("bannerBtn");
    if (link === "home") { btn.href = location.hostname.endsWith("github.io") ? "index.html" : "./"; btn.dataset.top = ""; btn.removeAttribute("target"); }
    else if (/^(https:\/\/|tel:)/.test(link)) { btn.href = link; btn.target = /^https/.test(link) ? "_blank" : ""; btn.rel = "noopener noreferrer"; btn.dataset.top = ""; }
    else { btn.href = "#hero"; btn.dataset.top = "1"; btn.removeAttribute("target"); }
  }
  /* ---------- портфолио: фото и видео агентства, просмотр на весь экран ---------- */
  var PF_PHOTO = /^uploads\/portfolio\/[a-z0-9-]+\.(webp|png|jpe?g)$/, PF_VIDEO = /^uploads\/videos\/[a-z0-9-]+\.(mp4|webm)$/;
  var PF_EMBED = /^https:\/\/(rutube\.ru\/play\/embed\/[a-f0-9]{32}(\?p=[A-Za-z0-9_-]{1,64})?|vk\.com\/video_ext\.php\?oid=-?\d{1,20}&id=\d{1,20}(&hash=[a-f0-9]{1,40})?)$/;
  var pfItems = [], lbIndex = 0;
  function renderPortfolio() {
    var pf = cfg.portfolio || {};
    pfItems = (pf.items || []).filter(function (it) {
      return (it.t === "photo" && PF_PHOTO.test(it.src)) || (it.t === "video" && PF_VIDEO.test(it.src)) || (it.t === "embed" && PF_EMBED.test(it.src));
    });
    // в демо показываем примерные фото, чтобы было видно, как выглядит блок
    if (cfg.demo && !pfItems.length) pfItems = ["santa", "duo", "trio"].map(function (k) { return { t: "photo", src: "assets/img/demo/" + k + ".webp" }; });
    var on = cfg.demo || (pf.enabled && pfItems.length);
    $("portfolio").hidden = !on;
    if (!on) return;
    $("pfTitle").textContent = pf.title || "Наши праздники";
    // сетка подстраивается под количество: 1–4 в ряд; при 5 и больше первое фото крупнее
    $("pfGrid").dataset.n = Math.min(pfItems.length, 4);
    $("pfGrid").classList.toggle("pf-big", pfItems.length >= 5);
    $("pfGrid").innerHTML = pfItems.map(function (it, i) {
      var inner = it.t === "photo" ? '<img src="' + esc(it.src) + '" alt="" loading="lazy">'
        : it.t === "video" ? '<video src="' + esc(it.src) + '#t=0.5" preload="metadata" muted playsinline></video><span class="pf-play"><svg aria-hidden="true"><use href="#i-play"/></svg></span>'
        : '<span class="pf-embed">' + (/rutube/.test(it.src) ? "Rutube" : "VK Видео") + '</span><span class="pf-play"><svg aria-hidden="true"><use href="#i-play"/></svg></span>';
      return '<button type="button" class="pf-item pf-' + it.t + '" data-pf="' + i + '" aria-label="' + (it.t === "photo" ? "Открыть фото " : "Смотреть видео ") + (i + 1) + '">' + inner + "</button>";
    }).join("");
  }
  function lbShow(i) {
    lbIndex = (i + pfItems.length) % pfItems.length;
    var it = pfItems[lbIndex], st = $("lbStage");
    st.innerHTML = "";
    var el;
    if (it.t === "photo") { el = document.createElement("img"); el.src = it.src; el.alt = ""; }
    else if (it.t === "video") { el = document.createElement("video"); el.src = it.src; el.controls = true; el.autoplay = true; el.playsInline = true; }
    else { el = document.createElement("iframe"); el.src = it.src; el.allow = "autoplay; fullscreen; encrypted-media; picture-in-picture"; el.setAttribute("allowfullscreen", ""); el.title = "Видео"; }
    st.appendChild(el);
    $("lbCount").textContent = pfItems.length > 1 ? (lbIndex + 1) + " из " + pfItems.length : "";
    $("lbPrev").hidden = $("lbNext").hidden = pfItems.length < 2;
  }
  function lbOpen(i) { lbShow(i); $("lb").hidden = false; document.body.classList.add("lb-open"); $("lbClose").focus(); }
  function lbClose() { $("lb").hidden = true; $("lbStage").innerHTML = ""; document.body.classList.remove("lb-open"); var b = document.querySelector('[data-pf="' + lbIndex + '"]'); if (b) b.focus(); }
  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-pf]") : null;
    if (b) { lbOpen(+b.dataset.pf); return; }
    if (e.target.id === "lbClose" || e.target.id === "lb") lbClose();
    if (e.target.id === "lbPrev") lbShow(lbIndex - 1);
    if (e.target.id === "lbNext") lbShow(lbIndex + 1);
  });
  document.addEventListener("keydown", function (e) {
    if ($("lb").hidden) return;
    if (e.key === "Escape") lbClose();
    if (e.key === "ArrowLeft") lbShow(lbIndex - 1);
    if (e.key === "ArrowRight") lbShow(lbIndex + 1);
  });
  (function () { // листание пальцем
    var x0 = null;
    $("lb").addEventListener("touchstart", function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    $("lb").addEventListener("touchend", function (e) { if (x0 == null) return; var dx = e.changedTouches[0].clientX - x0; x0 = null; if (Math.abs(dx) > 50 && pfItems.length > 1) lbShow(lbIndex + (dx < 0 ? 1 : -1)); });
  })();

  /* ---------- пояснения в демо: что агентство может настроить ---------- */
  var NOTES = [
    ["#hero h1", "Фон, логотип и название — ваши"],
    ["#characters", "Составы, фото и подписи меняете сами"],
    ["#programs", "Программы, длительность и цены — из вашего прайса"],
    ["#step-2 .date-grid", "Занятое время скрывается само: учитываются заявки и заказы по телефону"],
    ["#addons", "Допы и районы выезда с вашими ценами"],
    ["#step-4 .contact-grid", "Заявка придёт вам в Telegram и на почту, контакты клиента — в панели"],
    ["#summary h2", "Цена считается по вашему прайсу, с наценками на праздничные даты"],
    ["#portfolio .pf-title", "Фото и видео ваших аниматоров. Блок можно выключить"],
    ["#banner .banner-text h2", "Баннер: свой текст, кнопка и ссылка. Можно выключить"]
  ];
  function renderGuide() {
    var bar = $("guideBar"), tog = $("notesToggle");
    if (cfg.preview) { // агентство смотрит свою выключенную или закрытую страницу
      bar.hidden = false; bar.classList.add("is-preview"); document.body.classList.add("has-guide");
      $("guideText").textContent = cfg.preview === "off" ? "Предпросмотр: страница выключена и не видна клиентам" : "Предпросмотр: запись закрыта до оплаты, клиенты видят только ваш телефон";
      $("guideSub").textContent = "Эту плашку видите только вы";
      return;
    }
    if (!cfg.demo) return;
    bar.hidden = false; tog.hidden = false; document.body.classList.add("has-guide");
    NOTES.forEach(function (n) {
      var t = document.querySelector(n[0]); if (!t) return;
      var tip = document.createElement("p"); tip.className = "note-tip"; tip.textContent = n[1];
      t.parentNode.insertBefore(tip, t);
    });
    var KEY = "bp-no-notes", off = false;
    try { off = localStorage.getItem(KEY) === "1"; } catch (e) {}
    function apply() {
      document.documentElement.classList.toggle("no-notes", off);
      tog.setAttribute("aria-pressed", off ? "true" : "false");
      tog.textContent = off ? "Показать пояснения" : "Отключить пояснения";
    }
    apply();
    tog.addEventListener("click", function () { off = !off; try { localStorage.setItem(KEY, off ? "1" : "0"); } catch (e) {} apply(); });
  }

  // Запись закрыта до оплаты: только название, оформление и телефон
  function initClosed(data) {
    cfg = data;
    document.title = cfg.name || "Онлайн-запись";
    document.querySelectorAll(".js-name").forEach(function (n) { n.textContent = cfg.name || ""; });
    if (cfg.phone) document.querySelectorAll(".js-phone").forEach(function (a) { a.querySelector("span").textContent = cfg.phone; a.href = "tel:" + cfg.phone.replace(/[^\d+]/g, ""); a.hidden = false; });
    if (/^uploads\/logos\/[a-z0-9-]+\.(webp|png|jpe?g)$/.test(cfg.logo || "")) document.querySelectorAll(".logo-mark").forEach(function (svg) { var img = document.createElement("img"); img.className = "logo-img"; img.src = cfg.logo; img.alt = ""; svg.replaceWith(img); });
    if (/^uploads\/heroes\/[a-z0-9-]+\.(webp|png|jpe?g)$/.test(cfg.heroImage || "")) { var hero = $("hero"); hero.classList.add("custom"); hero.style.backgroundImage = "url(" + cfg.heroImage + ")"; }
    var qs = "?a=" + encodeURIComponent(slug);
    document.querySelectorAll(".js-consent").forEach(function (a) { a.href = "consent" + qs; });
    document.querySelectorAll(".js-privacy").forEach(function (a) { a.href = "privacy" + qs; });
    $("app").hidden = true; $("closed").hidden = false;
    document.querySelector("#hero .lead").textContent = "Онлайн-запись временно недоступна. Позвоните нам, чтобы заказать праздник.";
  }

  // В демо вместо иконок — примерные фото; у агентств без своих фото остаются иконки
  var DEMO_PHOTOS = { santa: "assets/img/demo/santa.webp", duo: "assets/img/demo/duo.webp", trio: "assets/img/demo/trio.webp" };
  function photoOf(c) { return PHOTO_RE.test(c.photo || "") ? c.photo : (cfg.demo && DEMO_PHOTOS[c.icon]) || ""; }
  function whoArt(c) {
    var ph = photoOf(c), badge = c.badge ? '<span class="badge">' + esc(c.badge) + "</span>" : "";
    if (ph) return '<span class="who-art photo"><img src="' + esc(ph) + '" alt="" loading="lazy">' + badge + "</span>";
    return '<span class="who-art">' + (WHO_ART[c.icon] || WHO_ART.santa).map(icon).join("") + badge + "</span>";
  }
  var CHECK = '<span class="check" aria-hidden="true"><svg><use href="#i-check"/></svg></span>';

  function renderCharacters() {
    var cheapest = cfg.programs[0].id;
    $("characters").innerHTML = cfg.characters.map(function (c) {
      return '<label class="card"><input type="radio" name="character" value="' + esc(c.id) + '"' + (state.character === c.id ? " checked" : "") + ">" +
        '<span class="card-body">' + CHECK + whoArt(c) +
        '<span class="card-text"><span class="card-title">' + esc(c.title) + '</span><span class="card-note">' + esc(c.note) + '</span><span class="card-price">от ' + rub(cfg.prices[c.id][cheapest]) + "</span></span></span></label>";
    }).join("");
  }
  function renderPrograms() {
    $("programs").innerHTML = cfg.programs.map(function (p) {
      return '<label class="card"><input type="radio" name="program" value="' + esc(p.id) + '"' + (state.program === p.id ? " checked" : "") + ">" +
        '<span class="card-body">' + CHECK + '<span class="prog-head"><svg class="prog-icon" viewBox="0 0 48 48" aria-hidden="true"><use href="#' + (PROG_ICON[p.icon] || "i-giftbig") + '"/></svg></span>' +
        '<span class="card-text"><span class="card-title">' + esc(p.title) + '</span><span class="card-note">' + esc(p.note) + "</span>" +
        '<span class="prog-min"><svg aria-hidden="true"><use href="#i-clock"/></svg>' + esc(p.minutes) + ' мин</span><span class="card-price">' + rub(cfg.prices[state.character][p.id]) + "</span></span></span></label>";
    }).join("");
  }
  function renderKids() {
    $("kidsValue").textContent = state.kids;
    var last = state.children[state.children.length - 1];
    var lastFilled = last && (last.name.trim() || String(last.age).trim());
    // Нельзя сделать детей меньше, чем вписано имён: сначала убрать ребёнка крестиком
    $("kidsMinus").disabled = state.kids <= 1 || (state.kids <= state.children.length && lastFilled);
    $("kidsPlus").disabled = state.kids >= cfg.kids.max;
    $("kidsNote").textContent = cfg.kids.extraPrice > 0 && cfg.kids.included < cfg.kids.max
      ? "До " + cfg.kids.included + " " + plural(cfg.kids.included, "ребёнка", "детей", "детей") + " — в цене, дальше +" + rub(cfg.kids.extraPrice) + " за ребёнка"
      : "Количество детей на цену не влияет, до " + cfg.kids.max + " на один праздник.";
  }

  function buildMonths() {
    var s = parse(cfg.season.start), e = parse(cfg.season.end);
    var cur = new Date(s.getFullYear(), s.getMonth(), 1);
    while (cur <= e) { months.push(new Date(cur)); cur = new Date(cur.getFullYear(), cur.getMonth() + 1, 1); }
  }
  function renderCalendar() {
    var m = months[state.month];
    $("calTitle").textContent = MONTHS[m.getMonth()] + " " + m.getFullYear();
    $("calPrev").disabled = state.month === 0;
    $("calNext").disabled = state.month === months.length - 1;
    var start = parse(cfg.season.start), end = parse(cfg.season.end), first = today();
    var box = $("days"); box.innerHTML = "";
    var lead = (m.getDay() + 6) % 7;
    for (var i = 0; i < lead; i++) box.appendChild(el("span", "day empty"));
    var last = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
    for (var d = 1; d <= last; d++) {
      var date = new Date(m.getFullYear(), m.getMonth(), d), key = iso(date);
      var b = el("button", "day", String(d)); b.type = "button"; b.dataset.date = key;
      if (date < start || date > end || date < first) { b.disabled = true; }
      else if (!dayFree(key)) { b.disabled = true; b.classList.add("full"); b.setAttribute("aria-label", human(key) + ", всё занято"); }
      else {
        var lv = loadLevel(dayLoad(key));
        b.classList.add("d" + lv);
        b.setAttribute("aria-label", human(key) + ", " + ["", "много свободного времени", "половина времени занята", "почти всё время занято"][lv]);
        b.setAttribute("aria-pressed", state.date === key ? "true" : "false");
      }
      box.appendChild(b);
    }
  }

  function renderTimes() {
    var grid = $("times"); grid.innerHTML = "";
    if (!state.date) { $("timesTitle").textContent = "Выберите дату в календаре"; $("leftNote").hidden = true; return; }
    $("timesTitle").textContent = "Доступное время на " + short(state.date);
    var slots = slotsFor(state.date);
    var freeCount = slots.filter(function (s) { return s.free > 0; }).length;
    $("leftNote").hidden = freeCount > 3;
    $("leftNote").textContent = "На эту дату осталось " + freeCount + " " + plural(freeCount, "свободное окно", "свободных окна", "свободных окон");
    var base = null;
    slots.forEach(function (s) { if (s.free > 0) { var p = calc(state.date, s.time); if (!base || p.total < base.total) base = p; } });
    slots.forEach(function (s) {
      var dis = s.free === 0;
      if (dis && state.time === s.time) state.time = null;
      var pr = calc(state.date, s.time);
      var diff = pr && base && pr.total !== base.total;
      var lab = el("label", "slot");
      lab.innerHTML = '<input type="radio" name="time" value="' + s.time + '"' + (dis ? " disabled" : "") + (state.time === s.time ? " checked" : "") + ">" +
        "<span><b>" + s.time + '<i class="t-end"> – ' + s.end + "</i></b>" + (dis ? "<em>занято</em>" : diff ? '<em class="' + (pr.surge.plus ? "up" : "") + '">' + rub(pr.total) + "</em>" : "") + "</span>";
      grid.appendChild(lab);
    });
  }

  function renderPriceCard() {
    var pr = calc(state.date, state.time);
    $("pcSurge").hidden = true;
    if (!state.date) {
      $("pcLabel").textContent = "Стоимость";
      $("pcPrice").textContent = pr ? "от " + rub(pr.total) : "—";
      $("pcDemand").textContent = "Зависит от даты и от того, насколько разобрано время";
      return;
    }
    $("pcLabel").textContent = "Стоимость " + short(state.date) + (state.time ? ", " + state.time : "");
    $("pcPrice").textContent = pr ? (state.time ? "" : "от ") + rub(pr.total) : "—";
    var lv = loadLevel(dayLoad(state.date));
    $("pcDemand").textContent = "Загрузка дня: " + ["", "низкая", "средняя", "высокая"][lv] + (pr && pr.label ? ". " + pr.label : "");
    if (state.time && pr && pr.surge.plus) { $("pcSurge").textContent = pr.surge.label + ": +" + Math.round(pr.surge.plus * 100) + "%"; $("pcSurge").hidden = false; }
  }

  function renderAddons() {
    $("addons").innerHTML = cfg.addons.map(function (a) {
      return '<label class="tile"><input type="checkbox" name="addon" value="' + esc(a.id) + '"' + (state.addons[a.id] ? " checked" : "") + ">" +
        '<span class="tile-body">' + CHECK + '<svg class="t-ico" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-add-' + (ADDON_ICONS.indexOf(a.icon) > -1 ? a.icon : "star") + '"/></svg><span class="tile-title">' + esc(a.title) + '</span><span class="tile-note">' + esc(a.note) + '</span><span class="tile-price">+' + rub(a.price) + "</span></span></label>";
    }).join("");
    $("zones").innerHTML = cfg.zones.map(function (z) {
      return '<label class="chip"><input type="radio" name="zone" value="' + esc(z.id) + '"' + (state.zone === z.id ? " checked" : "") + "><span>" + esc(z.title) + (z.price ? " +" + rub(z.price) : "") + "</span></label>";
    }).join("");
  }

  function renderChildren() {
    var box = $("children"); box.innerHTML = "";
    state.children.forEach(function (c, i) {
      var row = el("div", "child");
      row.innerHTML = '<input data-i="' + i + '" data-k="name" maxlength="40" placeholder="Имя ребёнка" aria-label="Имя ребёнка ' + (i + 1) + '" value="' + esc(c.name) + '">' +
        '<input data-i="' + i + '" data-k="age" inputmode="numeric" maxlength="2" placeholder="Возраст" aria-label="Возраст ребёнка ' + (i + 1) + '" value="' + esc(c.age) + '">' +
        (state.children.length > 1 ? '<button type="button" data-remove="' + i + '" aria-label="Убрать ребёнка ' + (i + 1) + '">×</button>' : "<span></span>");
      box.appendChild(row);
    });
    $("addChild").hidden = state.children.length >= cfg.kids.max;
  }

  function line(l) { return '<div class="row"><span>' + esc(l[0]) + "</span><span>" + (l[1] < 0 ? "−" + rub(-l[1]) : rub(l[1])) + "</span></div>"; }
  function renderBar() {
    var pr = calc(state.date, state.time);
    var label = state.date && state.time ? "Итого" : "Итого от";
    $("barLabel").textContent = label; $("sumLabel").textContent = label;
    $("barTotal").textContent = pr ? rub(pr.total) : "—"; $("sumTotal").textContent = pr ? rub(pr.total) : "—";
    var lines = pr ? pr.lines.map(line).join("") : "";
    $("breakdown").innerHTML = lines; $("sumLines").innerHTML = lines;
    var c = find(cfg.characters, state.character) || {}, p = find(cfg.programs, state.program) || {}, z = find(cfg.zones, state.zone) || {};
    $("sumWho").innerHTML = '<span class="sum-ava">' + (photoOf(c) ? '<img src="' + esc(photoOf(c)) + '" alt="">' : icon((WHO_ART[c.icon] || WHO_ART.santa)[0])) + "</span>" +
      "<span><b>" + esc(c.title) + "</b><small>" + esc(p.title) + (p.minutes ? ", " + esc(p.minutes) + " мин" : "") + "</small></span>";
    var when = state.date ? short(state.date) + (state.time ? ", " + state.time : ", время не выбрано") : "Дата не выбрана";
    $("sumFacts").innerHTML = '<li class="' + (state.date && state.time ? "" : "muted") + '"><svg aria-hidden="true"><use href="#i-cal"/></svg>' + esc(when) + "</li>" +
      '<li><svg aria-hidden="true"><use href="#i-pin"/></svg>' + esc(z.title || "") + "</li>" +
      '<li><svg aria-hidden="true"><use href="#i-kids"/></svg>' + state.kids + " " + plural(state.kids, "ребёнок", "ребёнка", "детей") + "</li>";
    renderPriceCard();
  }

  /* ---------- шаги ---------- */
  var STEPS = 4;
  // Можно ли уйти с шага n дальше; если нет — что сказать и куда показать
  function stepError(n) {
    if (n === 2 && !state.date) return { msg: "Выберите дату в календаре.", el: $("days") };
    if (n === 2 && !state.time) return { msg: "Выберите время.", el: $("times") };
    return null;
  }
  function showError(err) {
    var box = $("formError");
    if (!err) { box.hidden = true; return; }
    box.textContent = err.msg; box.hidden = false;
    if (err.el) { err.el.scrollIntoView({ behavior: "smooth", block: "center" }); if (err.el.tagName === "INPUT") err.el.focus({ preventScroll: true }); }
  }
  function goStep(n, push) {
    n = Math.max(1, Math.min(STEPS, n));
    state.step = n;
    for (var i = 1; i <= STEPS; i++) $("step-" + i).hidden = i !== n;
    document.querySelectorAll("#stepsNav li").forEach(function (li, i) {
      li.classList.toggle("is-current", i + 1 === n); li.classList.toggle("is-done", i + 1 < n);
      var b = li.querySelector("button"); b.disabled = i + 1 > n && !canReach(i + 1);
      if (i + 1 === n) b.setAttribute("aria-current", "step"); else b.removeAttribute("aria-current");
    });
    var last = n === STEPS, txt = last ? "Заказать" : "Далее";
    ["nextBtn", "sumBtn"].forEach(function (id) { $(id).firstChild.nodeValue = txt; });
    $("backBtn").hidden = n === 1;
    showError(null);
    if (push) history.pushState({ step: n }, "");
    var top = $("order").getBoundingClientRect().top + window.scrollY - 16;
    if (window.scrollY > top || push) window.scrollTo({ top: top, behavior: "smooth" });
    var h = $("step-" + n).querySelector(".step-title"); if (h && push) h.focus({ preventScroll: true });
  }
  function canReach(n) { for (var i = 1; i < n; i++) if (stepError(i)) return false; return true; }
  function next() {
    if (state.step < STEPS) {
      var err = stepError(state.step);
      if (err) { showError(err); return; }
      goStep(state.step + 1, true);
    } else submit();
  }
  function back() {
    if (state.step <= 1) return;
    if (history.state && history.state.step === state.step) history.back(); else goStep(state.step - 1, false);
  }

  /* ---------- события ---------- */
  function bind() {
    var form = $("order");
    form.addEventListener("change", function (e) {
      var t = e.target;
      if (t.name === "character") { state.character = t.value; renderPrograms(); renderTimes(); renderBar(); }
      else if (t.name === "program") { state.program = t.value; renderCalendar(); renderTimes(); renderBar(); }
      else if (t.name === "time") { state.time = t.value; showError(null); renderBar(); }
      else if (t.name === "addon") { state.addons[t.value] = t.checked; renderBar(); }
      else if (t.name === "zone") { state.zone = t.value; renderBar(); }
    });
    $("days").addEventListener("click", function (e) {
      var b = e.target.closest(".day");
      if (!b || b.disabled) return;
      state.date = b.dataset.date; state.time = null; showError(null);
      document.querySelectorAll(".day[aria-pressed]").forEach(function (x) { x.setAttribute("aria-pressed", x === b ? "true" : "false"); });
      renderTimes(); renderBar();
    });
    $("calPrev").addEventListener("click", function () { if (state.month > 0) { state.month--; renderCalendar(); } });
    $("calNext").addEventListener("click", function () { if (state.month < months.length - 1) { state.month++; renderCalendar(); } });
    $("kidsMinus").addEventListener("click", function () {
      if (state.kids <= 1) return;
      state.kids--;
      if (state.children.length > state.kids) { state.children.pop(); renderChildren(); }
      renderKids(); renderTimes(); renderBar();
    });
    $("kidsPlus").addEventListener("click", function () { if (state.kids < cfg.kids.max) { state.kids++; renderKids(); renderTimes(); renderBar(); } });
    ["nextBtn", "sumBtn"].forEach(function (id) { $(id).addEventListener("click", next); });
    $("backBtn").addEventListener("click", back);
    $("stepsNav").addEventListener("click", function (e) {
      var b = e.target.closest("button"); if (!b || b.disabled) return;
      var n = +b.dataset.go; if (n === state.step) return;
      if (n > state.step) { var err = null; for (var i = state.step; i < n && !err; i++) err = stepError(i); if (err) { showError(err); return; } }
      goStep(n, true);
    });
    // кнопка браузера «Назад» возвращает на предыдущий шаг
    window.addEventListener("popstate", function (e) { goStep(e.state && e.state.step ? e.state.step : 1, false); });
    $("bannerBtn").addEventListener("click", function (e) {
      if (!this.dataset.top) return;
      e.preventDefault(); goStep(1, state.step !== 1);
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
    $("barSum").addEventListener("click", function () {
      var bd = $("breakdown"); if (!bd.innerHTML) return;
      var open = bd.hidden; bd.hidden = !open; this.setAttribute("aria-expanded", String(open));
    });

    $("children").addEventListener("input", function (e) { var i = e.target.dataset.i; if (i != null) { if (e.target.dataset.k === "age") e.target.value = e.target.value.replace(/\D/g, ""); state.children[i][e.target.dataset.k] = e.target.value; renderKids(); } });
    $("children").addEventListener("click", function (e) {
      var r = e.target.dataset.remove; if (r == null) return;
      state.children.splice(+r, 1); renderChildren(); renderKids();
    });
    $("addChild").addEventListener("click", function () {
      state.children.push({ name: "", age: "" }); renderChildren();
      var inputs = $("children").querySelectorAll('input[data-k="name"]'); inputs[inputs.length - 1].focus();
      if (state.children.length > state.kids) { state.kids = Math.min(cfg.kids.max, state.children.length); renderKids(); renderTimes(); renderBar(); }
    });

    var phone = form.elements.phone;
    phone.addEventListener("input", function () {
      var d = phone.value.replace(/\D/g, "");
      if (d[0] === "8") d = "7" + d.slice(1);
      if (d && d[0] !== "7") d = "7" + d;
      d = d.slice(0, 11);
      var r = "+7";
      if (d.length > 1) r += " (" + d.slice(1, 4);
      if (d.length >= 4) r += ") " + d.slice(4, 7);
      if (d.length >= 7) r += "-" + d.slice(7, 9);
      if (d.length >= 9) r += "-" + d.slice(9, 11);
      phone.value = d.length ? r : "";
    });

    form.addEventListener("submit", function (e) { e.preventDefault(); next(); });
    function closeDone() { $("done").hidden = true; goStep(1, false); history.replaceState({ step: 1 }, ""); window.scrollTo({ top: 0, behavior: "smooth" }); }
    $("doneClose").addEventListener("click", closeDone);
    $("done").addEventListener("click", function (e) { if (e.target === this) closeDone(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !$("done").hidden) closeDone(); });
  }

  function submit() {
    var err = validate();
    if (err) { if (err.step && err.step !== state.step) goStep(err.step, true); showError(err); return; }
    showError(null);
    if (!cfg.live || cfg.demo) { showDone(); return; }
    sendOrder();
  }
  function setBusy(on) {
    ["nextBtn", "sumBtn"].forEach(function (id) { $(id).disabled = on; $(id).firstChild.nodeValue = on ? "Отправляем…" : (state.step === STEPS ? "Заказать" : "Далее"); });
  }
  function sendOrder() {
    var f = $("order");
    setBusy(true);
    var payload = {
      agency: slug, character: state.character, program: state.program, date: state.date, time: state.time,
      kids: state.kids, addons: Object.keys(state.addons).filter(function (k) { return state.addons[k]; }), zone: state.zone,
      name: f.elements.name.value, phone: f.elements.phone.value, address: f.elements.address.value, comment: f.elements.comment.value,
      children: state.children, consent: f.elements.consent.checked, trap: f.elements.bp_trap.value,
      expectedTotal: (calc(state.date, state.time) || {}).total // сервер сверит: если цена успела измениться, спросим ещё раз
    };
    fetch("api/order.php", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload) })
      .then(function (r) { return r.json().then(function (j) { return { status: r.status, j: j }; }); })
      .then(function (res) {
        if (res.status === 200 && res.j.ok) {
          showDone(res.j);
          // время уже занято этой заявкой: сбрасываем выбор, чтобы повторное «Заказать» не отправило дубль
          state.time = null; reloadOccupancy();
          return;
        }
        $("formError").textContent = res.j.error || "Не удалось отправить заявку. Попробуйте ещё раз или позвоните нам.";
        $("formError").hidden = false;
        if (res.status === 409 && res.j.priceChanged) { reloadOccupancy(); $("formError").scrollIntoView({ behavior: "smooth", block: "center" }); }
        else if (res.status === 409) { state.time = null; reloadOccupancy(); goStep(2, true); $("formError").textContent = res.j.error; $("formError").hidden = false; }
      })
      .catch(function () { $("formError").textContent = "Нет связи с сервером. Проверьте интернет и попробуйте ещё раз."; $("formError").hidden = false; })
      .finally(function () { setBusy(false); });
  }
  // Свежие занятость и цены агентства (могли измениться, пока страница была открыта)
  function reloadOccupancy() {
    fetch("api/agency.php?a=" + encodeURIComponent(slug), { cache: "no-store" }).then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.occupancy || !d.characters) return;
        cfg = d;
        if (!find(cfg.characters, state.character)) state.character = cfg.characters[0].id;
        if (!find(cfg.programs, state.program)) state.program = cfg.programs[0].id;
        if (!find(cfg.zones, state.zone)) state.zone = cfg.zones[0].id;
        Object.keys(state.addons).forEach(function (k) { if (!find(cfg.addons, k)) delete state.addons[k]; });
        if (state.kids > cfg.kids.max) state.kids = cfg.kids.max;
        if (state.time && !(slotOf(state.date, state.time) || {}).free) state.time = null;
        renderCharacters(); renderPrograms(); renderKids(); renderCalendar(); renderTimes(); renderAddons(); renderBar();
      }).catch(function () {});
  }

  function validate() {
    var f = $("order");
    if (!state.date) return { msg: "Выберите дату в календаре.", el: $("days"), step: 2 };
    if (!state.time) return { msg: "Выберите время.", el: $("times"), step: 2 };
    var req = [["name", "Укажите имя."], ["phone", "Укажите телефон."], ["address", "Укажите адрес."]];
    for (var i = 0; i < req.length; i++) {
      var input = f.elements[req[i][0]];
      var bad = !input.value.trim() || (req[i][0] === "phone" && input.value.replace(/\D/g, "").length < 11);
      input.setAttribute("aria-invalid", bad ? "true" : "false");
      if (bad) return { msg: req[i][0] === "phone" && input.value ? "Проверьте номер телефона." : req[i][1], el: input, step: 4 };
    }
    if (!f.elements.consent.checked) return { msg: "Отметьте согласие на обработку персональных данных.", el: f.elements.consent, step: 4 };
    return null;
  }

  function showDone(server) {
    var pr = calc(state.date, state.time), f = $("order");
    if (server && server.lines) pr = { lines: server.lines, total: server.total };
    $("doneText").textContent = cfg.demo
      ? "Это демо-версия, поэтому заявка никуда не ушла. В рабочей версии она сразу придёт агентству, и с вами свяжутся для подтверждения."
      : "Заявка № " + (server ? server.id : "") + " у агентства. Мы перезвоним по номеру " + f.elements.phone.value + ", чтобы подтвердить визит.";
    var kids = state.children.filter(function (c) { return c.name.trim(); }).map(function (c) { return c.name.trim() + (c.age ? ", " + c.age : ""); }).join("; ");
    var rows = [["Когда", human(state.date) + ", " + state.time]];
    if (kids) rows.push(["Дети", kids]);
    rows = rows.concat(pr.lines).concat([["Итого", pr.total]]);
    $("doneSum").innerHTML = rows.map(function (r) { return '<div class="row"><span>' + esc(r[0]) + "</span><span>" + (typeof r[1] === "number" ? (r[1] < 0 ? "−" + rub(-r[1]) : rub(r[1])) : esc(r[1])) + "</span></div>"; }).join("");
    // кнопка предоплаты — только если агентство включило её в настройках
    var pay = cfg.payment || {}, payOn = !cfg.demo && pay.enabled && /^https:\/\//.test(pay.link || "");
    $("donePay").hidden = !payOn;
    if (payOn) {
      $("payBtn").href = pay.link;
      $("payNote").textContent = (pay.amount ? "Предоплата: " + pay.amount + ". " : "") + "Оплата идёт напрямую агентству. Укажите в комментарии к платежу номер заявки № " + (server ? server.id : "") + ".";
    }
    $("doneClose").textContent = payOn ? "Закрыть" : "Хорошо";
    $("done").hidden = false; (payOn ? $("payBtn") : $("doneClose")).focus();
  }

  /* ---------- запуск ---------- */
  function init(data) {
    if (data && data.closed) { initClosed(data); return; }
    cfg = data;
    // скрытые агентством составы, программы и допы на странице не показываем
    ["characters", "programs", "addons"].forEach(function (k) { if (cfg[k]) cfg[k] = cfg[k].filter(function (x) { return !x.off; }); });
    var popular = cfg.characters.filter(function (c) { return c.badge; })[0] || cfg.characters[0];
    state.character = popular.id;
    state.program = (cfg.programs[1] || cfg.programs[0]).id;
    state.zone = cfg.zones[0].id;
    // цвета агентства: кнопки и выбор — «основной», подсветка заголовка — «акцентный» (старый зелёный по умолчанию не применяем)
    var hex = /^#[0-9a-f]{6}$/i, col = cfg.colors || {}, root = document.documentElement.style;
    if (hex.test(col.accent || "") && col.accent.toUpperCase() !== "#163A2F") {
      root.setProperty("--accent", col.accent);
      var n = parseInt(col.accent.slice(1), 16); root.setProperty("--accent-soft", "rgba(" + (n >> 16) + "," + (n >> 8 & 255) + "," + (n & 255) + ",.12)");
    }
    if (hex.test(col.gold || "") && col.gold.toUpperCase() !== "#C9A24A") root.setProperty("--gold", col.gold);
    buildMonths();
    var t = today();
    months.forEach(function (m, i) { if (m.getFullYear() === t.getFullYear() && m.getMonth() === t.getMonth()) state.month = i; });
    renderStatic(); renderCharacters(); renderPrograms(); renderKids(); renderCalendar(); renderTimes(); renderAddons(); renderChildren(); renderBar(); renderPriceCard();
    bind();
    history.replaceState({ step: 1 }, "");
    goStep(1, false);
  }

  var pathSlug = location.pathname.split("/").filter(Boolean).pop() || "";
  if (q) slug = q.toLowerCase().replace(/[^a-z0-9-]/g, "") || "demo";
  else if (/^[a-z0-9-]{2,40}$/.test(pathSlug) && pathSlug !== "prazdnik-zapis") slug = pathSlug;
  function getJson(url) { return fetch(url, { cache: "no-store" }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }); }
  getJson("api/agency.php?a=" + encodeURIComponent(slug))
    .catch(function () { return getJson("agencies/" + slug + ".json"); })
    .then(init)
    .catch(function () {
      ["app", "banner", "foot", "hero"].forEach(function (id) { $(id).hidden = true; });
      $("fail").hidden = false;
    });
})();
