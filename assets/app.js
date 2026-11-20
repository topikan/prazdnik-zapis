(function () {
  "use strict";

  var $ = function (id) { return document.getElementById(id); };
  var MONTHS = ["январь", "февраль", "март", "апрель", "май", "июнь", "июль", "август", "сентябрь", "октябрь", "ноябрь", "декабрь"];
  var MONTHS_GEN = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
  var DOW = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];
  var DOW_FULL = ["воскресенье", "понедельник", "вторник", "среда", "четверг", "пятница", "суббота"];

  var cfg = null;
  var slug = "demo";
  var state = { character: null, program: null, kids: 1, date: null, time: null, addons: {}, zone: null };

  /* ---------- утилиты ---------- */
  function rub(n) { return Math.round(n).toLocaleString("ru-RU").replace(/,/g, " ") + " ₽"; }
  function round100(n) { return Math.round(n / 100) * 100; }
  function pad(n) { return (n < 10 ? "0" : "") + n; }
  function iso(d) { return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()); }
  function parse(s) { var p = s.split("-"); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function minutes(t) { var p = t.split(":"); return +p[0] * 60 + +p[1]; }
  function hhmm(m) { return pad(Math.floor(m / 60)) + ":" + pad(m % 60); }
  function human(s) { var d = parse(s); return d.getDate() + " " + MONTHS_GEN[d.getMonth()] + ", " + DOW_FULL[d.getDay()]; }
  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }

  // детерминированный «случайный» генератор, чтобы занятые слоты не прыгали при перезагрузке
  function hash(str) { var h = 2166136261; for (var i = 0; i < str.length; i++) { h ^= str.charCodeAt(i); h = Math.imul(h, 16777619); } return (h >>> 0) / 4294967295; }

  /* ---------- цены ---------- */
  function dateRule(date) {
    for (var i = 0; i < cfg.dateRules.length; i++) {
      var r = cfg.dateRules[i];
      if (date >= r.from && date <= r.to) return r;
    }
    return { mult: 1, label: "" };
  }
  function multFor(date, time) {
    var m = dateRule(date).mult, label = dateRule(date).label;
    if (time) {
      (cfg.timeRules || []).forEach(function (r) {
        if (r.date === date && minutes(time) >= minutes(r.after) && r.mult > m) { m = r.mult; label = r.label; }
      });
    }
    return { mult: m, label: label };
  }
  var maxMult = 1;
  function basePrice() {
    if (!state.character || !state.program) return null;
    return cfg.prices[state.character][state.program];
  }
  function calc(date, time) {
    var base = basePrice();
    if (base == null) return null;
    var m = date ? multFor(date, time) : { mult: 1, label: "" };
    var lines = [];
    var program = round100(base * m.mult);
    lines.push([titleOf(cfg.characters, state.character) + ", " + programOf().minutes + " мин", program]);
    if (m.mult !== 1) lines[0][0] += " (" + m.label.toLowerCase() + ")";
    var extraKids = Math.max(0, state.kids - cfg.kids.included);
    if (extraKids) lines.push(["Ещё " + extraKids + " " + plural(extraKids, "ребёнок", "ребёнка", "детей") + " сверх " + cfg.kids.included, extraKids * cfg.kids.extraPrice]);
    cfg.addons.forEach(function (a) { if (state.addons[a.id]) lines.push([a.title, a.price]); });
    var z = find(cfg.zones, state.zone);
    if (z && z.price) lines.push(["Выезд: " + z.title.toLowerCase(), z.price]);
    var total = lines.reduce(function (s, l) { return s + l[1]; }, 0);
    return { total: total, lines: lines, mult: m.mult };
  }
  function plural(n, a, b, c) { var n10 = n % 10, n100 = n % 100; if (n10 === 1 && n100 !== 11) return a; if (n10 >= 2 && n10 <= 4 && (n100 < 10 || n100 >= 20)) return b; return c; }
  function find(list, id) { for (var i = 0; i < list.length; i++) if (list[i].id === id) return list[i]; return null; }
  function titleOf(list, id) { var x = find(list, id); return x ? x.title : ""; }
  function programOf() { return find(cfg.programs, state.program); }

  /* ---------- слоты ---------- */
  function slotsFor(date) {
    var from = minutes(cfg.hours.from), to = minutes(cfg.hours.to);
    if (cfg.hours.lastDay && cfg.hours.lastDay.date === date) to = minutes(cfg.hours.lastDay.to);
    var list = [];
    for (var t = from; t <= to; t += 60) {
      var time = hhmm(t);
      var m = multFor(date, time).mult;
      var p = Math.min(0.9, 0.12 + 0.22 * (m - 1)); // чем выше спрос, тем больше занято
      var free = 0;
      for (var k = 0; k < cfg.pairs; k++) if (hash(slug + date + time + k) > p) free++;
      list.push({ time: time, free: free });
    }
    return list;
  }
  function dayFree(date) { return slotsFor(date).some(function (s) { return s.free > 0; }); }

  /* ---------- отрисовка ---------- */
  function radioOption(name, item, price, checked) {
    var label = el("label", "opt");
    label.innerHTML =
      '<input type="radio" name="' + name + '" value="' + esc(item.id) + '"' + (checked ? " checked" : "") + ">" +
      '<span class="opt-body"><span class="opt-title">' + esc(item.title) + "</span>" +
      (item.note ? '<span class="opt-note">' + esc(item.note) + "</span>" : "") +
      (price ? '<span class="opt-price">' + price + "</span>" : "") + "</span>";
    return label;
  }

  function renderCharacters() {
    var box = $("characters"); box.innerHTML = "";
    var cheapest = cfg.programs[0].id;
    cfg.characters.forEach(function (c) {
      box.appendChild(radioOption("character", c, "от " + rub(cfg.prices[c.id][cheapest]), state.character === c.id));
    });
  }
  function renderPrograms() {
    var box = $("programs"); box.innerHTML = "";
    cfg.programs.forEach(function (p) {
      var item = { id: p.id, title: p.title + ", " + p.minutes + " мин", note: p.note };
      var price = state.character ? "от " + rub(cfg.prices[state.character][p.id]) : "";
      box.appendChild(radioOption("program", item, price, state.program === p.id));
    });
  }
  function renderKids() {
    $("kidsValue").textContent = state.kids;
    $("kidsMinus").disabled = state.kids <= 1;
    $("kidsPlus").disabled = state.kids >= cfg.kids.max;
    $("kidsNote").textContent = "До " + cfg.kids.included + " детей входит в цену, дальше +" + rub(cfg.kids.extraPrice) + " за каждого";
  }

  function renderCalendar() {
    var box = $("calendar"); box.innerHTML = "";
    var start = parse(cfg.season.start), end = parse(cfg.season.end);
    var tomorrow = new Date(); tomorrow.setHours(0, 0, 0, 0); tomorrow.setDate(tomorrow.getDate() + 1);
    var cur = new Date(start.getFullYear(), start.getMonth(), 1);
    while (cur <= end) {
      var month = el("div", "month");
      month.appendChild(el("p", "month-title", MONTHS[cur.getMonth()][0].toUpperCase() + MONTHS[cur.getMonth()].slice(1) + " " + cur.getFullYear()));
      var dow = el("div", "dow"); DOW.forEach(function (d) { dow.appendChild(el("span", "", d)); }); month.appendChild(dow);
      var days = el("div", "days");
      var lead = (cur.getDay() + 6) % 7;
      for (var i = 0; i < lead; i++) days.appendChild(el("span", "day empty"));
      var last = new Date(cur.getFullYear(), cur.getMonth() + 1, 0).getDate();
      for (var d = 1; d <= last; d++) {
        var date = new Date(cur.getFullYear(), cur.getMonth(), d);
        var key = iso(date);
        var b = el("button", "day");
        b.type = "button";
        b.dataset.date = key;
        var inSeason = date >= start && date <= end;
        if (!inSeason || date < tomorrow) {
          b.disabled = true; b.textContent = d;
        } else {
          var m = dateRule(key).mult;
          var h = maxMult > 1 ? (m - 1) / (maxMult - 1) : 0;
          b.style.setProperty("--h", h.toFixed(3));
          if (h > 0.55) b.classList.add("hot");
          var pr = calc(key);
          b.innerHTML = d + (pr ? "<small>" + (Math.round(pr.total / 100) / 10).toLocaleString("ru-RU") + "к</small>" : "");
          b.setAttribute("aria-label", human(key) + (pr ? ", от " + rub(pr.total) : "") + ", " + dateRule(key).label.toLowerCase());
          b.setAttribute("aria-pressed", state.date === key ? "true" : "false");
          if (!dayFree(key)) { b.classList.add("full"); b.disabled = true; b.setAttribute("aria-label", human(key) + ", всё занято"); b.innerHTML = d + "<small>нет мест</small>"; }
        }
        days.appendChild(b);
      }
      month.appendChild(days);
      box.appendChild(month);
      cur = new Date(cur.getFullYear(), cur.getMonth() + 1, 1);
    }
  }

  function renderTimes() {
    var box = $("timesBox");
    if (!state.date) { box.hidden = true; return; }
    box.hidden = false;
    $("timesTitle").textContent = "Свободное время на " + human(state.date);
    var grid = $("times"); grid.innerHTML = "";
    slotsFor(state.date).forEach(function (s) {
      var pr = calc(state.date, s.time);
      var lab = el("label", "slot");
      var dis = s.free === 0;
      if (dis && state.time === s.time) state.time = null;
      lab.innerHTML = '<input type="radio" name="time" value="' + s.time + '"' + (dis ? " disabled" : "") + (state.time === s.time ? " checked" : "") + ">" +
        "<span><b>" + s.time + "</b><em>" + (dis ? "занято" : pr ? rub(pr.total) : (s.free === 1 ? "последний" : "свободно")) + "</em></span>";
      grid.appendChild(lab);
    });
  }

  function renderAddons() {
    var box = $("addons"); box.innerHTML = "";
    cfg.addons.forEach(function (a) {
      var label = el("label", "opt check");
      label.innerHTML = '<input type="checkbox" name="addon" value="' + esc(a.id) + '"' + (state.addons[a.id] ? " checked" : "") + ">" +
        '<span class="opt-body"><span class="opt-title">' + esc(a.title) + '</span><span class="opt-note">' + esc(a.note) + '</span><span class="opt-price">+' + rub(a.price) + "</span></span>";
      box.appendChild(label);
    });
    var zones = $("zones"); zones.innerHTML = "";
    cfg.zones.forEach(function (z) {
      var c = el("label", "chip");
      c.innerHTML = '<input type="radio" name="zone" value="' + esc(z.id) + '"' + (state.zone === z.id ? " checked" : "") + "><span>" + esc(z.title) + (z.price ? " +" + rub(z.price) : "") + "</span>";
      zones.appendChild(c);
    });
  }

  function renderBar() {
    var pr = calc(state.date, state.time);
    var label = $("barLabel"), total = $("barTotal");
    if (!pr) { label.textContent = "Итого"; total.textContent = "выберите программу"; total.style.fontSize = "18px"; $("breakdown").innerHTML = ""; return; }
    total.style.fontSize = "";
    label.textContent = state.date && state.time ? "Итого" : state.date ? "Итого, без выбора времени" : "Итого от";
    total.textContent = rub(pr.total);
    $("breakdown").innerHTML = pr.lines.map(function (l) { return '<div class="row"><span>' + esc(l[0]) + "</span><span>" + rub(l[1]) + "</span></div>"; }).join("");
  }

  function renderAll() {
    renderCharacters(); renderPrograms(); renderKids(); renderCalendar(); renderTimes(); renderAddons(); renderBar();
  }

  /* ---------- события ---------- */
  function bind() {
    $("order").addEventListener("change", function (e) {
      var t = e.target;
      if (t.name === "character") { state.character = t.value; renderPrograms(); renderCalendar(); renderTimes(); }
      else if (t.name === "program") { state.program = t.value; renderCalendar(); renderTimes(); }
      else if (t.name === "time") { state.time = t.value; }
      else if (t.name === "addon") { state.addons[t.value] = t.checked; renderCalendar(); renderTimes(); }
      else if (t.name === "zone") { state.zone = t.value; renderCalendar(); renderTimes(); }
      renderBar();
    });
    $("calendar").addEventListener("click", function (e) {
      var b = e.target.closest(".day");
      if (!b || b.disabled) return;
      state.date = b.dataset.date; state.time = null;
      document.querySelectorAll(".day[aria-pressed]").forEach(function (x) { x.setAttribute("aria-pressed", x === b ? "true" : "false"); });
      renderTimes(); renderBar();
      $("timesBox").scrollIntoView({ behavior: "smooth", block: "nearest" });
    });
    $("kidsMinus").addEventListener("click", function () { if (state.kids > 1) { state.kids--; renderKids(); renderCalendar(); renderTimes(); renderBar(); } });
    $("kidsPlus").addEventListener("click", function () { if (state.kids < cfg.kids.max) { state.kids++; renderKids(); renderCalendar(); renderTimes(); renderBar(); } });
    $("barSum").addEventListener("click", function () {
      var bd = $("breakdown"), open = bd.hidden;
      if (!bd.innerHTML) return;
      bd.hidden = !open; this.setAttribute("aria-expanded", String(open));
    });

    var phone = document.querySelector('input[name="phone"]');
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

    $("order").addEventListener("submit", function (e) {
      e.preventDefault();
      var err = validate();
      var box = $("formError");
      if (err) { box.textContent = err.msg; box.hidden = false; err.el.scrollIntoView({ behavior: "smooth", block: "center" }); return; }
      box.hidden = true;
      showDone();
    });
    $("doneClose").addEventListener("click", function () { $("done").hidden = true; });
  }

  function validate() {
    var f = $("order");
    if (!state.character) return { msg: "Выберите, кто приедет.", el: $("step-who") };
    if (!state.program) return { msg: "Выберите программу.", el: $("step-program") };
    if (!state.date) return { msg: "Выберите дату.", el: $("step-date") };
    if (!state.time) return { msg: "Выберите время.", el: $("step-date") };
    var fields = [["name", "Укажите имя."], ["phone", "Укажите телефон."], ["address", "Укажите адрес."]];
    for (var i = 0; i < fields.length; i++) {
      var input = f.elements[fields[i][0]];
      var bad = !input.value.trim() || (fields[i][0] === "phone" && input.value.replace(/\D/g, "").length < 11);
      input.setAttribute("aria-invalid", bad ? "true" : "false");
      if (bad) return { msg: fields[i][0] === "phone" && input.value ? "Проверьте номер телефона." : fields[i][1], el: input };
    }
    if (!f.elements.consent.checked) return { msg: "Отметьте согласие на обработку данных.", el: f.elements.consent };
    return null;
  }

  function showDone() {
    var pr = calc(state.date, state.time);
    var f = $("order");
    $("doneText").textContent = cfg.demo
      ? "Это демо-версия, поэтому заявка никуда не ушла. В рабочей версии она сразу придёт агентству в Telegram, и с вами свяжутся для подтверждения."
      : "Мы свяжемся с вами по номеру " + f.elements.phone.value + ", чтобы подтвердить визит.";
    var rows = [["Когда", human(state.date) + ", " + state.time]].concat(pr.lines).concat([["Итого", pr.total]]);
    $("doneSum").innerHTML = rows.map(function (r) { return '<div class="row"><span>' + esc(r[0]) + "</span><span>" + (typeof r[1] === "number" ? rub(r[1]) : esc(r[1])) + "</span></div>"; }).join("");
    $("done").hidden = false;
    $("doneClose").focus();
  }

  /* ---------- запуск ---------- */
  function init(data) {
    cfg = data;
    maxMult = Math.max.apply(null, cfg.dateRules.map(function (r) { return r.mult; }));
    state.character = cfg.characters[1] ? cfg.characters[1].id : cfg.characters[0].id;
    state.program = cfg.programs[1] ? cfg.programs[1].id : cfg.programs[0].id;
    state.zone = cfg.zones[0].id;
    var root = document.documentElement.style;
    if (cfg.colors) {
      if (cfg.colors.accent) { root.setProperty("--accent", cfg.colors.accent); root.setProperty("--heat-1", cfg.colors.accent); }
      if (cfg.colors.accentDeep) root.setProperty("--accent-deep", cfg.colors.accentDeep);
      if (cfg.colors.ink) root.setProperty("--ink", cfg.colors.ink);
    }
    $("agencyName").textContent = cfg.name + (cfg.city ? ", " + cfg.city : "");
    document.title = "Дед Мороз на дом — " + cfg.name;
    if (cfg.phone) { var a = $("agencyPhone"); a.textContent = cfg.phone; a.href = "tel:" + cfg.phone.replace(/[^\d+]/g, ""); a.hidden = false; }
    $("demoNote").hidden = !cfg.demo;
    renderAll();
    bind();
  }

  var q = new URLSearchParams(location.search).get("a");
  if (q) slug = q.toLowerCase().replace(/[^a-z0-9-]/g, "") || "demo";
  fetch("agencies/" + slug + ".json", { cache: "no-cache" })
    .then(function (r) { if (!r.ok) throw new Error("not found"); return r.json(); })
    .then(init)
    .catch(function () {
      document.querySelector(".top").hidden = true; $("app").hidden = true; $("bar").hidden = true; $("fail").hidden = false;
    });
})();
