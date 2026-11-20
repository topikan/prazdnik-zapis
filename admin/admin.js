(function () {
  "use strict";
  var $ = function (id) { return document.getElementById(id); };
  var me = null, cfg = null, slug = "", tab = "orders";
  var MONTHS = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
  var STATUS = { "new": "Новая", confirmed: "Подтверждена", done: "Выполнена", cancelled: "Отменена" };
  var ADDON_ICONS = [["sack", "Мешок с подарком"], ["camera", "Фотоаппарат"], ["letter", "Письмо"], ["bubbles", "Пузыри"], ["candy", "Конфета"], ["cake", "Торт"], ["balloon", "Шарик"], ["music", "Музыка"], ["snowflake", "Снежинка"], ["car", "Машина"], ["star", "Звезда"], ["video", "Видео"], ["gift", "Подарок"]];

  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function rub(n) { return Math.round(n).toLocaleString("ru-RU") + " ₽"; }
  function pad(n) { return (n < 10 ? "0" : "") + n; }
  function iso(d) { return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()); }
  function human(s) { var p = s.split("-"); return +p[2] + " " + MONTHS[+p[1] - 1]; }
  function uid(prefix) { return prefix + Math.random().toString(36).slice(2, 8); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function fmtPhone(p) { var d = String(p).replace(/\D/g, ""); return d.length === 11 ? "+7 (" + d.slice(1, 4) + ") " + d.slice(4, 7) + "-" + d.slice(7, 9) + "-" + d.slice(9) : p; }

  function flash(msg, bad) {
    var f = $("flash"); f.textContent = msg; f.className = "flash" + (bad ? " bad" : ""); f.hidden = false;
    clearTimeout(flash.t); flash.t = setTimeout(function () { f.hidden = true; }, 4000);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  function api(action, data) {
    return fetch("../api/admin.php?action=" + action, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF": me ? me.csrf : "" },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().catch(function () { return { error: "Сервер не ответил" }; }).then(function (j) {
        if (r.status === 401 && action !== "login") { showLogin(); throw new Error(j.error); }
        if (!r.ok) throw new Error(j.error || "Ошибка");
        return j;
      });
    });
  }

  /* ---------- вход ---------- */
  function showLogin() { $("app").hidden = true; $("login").hidden = false; }
  $("loginForm").addEventListener("submit", function (e) {
    e.preventDefault();
    var f = e.target;
    api("login", { login: f.login.value, password: f.password.value }).then(function (m) { f.password.value = ""; $("loginErr").hidden = true; start(m); })
      .catch(function (err) { $("loginErr").textContent = err.message; $("loginErr").hidden = false; });
  });
  $("logout").addEventListener("click", function () { api("logout").finally(function () { me = null; showLogin(); }); });

  function start(m) {
    me = m;
    $("login").hidden = true; $("app").hidden = false;
    document.querySelectorAll(".super-only").forEach(function (x) { x.hidden = me.role !== "super"; });
    if (!me.agency) { setTab(me.role === "super" ? "agencies" : "orders"); renderHeader(); return; }
    api("config_get").then(function (r) { cfg = r.config; slug = r.slug; renderHeader(); setTab(tab === "agencies" && me.role !== "super" ? "orders" : tab); });
  }
  function renderHeader() {
    $("agencyTitle").textContent = me.agency ? me.agency.name : "Выберите агентство";
    var pl = $("publicLink");
    if (me.agency) { pl.href = "../" + me.agency.slug; pl.hidden = false; } else pl.hidden = true;
    document.querySelectorAll("#tabs button").forEach(function (b) { if (b.dataset.tab !== "agencies" && b.dataset.tab !== "site") b.disabled = !me.agency; });
  }

  /* ---------- вкладки ---------- */
  $("tabs").addEventListener("click", function (e) { var b = e.target.closest("button"); if (b && !b.disabled) setTab(b.dataset.tab); });
  function setTab(t) {
    tab = t;
    document.querySelectorAll("#tabs button").forEach(function (b) { b.setAttribute("aria-current", b.dataset.tab === t ? "true" : "false"); });
    document.querySelectorAll(".tab").forEach(function (s) { s.hidden = s.id !== "tab-" + t; });
    if (t === "orders") loadOrders();
    if (t === "schedule") loadSchedule();
    if (t === "prices") renderPrices();
    if (t === "settings") renderSettings();
    if (t === "agencies") loadAgencies();
    if (t === "site") loadSite();
  }

  /* ---------- заявки ---------- */
  function defaultDates() {
    if (!$("ordFrom").value) $("ordFrom").value = iso(new Date());
    if (!$("ordTo").value && cfg) $("ordTo").value = cfg.season.end;
  }
  ["ordReload"].forEach(function (id) { $(id).addEventListener("click", loadOrders); });
  ["ordFrom", "ordTo", "ordStatus"].forEach(function (id) { $(id).addEventListener("change", loadOrders); });

  function loadOrders() {
    defaultDates();
    api("orders", { from: $("ordFrom").value, to: $("ordTo").value }).then(function (r) {
      var st = $("ordStatus").value;
      var list = r.orders.filter(function (o) { return !st || o.status === st; });
      if (!list.length) { $("orders").innerHTML = '<div class="card empty">Заявок за этот период нет</div>'; return; }
      $("orders").innerHTML = list.map(function (o) {
        var d = o.data || {}, ch = find(cfg.characters, d.character), pr = find(cfg.programs, d.program);
        var kids = (d.children || []).map(function (c) { return esc(c.name) + (c.age ? ", " + esc(c.age) : ""); }).join("; ");
        var addons = (d.addons || []).map(function (id) { var a = find(cfg.addons, id); return a ? esc(a.title) : ""; }).filter(Boolean).join(", ");
        return '<div class="card order">' +
          '<div class="when"><b>' + human(o.visit_date) + "</b>" + esc(o.visit_time) + '<small>№ ' + o.id + "</small></div>" +
          "<div><b>" + esc(ch ? ch.title : d.character) + "</b><small>" + esc(pr ? pr.title + ", " + pr.minutes + " мин" : d.program) + " · детей: " + esc(d.kids) + "</small>" +
          (addons ? "<small>Допы: " + addons + "</small>" : "") + (kids ? "<small>Дети: " + kids + "</small>" : "") + (d.comment ? "<small>Пожелания: " + esc(d.comment) + "</small>" : "") + "</div>" +
          "<div><b>" + esc(o.name) + '</b><small><a href="tel:' + esc(o.phone) + '">' + esc(fmtPhone(o.phone)) + "</a></small><small>" + esc(o.address) + "</small></div>" +
          '<div><div class="sum">' + rub(o.total) + '</div><span class="st st-' + esc(o.status) + '">' + STATUS[o.status] + "</span>" +
          '<p><select data-order="' + o.id + '" aria-label="Статус заявки ' + o.id + '">' + Object.keys(STATUS).map(function (k) { return '<option value="' + k + '"' + (k === o.status ? " selected" : "") + ">" + STATUS[k] + "</option>"; }).join("") + "</select></p></div>" +
          "</div>";
      }).join("");
    }).catch(function (e) { flash(e.message, true); });
  }
  $("orders").addEventListener("change", function (e) {
    var id = e.target.dataset.order; if (!id) return;
    api("order_status", { id: +id, status: e.target.value }).then(function () { flash("Статус обновлён"); loadOrders(); }).catch(function (err) { flash(err.message, true); });
  });
  function find(list, id) { return (list || []).filter(function (x) { return x.id === id; })[0]; }

  /* ---------- расписание ---------- */
  function shiftDay(n) { var d = new Date($("schDate").value + "T12:00"); d.setDate(d.getDate() + n); $("schDate").value = iso(d); loadSchedule(); }
  $("schPrev").addEventListener("click", function () { shiftDay(-1); });
  $("schNext").addEventListener("click", function () { shiftDay(1); });
  $("schDate").addEventListener("change", loadSchedule);
  function loadSchedule() {
    if (!$("schDate").value) { var t = new Date(); t.setDate(t.getDate() + 1); $("schDate").value = iso(t); }
    api("schedule", { date: $("schDate").value }).then(function (r) {
      $("schSlots").innerHTML = r.slots.map(function (s) {
        var pct = Math.min(100, Math.round(s.busy / s.total * 100));
        return '<div class="slot-row"><b>' + s.time + '</b><div class="meter"><i class="' + (pct >= 100 ? "full" : pct >= 50 ? "mid" : "") + '" data-w="' + pct + '"></i></div><span>' + s.busy + " из " + s.total + "</span></div>";
      }).join("");
      // ширину задаём из скрипта: атрибут style запрещён политикой безопасности
      document.querySelectorAll("#schSlots .meter i").forEach(function (i) { i.style.width = i.dataset.w + "%"; });
      $("blockTime").innerHTML = r.slots.map(function (s) { return "<option>" + s.time + "</option>"; }).join("");
      $("blockForm").teams.max = cfg.pairs;
      $("schBlocks").innerHTML = r.blocks.length ? r.blocks.map(function (b) {
        return '<div class="list-row"><span><b>' + esc(b.visit_time) + "</b> · закрыто составов: " + esc(b.teams) + (b.note ? " · " + esc(b.note) : "") + '</span><button class="btn danger small" data-block="' + b.id + '">Открыть</button></div>';
      }).join("") : '<p class="muted">Вручную ничего не закрыто</p>';
      $("schOrders").innerHTML = r.orders.length ? r.orders.map(function (o) {
        return '<div class="list-row"><span><b>' + esc(o.visit_time) + "</b> " + esc(o.name) + " · " + esc(o.address) + '</span><span class="st st-' + esc(o.status) + '">' + STATUS[o.status] + "</span></div>";
      }).join("") : '<p class="muted">Визитов нет</p>';
    }).catch(function (e) { flash(e.message, true); });
  }
  $("blockForm").addEventListener("submit", function (e) {
    e.preventDefault(); var f = e.target;
    api("block_add", { date: $("schDate").value, time: f.time.value, teams: +f.teams.value, note: f.note.value }).then(function () { f.note.value = ""; flash("Время закрыто"); loadSchedule(); }).catch(function (err) { flash(err.message, true); });
  });
  $("schBlocks").addEventListener("click", function (e) {
    var id = e.target.dataset.block; if (!id) return;
    api("block_remove", { id: +id }).then(function () { flash("Время снова открыто"); loadSchedule(); }).catch(function (err) { flash(err.message, true); });
  });

  /* ---------- редактор строк ---------- */
  // fields: [{k, label, type: text|number|date|time|select|percent|mult, options, num}]
  function rowsEditor(title, hint, rows, fields, newRow) {
    var box = document.createElement("div"); box.className = "card ed";
    var data = clone(rows || []);
    function cell(f, r, i) {
      var v = r[f.k];
      if (f.type === "select") return "<select data-i=\"" + i + "\" data-k=\"" + f.k + "\">" + f.options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] === v ? " selected" : "") + ">" + esc(o[1]) + "</option>"; }).join("") + "</select>";
      if (f.type === "percent") v = v == null ? "" : Math.round(v * 100);
      var type = f.type === "percent" || f.type === "number" || f.type === "mult" ? "number" : (f.type || "text");
      var step = f.type === "mult" ? ' step="0.05" min="0.1"' : f.type === "percent" ? ' step="1" min="0" max="100"' : f.type === "number" ? ' step="1" min="0"' : "";
      return '<input type="' + type + '"' + step + ' data-i="' + i + '" data-k="' + f.k + '" value="' + esc(v) + '"' + (f.ph ? ' placeholder="' + esc(f.ph) + '"' : "") + ">";
    }
    function draw() {
      box.innerHTML = "<h2>" + esc(title) + "</h2>" + (hint ? '<p class="muted">' + esc(hint) + "</p>" : "") +
        "<table><thead><tr>" + fields.map(function (f) { return "<th>" + esc(f.label) + "</th>"; }).join("") + "<th></th></tr></thead><tbody>" +
        data.map(function (r, i) { return "<tr>" + fields.map(function (f) { return '<td class="' + (f.num ? "num" : "") + '">' + cell(f, r, i) + "</td>"; }).join("") + '<td><button type="button" class="del" data-del="' + i + '" aria-label="Удалить строку">×</button></td></tr>'; }).join("") +
        '</tbody></table><button type="button" class="btn ghost small add">+ Добавить</button>';
    }
    box.addEventListener("input", function (e) {
      var i = e.target.dataset.i, k = e.target.dataset.k; if (i == null) return;
      var f = fields.filter(function (x) { return x.k === k; })[0], v = e.target.value;
      if (f.type === "number" || f.type === "mult") v = v === "" ? 0 : +v;
      if (f.type === "percent") v = v === "" ? 0 : +v / 100;
      data[i][k] = v;
      box.dispatchEvent(new CustomEvent("changed"));
    });
    box.addEventListener("click", function (e) {
      if (e.target.dataset.del != null) { data.splice(+e.target.dataset.del, 1); draw(); box.dispatchEvent(new CustomEvent("changed")); }
      if (e.target.classList.contains("add")) { data.push(newRow()); draw(); box.dispatchEvent(new CustomEvent("changed")); }
    });
    draw();
    box.get = function () { return data; };
    return box;
  }
  // select меняет значение через событие change: синхронизируем отдельно
  document.addEventListener("change", function (e) {
    var t = e.target; if (t.tagName !== "SELECT" || t.dataset.i == null) return;
    t.dispatchEvent(new Event("input", { bubbles: true }));
  });

  /* ---------- цены и услуги ---------- */
  var ed = {};
  function renderPrices() {
    var root = $("pricesForm"); root.innerHTML = "";
    ed.characters = rowsEditor("Кто приедет", "Составы персонажей. Значок «бейдж» — короткая плашка над карточкой, например «Чаще всего выбирают».", cfg.characters,
      [{ k: "title", label: "Название" }, { k: "note", label: "Подпись" }, { k: "icon", label: "Картинка", type: "select", options: [["santa", "Дед Мороз"], ["duo", "Дед Мороз и Снегурочка"], ["trio", "Трио со Снеговиком"]] }, { k: "badge", label: "Бейдж" }],
      function () { return { id: uid("c"), title: "", note: "", icon: "santa", badge: "" }; });
    ed.programs = rowsEditor("Программы", "Длительность в минутах влияет на то, сколько часов займёт визит вместе с дорогой.", cfg.programs,
      [{ k: "title", label: "Название" }, { k: "minutes", label: "Минут", type: "number", num: 1 }, { k: "note", label: "Что входит" }, { k: "icon", label: "Картинка", type: "select", options: [["gift", "Подарок"], ["tree", "Ёлка"], ["ball", "Диско-шар"]] }],
      function () { return { id: uid("p"), title: "", minutes: 30, note: "", icon: "tree" }; });
    var matrix = document.createElement("div"); matrix.className = "card ed matrix";
    var prices = clone(cfg.prices || {});
    function drawMatrix() {
      var chs = ed.characters.get(), prs = ed.programs.get();
      matrix.innerHTML = '<h2>Базовые цены</h2><p class="muted">Цена в обычный день, без наценок. Наценки по датам и спросу добавятся сверху.</p><table><thead><tr><th></th>' +
        prs.map(function (p) { return "<th>" + esc(p.title || "Программа") + "</th>"; }).join("") + "</tr></thead><tbody>" +
        chs.map(function (c) { return "<tr><td>" + esc(c.title || "Состав") + "</td>" + prs.map(function (p) { var v = (prices[c.id] || {})[p.id]; return '<td><input type="number" min="0" step="100" data-c="' + c.id + '" data-p="' + p.id + '" value="' + (v == null ? "" : v) + '"></td>'; }).join("") + "</tr>"; }).join("") + "</tbody></table>";
    }
    matrix.addEventListener("input", function (e) { var c = e.target.dataset.c, p = e.target.dataset.p; if (!c) return; prices[c] = prices[c] || {}; prices[c][p] = e.target.value === "" ? null : +e.target.value; });
    ed.characters.addEventListener("changed", drawMatrix); ed.programs.addEventListener("changed", drawMatrix);
    drawMatrix();
    ed.matrix = { get: function () { var out = {}; ed.characters.get().forEach(function (c) { out[c.id] = {}; ed.programs.get().forEach(function (p) { var v = (prices[c.id] || {})[p.id]; if (v != null) out[c.id][p.id] = v; }); }); return out; } };

    ed.kids = rowsEditor("Дети", "Сколько детей входит в цену, максимум на один заказ и доплата за каждого сверх нормы.", [cfg.kids],
      [{ k: "included", label: "Входит в цену", type: "number", num: 1 }, { k: "max", label: "Максимум", type: "number", num: 1 }, { k: "extraPrice", label: "Доплата за ребёнка, ₽", type: "number", num: 1 }], function () { return { included: 5, max: 15, extraPrice: 300 }; });
    ed.dateRules = rowsEditor("Наценки по датам", "Коэффициент: 1 — обычная цена, 1,5 — на 50% дороже. Подпись видит клиент.", cfg.dateRules,
      [{ k: "from", label: "С", type: "date" }, { k: "to", label: "По", type: "date" }, { k: "mult", label: "Коэффициент", type: "mult", num: 1 }, { k: "label", label: "Подпись" }],
      function () { return { from: cfg.season.start, to: cfg.season.start, mult: 1, label: "" }; });
    ed.timeRules = rowsEditor("Наценки по времени", "Например, вечер 31 декабря после 19:00.", cfg.timeRules,
      [{ k: "date", label: "Дата", type: "date" }, { k: "after", label: "Начиная с", type: "time" }, { k: "mult", label: "Коэффициент", type: "mult", num: 1 }, { k: "label", label: "Подпись" }],
      function () { return { date: cfg.season.end, after: "19:00", mult: 2, label: "" }; });
    ed.demand = rowsEditor("Наценка за спрос", "Когда свободных составов на это время остаётся мало, цена растёт. Удалите строки, если наценка не нужна.", cfg.demandPricing,
      [{ k: "freeShareAtMost", label: "Свободно не больше, %", type: "percent", num: 1 }, { k: "plus", label: "Наценка, %", type: "percent", num: 1 }, { k: "label", label: "Подпись для клиента" }],
      function () { return { freeShareAtMost: 0.5, plus: 0.1, label: "Это время популярно" }; });
    ed.promo = rowsEditor("Скидка за раннее бронирование", "Действует для заказов, оформленных до указанной даты. Оставьте пустым, если не нужна.", cfg.promo ? [cfg.promo] : [],
      [{ k: "percent", label: "Скидка, %", type: "number", num: 1 }, { k: "until", label: "Для заказов до", type: "date" }, { k: "text", label: "Текст" }],
      function () { return { percent: 10, until: cfg.season.start, text: "" }; });
    ed.addons = rowsEditor("Дополнительные услуги", "Все картинки в одном стиле, выберите подходящую.", cfg.addons,
      [{ k: "title", label: "Название" }, { k: "note", label: "Подпись" }, { k: "price", label: "Цена, ₽", type: "number", num: 1 }, { k: "icon", label: "Картинка", type: "select", options: ADDON_ICONS }],
      function () { return { id: uid("a"), title: "", note: "", price: 1000, icon: "star" }; });
    ed.zones = rowsEditor("Куда ехать", "Первая строка выбрана по умолчанию.", cfg.zones,
      [{ k: "title", label: "Название" }, { k: "price", label: "Доплата, ₽", type: "number", num: 1 }], function () { return { id: uid("z"), title: "", price: 0 }; });

    [ed.characters, ed.programs, matrix, ed.kids, ed.dateRules, ed.timeRules, ed.demand, ed.promo, ed.addons, ed.zones].forEach(function (x) { root.appendChild(x); });
  }
  $("savePrices").addEventListener("click", function () {
    var c = clone(cfg);
    c.characters = ed.characters.get(); c.programs = ed.programs.get(); c.prices = ed.matrix.get();
    c.kids = ed.kids.get()[0] || c.kids; c.dateRules = ed.dateRules.get(); c.timeRules = ed.timeRules.get();
    c.demandPricing = ed.demand.get(); c.addons = ed.addons.get(); c.zones = ed.zones.get();
    var p = ed.promo.get()[0]; if (p && p.percent) c.promo = p; else delete c.promo;
    save(c);
  });
  function save(c) {
    api("config_save", { config: c }).then(function () { cfg = c; flash("Сохранено. Изменения уже на странице записи."); if (me.agency) { me.agency.name = c.name; renderHeader(); } })
      .catch(function (e) { flash(e.message, true); });
  }

  /* ---------- настройки ---------- */
  var SETTINGS = [
    ["Основное", [["name", "Название агентства"], ["city", "Город"], ["phone", "Телефон на странице"], ["colors.accent", "Основной цвет", "color"], ["colors.gold", "Акцентный цвет", "color"]]],
    ["Сезон и часы", [["season.start", "Сезон с", "date"], ["season.end", "Сезон по", "date"], ["hours.from", "Первый визит в", "time"], ["hours.to", "Последний визит в", "time"], ["hours.lastDay.date", "Особый день", "date"], ["hours.lastDay.to", "В особый день последний визит в", "time"], ["pairs", "Сколько составов работает", "number"], ["travelMinutes", "Дорога между визитами, мин", "number"]]],
    ["Реквизиты для согласия и политики", [["legal.operator", "Оператор (ИП или ООО, ФИО)"], ["legal.inn", "ИНН"], ["legal.ogrn", "ОГРН / ОГРНИП"], ["legal.address", "Адрес"], ["legal.email", "Почта для обращений"], ["legal.keepMonths", "Хранить данные после визита, мес.", "number"], ["legal.updated", "Дата редакции политики"], ["legal.policyUrl", "Своя политика: ссылка (если есть)"]]]
  ];
  function getPath(o, p) { return p.split(".").reduce(function (a, k) { return a == null ? undefined : a[k]; }, o); }
  function setPath(o, p, v) { var ks = p.split("."), last = ks.pop(); ks.forEach(function (k) { if (o[k] == null || typeof o[k] !== "object") o[k] = {}; o = o[k]; }); o[last] = v; }
  function renderSettings() {
    var root = $("settingsForm");
    root.innerHTML = SETTINGS.map(function (g) {
      return '<div class="card"><h2>' + esc(g[0]) + '</h2><div class="fields">' + g[1].map(function (f) {
        var v = getPath(cfg, f[0]); if (f[2] === "color" && !v) v = f[0] === "colors.gold" ? "#C9A24A" : "#163A2F";
        return "<label>" + esc(f[1]) + '<input data-path="' + f[0] + '" type="' + (f[2] || "text") + '" value="' + esc(v == null ? "" : v) + '"></label>';
      }).join("") + "</div></div>";
    }).join("") + (me.role === "super" ? '<div class="card"><h2>Только для владельца сервиса</h2><label class="row"><span><input type="checkbox" id="demoFlag"' + (cfg.demo ? " checked" : "") + "> Демо-режим (заявки не принимаются)</span></label></div>" : "");
  }
  $("saveSettings").addEventListener("click", function () {
    var c = clone(cfg);
    document.querySelectorAll("#settingsForm input[data-path]").forEach(function (i) {
      var v = i.type === "number" ? (i.value === "" ? 0 : +i.value) : i.value.trim();
      setPath(c, i.dataset.path, v);
    });
    if (c.hours && c.hours.lastDay && !c.hours.lastDay.date) delete c.hours.lastDay;
    if ($("demoFlag")) c.demo = $("demoFlag").checked;
    save(c);
  });
  $("pwForm").addEventListener("submit", function (e) {
    e.preventDefault(); var f = e.target;
    api("password", { old: f.old.value, "new": f["new"].value }).then(function () { f.reset(); flash("Пароль изменён"); }).catch(function (err) { flash(err.message, true); });
  });

  /* ---------- главная (владелец) ---------- */
  var siteEd = {}, siteData = null;
  var SITE_FIELDS = [
    ["Первый экран", [["heroTitle", "Заголовок", "area"], ["heroLead", "Подзаголовок", "area"], ["demoButton", "Кнопка демо"], ["contactButton", "Кнопка связи"]]],
    ["Ссылки", [["telegram", "Telegram (https://t.me/...)"], ["demoSlug", "Адрес демо-агентства"], ["footer", "Текст в подвале"], ["footerLink", "Ссылка в подвале"]]],
    ["Заголовки блоков", [["itemsTitle", "Блок «Что это решает»"], ["stepsTitle", "Блок «Как подключиться»"], ["faqTitle", "Блок вопросов"], ["finalTitle", "Заголовок внизу"], ["finalDemoButton", "Кнопка демо внизу"], ["finalContactButton", "Кнопка связи внизу"]]]
  ];
  function loadSite() {
    api("site_get").then(function (r) {
      if (r.landing) return r.landing;
      return fetch("../assets/landing.json", { cache: "no-store" }).then(function (x) { return x.json(); });
    }).then(function (d) {
      siteData = d;
      var root = $("siteForm");
      root.innerHTML = SITE_FIELDS.map(function (g) {
        return '<div class="card"><h2>' + esc(g[0]) + '</h2><div class="fields">' + g[1].map(function (f) {
          var v = esc(d[f[0]] || "");
          return "<label>" + esc(f[1]) + (f[2] === "area" ? '<textarea data-site="' + f[0] + '" rows="3">' + v + "</textarea>" : '<input data-site="' + f[0] + '" value="' + v + '">') + "</label>";
        }).join("") + "</div></div>";
      }).join("");
      siteEd.items = rowsEditor("Карточки «Что это решает»", "", d.items, [{ k: "title", label: "Заголовок" }, { k: "text", label: "Текст" }], function () { return { title: "", text: "" }; });
      siteEd.steps = rowsEditor("Шаги «Как подключиться»", "", d.steps, [{ k: "title", label: "Шаг" }, { k: "text", label: "Пояснение" }], function () { return { title: "", text: "" }; });
      siteEd.faq = rowsEditor("Частые вопросы", "Здесь же цена подключения.", d.faq, [{ k: "q", label: "Вопрос" }, { k: "a", label: "Ответ" }], function () { return { q: "", a: "" }; });
      [siteEd.items, siteEd.steps, siteEd.faq].forEach(function (x) { root.appendChild(x); });
    }).catch(function (e) { flash(e.message, true); });
  }
  $("saveSite").addEventListener("click", function () {
    var d = clone(siteData || {});
    document.querySelectorAll("#siteForm [data-site]").forEach(function (i) { d[i.dataset.site] = i.value.trim(); });
    d.items = siteEd.items.get(); d.steps = siteEd.steps.get(); d.faq = siteEd.faq.get();
    api("site_save", { landing: d }).then(function () { siteData = d; flash("Главная сохранена"); }).catch(function (e) { flash(e.message, true); });
  });

  /* ---------- агентства (владелец) ---------- */
  function loadAgencies() {
    api("agencies").then(function (r) {
      $("agencies").innerHTML = r.agencies.map(function (a) {
        return '<div class="card agency-row"><div><b>' + esc(a.name) + "</b>" + (a.demo ? ' <span class="st st-new">демо</span>' : "") + (a.active ? "" : ' <span class="st st-cancelled">выключено</span>') +
          '<br><small class="muted">/' + esc(a.slug) + (a.city ? " · " + esc(a.city) : "") + " · вход: " + esc(a.login || "не создан") + "</small></div>" +
          "<div>Заявок: " + a.orders + "</div><div></div>" +
          '<div class="acts"><button class="btn small" data-open="' + a.id + '">Открыть</button>' +
          '<button class="btn ghost small" data-user="' + a.id + '" data-login="' + esc(a.login || a.slug) + '">Вход для агентства</button>' +
          '<button class="btn ' + (a.active ? "danger" : "ghost") + ' small" data-toggle="' + a.id + '" data-active="' + (a.active ? 0 : 1) + '">' + (a.active ? "Выключить" : "Включить") + "</button></div></div>";
      }).join("") || '<div class="card empty">Агентств пока нет</div>';
    }).catch(function (e) { flash(e.message, true); });
  }
  $("agencies").addEventListener("click", function (e) {
    var t = e.target;
    if (t.dataset.open) api("agency_open", { id: +t.dataset.open }).then(function (m) { start(m); tab = "orders"; }).catch(function (err) { flash(err.message, true); });
    if (t.dataset.toggle) api("agency_toggle", { id: +t.dataset.toggle, active: t.dataset.active === "1" }).then(loadAgencies);
    if (t.dataset.user) {
      var login = prompt("Логин для агентства", t.dataset.login); if (!login) return;
      var pass = Math.random().toString(36).slice(2, 8) + Math.random().toString(36).slice(2, 8).toUpperCase();
      api("agency_user", { id: +t.dataset.user, login: login, password: pass }).then(function () {
        alert("Вход для агентства создан.\n\nЛогин: " + login + "\nПароль: " + pass + "\n\nПередайте его агентству лично и попросите сменить пароль во вкладке «Настройки». Повторно этот пароль не показывается.");
        loadAgencies();
      }).catch(function (err) { flash(err.message, true); });
    }
  });
  $("newAgency").addEventListener("submit", function (e) {
    e.preventDefault(); var f = e.target;
    api("agency_create", { name: f.name.value, city: f.city.value, slug: f.slug.value }).then(function () { f.reset(); flash("Агентство создано"); loadAgencies(); }).catch(function (err) { flash(err.message, true); });
  });

  /* ---------- запуск ---------- */
  fetch("../api/admin.php?action=me", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" }, body: "{}" })
    .then(function (r) { return r.ok ? r.json() : null; })
    .then(function (m) { if (m && m.csrf) start(m); else showLogin(); })
    .catch(showLogin);
})();
