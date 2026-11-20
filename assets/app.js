(function () {
  "use strict";

  var $ = function (id) { return document.getElementById(id); };
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
  var state = { character: null, program: null, kids: 1, date: null, time: null, addons: {}, zone: null, month: 0, children: [{ name: "", age: "" }] };
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
  function today() { var t = new Date(); t.setHours(0, 0, 0, 0); return t; }

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

  // Наценка за спрос: чем меньше свободных артистов на это время, тем дороже
  function surgeFor(free) {
    var share = free / cfg.pairs, best = { plus: 0, label: "" };
    (cfg.demandPricing || []).forEach(function (r) { if (share <= r.freeShareAtMost && r.plus > best.plus) best = r; });
    return best;
  }
  function slotOf(date, time) { var l = slotsFor(date); for (var i = 0; i < l.length; i++) if (l[i].time === time) return l[i]; return null; }
  // Загрузка дня: доля занятых артистов по всем часам
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
  function slotsFor(date) {
    var from = minutes(cfg.hours.from), to = minutes(cfg.hours.to);
    if (cfg.hours.lastDay && cfg.hours.lastDay.date === date) to = minutes(cfg.hours.lastDay.to);
    var list = [];
    for (var t = from; t <= to; t += 60) {
      var time = hhmm(t), m = multFor(date, time).mult;
      var p = Math.min(0.92, 0.1 + 0.32 * (m - 1)), free = 0;
      for (var k = 0; k < cfg.pairs; k++) if (hash(slug + date + time + k) > p) free++;
      list.push({ time: time, end: hhmm(t + 60), free: free });
    }
    return list;
  }
  function dayFree(date) { return slotsFor(date).some(function (s) { return s.free > 0; }); }

  /* ---------- отрисовка ---------- */
  function renderStatic() {
    $("agencyName").textContent = cfg.name;
    document.title = "Дед Мороз на дом — " + cfg.name;
    if (cfg.phone) { var a = $("agencyPhone"); a.querySelector("span").textContent = cfg.phone; a.href = "tel:" + cfg.phone.replace(/[^\d+]/g, ""); a.hidden = false; }
    $("demoNote").hidden = !cfg.demo;
    if (q) { $("consentLink").href = "consent.html?a=" + encodeURIComponent(slug); $("privacyLink").href = "privacy.html?a=" + encodeURIComponent(slug); }
  }

  function renderCharacters() {
    var cheapest = cfg.programs[0].id;
    $("characters").innerHTML = cfg.characters.map(function (c) {
      var art = (WHO_ART[c.icon] || WHO_ART.santa).map(icon).join("");
      return '<label class="card">' + (c.badge ? '<span class="badge">' + esc(c.badge) + "</span>" : "") +
        '<input type="radio" name="character" value="' + esc(c.id) + '"' + (state.character === c.id ? " checked" : "") + ">" +
        '<span class="card-body"><span class="who-art">' + art + '</span><span><span class="card-title">' + esc(c.title) + '</span><span class="card-note">' + esc(c.note) + '</span><span class="card-price">от ' + rub(cfg.prices[c.id][cheapest]) + "</span></span></span></label>";
    }).join("");
  }
  function renderPrograms() {
    $("programs").innerHTML = cfg.programs.map(function (p) {
      return '<label class="card"><input type="radio" name="program" value="' + esc(p.id) + '"' + (state.program === p.id ? " checked" : "") + ">" +
        '<span class="card-body"><svg class="prog-icon" viewBox="0 0 48 48" aria-hidden="true"><use href="#' + (PROG_ICON[p.icon] || "i-giftbig") + '"/></svg>' +
        '<span><span class="card-title">' + esc(p.title) + '</span><span class="card-note">' + esc(p.note) + '</span><span class="prog-min">' + p.minutes + " мин · от " + rub(cfg.prices[state.character][p.id]) + "</span></span></span></label>";
    }).join("");
  }
  function renderKids() {
    $("kidsValue").textContent = state.kids;
    var last = state.children[state.children.length - 1];
    var lastFilled = last && (last.name.trim() || String(last.age).trim());
    // Нельзя сделать детей меньше, чем вписано имён: сначала убрать ребёнка крестиком
    $("kidsMinus").disabled = state.kids <= 1 || (state.kids <= state.children.length && lastFilled);
    $("kidsPlus").disabled = state.kids >= cfg.kids.max;
    $("kidsNote").textContent = "До " + cfg.kids.included + " детей входит в цену, каждый следующий +" + rub(cfg.kids.extraPrice) + ".";
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
    var start = parse(cfg.season.start), end = parse(cfg.season.end), tomorrow = today(); tomorrow.setDate(tomorrow.getDate() + 1);
    var box = $("days"); box.innerHTML = "";
    var lead = (m.getDay() + 6) % 7;
    for (var i = 0; i < lead; i++) box.appendChild(el("span", "day empty"));
    var last = new Date(m.getFullYear(), m.getMonth() + 1, 0).getDate();
    for (var d = 1; d <= last; d++) {
      var date = new Date(m.getFullYear(), m.getMonth(), d), key = iso(date);
      var b = el("button", "day", String(d)); b.type = "button"; b.dataset.date = key;
      if (date < start || date > end || date < tomorrow) { b.disabled = true; }
      else if (!dayFree(key)) { b.disabled = true; b.classList.add("full"); b.setAttribute("aria-label", human(key) + ", всё занято"); }
      else {
        var lv = loadLevel(dayLoad(key));
        b.classList.add("d" + lv);
        b.setAttribute("aria-label", human(key) + ", " + ["", "много свободных артистов", "половина занята", "почти всё занято"][lv]);
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
        "<span><b>" + s.time + " – " + s.end + "</b>" + (dis ? "<em>занято</em>" : diff ? '<em class="' + (pr.surge.plus ? "up" : "") + '">' + rub(pr.total) + "</em>" : "") + "</span>";
      grid.appendChild(lab);
    });
  }

  function renderPriceCard() {
    var pr = calc(state.date, state.time);
    $("pcSurge").hidden = true;
    if (!state.date) {
      $("pcLabel").textContent = "Стоимость";
      $("pcPrice").textContent = pr ? "от " + rub(pr.total) : "—";
      $("pcDemand").textContent = "Зависит от даты, времени и того, сколько артистов уже занято";
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
        '<span class="tile-body"><svg viewBox="0 0 48 48" aria-hidden="true"><use href="#i-add-' + (ADDON_ICONS.indexOf(a.icon) > -1 ? a.icon : "star") + '"/></svg><span class="tile-title">' + esc(a.title) + '</span><span class="tile-note">' + esc(a.note) + '</span><span class="tile-price">+' + rub(a.price) + "</span></span></label>";
    }).join("");
    $("zones").innerHTML = cfg.zones.map(function (z) {
      return '<label class="chip"><input type="radio" name="zone" value="' + esc(z.id) + '"' + (state.zone === z.id ? " checked" : "") + "><span>" + esc(z.title) + (z.price ? " +" + rub(z.price) : "") + "</span></label>";
    }).join("");
  }

  function renderChildren() {
    var box = $("children"); box.innerHTML = "";
    state.children.forEach(function (c, i) {
      var row = el("div", "child");
      row.innerHTML = '<input data-i="' + i + '" data-k="name" placeholder="Имя ребёнка" aria-label="Имя ребёнка ' + (i + 1) + '" value="' + esc(c.name) + '">' +
        '<input data-i="' + i + '" data-k="age" inputmode="numeric" placeholder="Возраст" aria-label="Возраст ребёнка ' + (i + 1) + '" value="' + esc(c.age) + '">' +
        (state.children.length > 1 ? '<button type="button" data-remove="' + i + '" aria-label="Убрать ребёнка ' + (i + 1) + '">×</button>' : "<span></span>");
      box.appendChild(row);
    });
    $("addChild").hidden = state.children.length >= cfg.kids.max;
  }

  function renderBar() {
    var pr = calc(state.date, state.time);
    if (!pr) { $("barTotal").textContent = "—"; $("breakdown").innerHTML = ""; return; }
    $("barLabel").textContent = state.date && state.time ? "Итого:" : "Итого от:";
    $("barTotal").textContent = rub(pr.total);
    $("breakdown").innerHTML = pr.lines.map(function (l) { return '<div class="row"><span>' + esc(l[0]) + "</span><span>" + (l[1] < 0 ? "−" + rub(-l[1]) : rub(l[1])) + "</span></div>"; }).join("");
    renderPriceCard();
  }

  /* ---------- события ---------- */
  function bind() {
    var form = $("order");
    form.addEventListener("change", function (e) {
      var t = e.target;
      if (t.name === "character") { state.character = t.value; renderPrograms(); renderTimes(); renderBar(); }
      else if (t.name === "program") { state.program = t.value; renderTimes(); renderBar(); }
      else if (t.name === "time") { state.time = t.value; renderBar(); }
      else if (t.name === "addon") { state.addons[t.value] = t.checked; renderBar(); }
      else if (t.name === "zone") { state.zone = t.value; renderBar(); }
    });
    $("days").addEventListener("click", function (e) {
      var b = e.target.closest(".day");
      if (!b || b.disabled) return;
      state.date = b.dataset.date; state.time = null;
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
    $("barSum").addEventListener("click", function () {
      var bd = $("breakdown"); if (!bd.innerHTML) return;
      var open = bd.hidden; bd.hidden = !open; this.setAttribute("aria-expanded", String(open));
    });

    $("children").addEventListener("input", function (e) { var i = e.target.dataset.i; if (i != null) { state.children[i][e.target.dataset.k] = e.target.value; renderKids(); } });
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

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var err = validate(), box = $("formError");
      if (err) {
        box.textContent = err.msg; box.hidden = false;
        err.el.scrollIntoView({ behavior: "smooth", block: "center" });
        if (err.el.tagName === "INPUT") err.el.focus({ preventScroll: true });
        return;
      }
      box.hidden = true; showDone();
    });
    $("doneClose").addEventListener("click", function () { $("done").hidden = true; });
  }

  function validate() {
    var f = $("order");
    if (!state.date) return { msg: "Выберите дату в календаре.", el: $("step-date") };
    if (!state.time) return { msg: "Выберите время.", el: $("step-date") };
    var req = [["name", "Укажите имя."], ["phone", "Укажите телефон."], ["address", "Укажите адрес."]];
    for (var i = 0; i < req.length; i++) {
      var input = f.elements[req[i][0]];
      var bad = !input.value.trim() || (req[i][0] === "phone" && input.value.replace(/\D/g, "").length < 11);
      input.setAttribute("aria-invalid", bad ? "true" : "false");
      if (bad) return { msg: req[i][0] === "phone" && input.value ? "Проверьте номер телефона." : req[i][1], el: input };
    }
    if (!f.elements.consent.checked) return { msg: "Отметьте согласие на обработку персональных данных.", el: f.elements.consent };
    return null;
  }

  function showDone() {
    var pr = calc(state.date, state.time), f = $("order");
    $("doneText").textContent = cfg.demo
      ? "Это демо-версия, поэтому заявка никуда не ушла. В рабочей версии она сразу придёт агентству, и с вами свяжутся для подтверждения."
      : "Мы перезвоним по номеру " + f.elements.phone.value + ", чтобы подтвердить визит.";
    var kids = state.children.filter(function (c) { return c.name.trim(); }).map(function (c) { return c.name.trim() + (c.age ? ", " + c.age : ""); }).join("; ");
    var rows = [["Когда", human(state.date) + ", " + state.time]];
    if (kids) rows.push(["Дети", kids]);
    rows = rows.concat(pr.lines).concat([["Итого", pr.total]]);
    $("doneSum").innerHTML = rows.map(function (r) { return '<div class="row"><span>' + esc(r[0]) + "</span><span>" + (typeof r[1] === "number" ? (r[1] < 0 ? "−" + rub(-r[1]) : rub(r[1])) : esc(r[1])) + "</span></div>"; }).join("");
    $("done").hidden = false; $("doneClose").focus();
  }

  /* ---------- запуск ---------- */
  function init(data) {
    cfg = data;
    var popular = cfg.characters.filter(function (c) { return c.badge; })[0] || cfg.characters[0];
    state.character = popular.id;
    state.program = (cfg.programs[1] || cfg.programs[0]).id;
    state.zone = cfg.zones[0].id;
    if (cfg.colors && cfg.colors.accent) document.documentElement.style.setProperty("--ink", cfg.colors.accent);
    if (cfg.colors && cfg.colors.gold) document.documentElement.style.setProperty("--gold", cfg.colors.gold);
    buildMonths();
    var t = today();
    months.forEach(function (m, i) { if (m.getFullYear() === t.getFullYear() && m.getMonth() === t.getMonth()) state.month = i; });
    renderStatic(); renderCharacters(); renderPrograms(); renderKids(); renderCalendar(); renderTimes(); renderAddons(); renderChildren(); renderBar(); renderPriceCard();
    bind();
  }

  if (q) slug = q.toLowerCase().replace(/[^a-z0-9-]/g, "") || "demo";
  fetch("agencies/" + slug + ".json", { cache: "no-cache" })
    .then(function (r) { if (!r.ok) throw new Error("not found"); return r.json(); })
    .then(init)
    .catch(function () {
      ["app", "bar"].forEach(function (id) { $(id).hidden = true; });
      document.querySelector(".hero").hidden = true; document.querySelector(".nav").hidden = true; $("fail").hidden = false;
    });
})();
