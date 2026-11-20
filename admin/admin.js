(function () {
  "use strict";
  // Если сайт открыли по http, переходим на https (хостинг за прокси не всегда делает это сам)
  if (location.protocol === "http:" && !/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)) { location.replace("https://" + location.host + location.pathname + location.search + location.hash); return; }
  var $ = function (id) { return document.getElementById(id); };
  var me = null, cfg = null, slug = "", tab = "orders";
  var MONTHS = ["января", "февраля", "марта", "апреля", "мая", "июня", "июля", "августа", "сентября", "октября", "ноября", "декабря"];
  var DAYS = ["воскресенье", "понедельник", "вторник", "среда", "четверг", "пятница", "суббота"];
  function plural(n, one, few, many) { var m10 = n % 10, m100 = n % 100; return m10 === 1 && m100 !== 11 ? one : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? few : many; }
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
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = ctl && setTimeout(function () { ctl.abort(); }, 25000);
    return fetch("../api/admin.php?action=" + action, {
      method: "POST", credentials: "same-origin", signal: ctl ? ctl.signal : undefined,
      headers: { "Content-Type": "application/json", "X-CSRF": me ? me.csrf : "" },
      body: JSON.stringify(data || {})
    }).catch(function (e) {
      throw new Error(e && e.name === "AbortError" ? "Сервер долго не отвечает. Проверьте интернет и попробуйте ещё раз." : "Нет связи с сервером. Проверьте интернет.");
    }).then(function (r) {
      if (timer) clearTimeout(timer);
      return r.json().catch(function () { return { error: "Сервер ответил с ошибкой (код " + r.status + ")" }; }).then(function (j) {
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
  $("tabs").addEventListener("click", function (e) {
    var b = e.target.closest("button"); if (!b || b.disabled || b.dataset.tab === tab) return;
    if (dirty[tab] && !confirm("На этой вкладке есть несохранённые изменения. Уйти без сохранения?")) return;
    if (dirty[tab]) markDirty(tab, false);
    setTab(b.dataset.tab);
  });
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
    if (!$("ordFrom").value) setDate($("ordFrom"), iso(new Date()));
    if (!$("ordTo").value && cfg) setDate($("ordTo"), cfg.season.end);
  }
  ["ordReload"].forEach(function (id) { $(id).addEventListener("click", loadOrders); });
  ["ordFrom", "ordTo", "ordStatus"].forEach(function (id) { $(id).addEventListener("change", loadOrders); });

  function years(a) { var n = parseInt(a, 10); return String(+a) === String(a).trim() && n > 0 && n < 19 ? n + " " + plural(n, "год", "года", "лет") : a; }
  function statusSelect(o) {
    return '<select class="st-select st-' + esc(o.status) + '" data-order="' + o.id + '" aria-label="Статус заявки № ' + o.id + '">' +
      Object.keys(STATUS).map(function (k) { return '<option value="' + k + '"' + (k === o.status ? " selected" : "") + ">" + STATUS[k] + "</option>"; }).join("") + "</select>";
  }
  function loadOrders() {
    defaultDates();
    api("orders", { from: $("ordFrom").value, to: $("ordTo").value }).then(function (r) {
      var st = $("ordStatus").value;
      var list = r.orders.filter(function (o) { return !st || o.status === st; });
      var active = list.filter(function (o) { return o.status !== "cancelled"; });
      var sum = active.reduce(function (s, o) { return s + +o.total; }, 0);
      $("ordSummary").textContent = list.length ? list.length + " " + plural(list.length, "заявка", "заявки", "заявок") + (sum ? " · на " + rub(sum) + (active.length < list.length ? " без отменённых" : "") : "") : "";
      if (!list.length) { $("orders").innerHTML = '<div class="card empty"><b>Заявок нет</b><p class="muted">За выбранные даты' + (st ? " с таким статусом" : "") + " заявок не было. Измените период или статус.</p></div>"; return; }
      $("orders").innerHTML = list.map(function (o) {
        var d = o.data || {}, ch = find(cfg.characters, d.character), pr = find(cfg.programs, d.program);
        var kids = (d.children || []).map(function (c) { return esc(c.name) + (c.age ? ", " + esc(years(c.age)) : ""); }).join("; ");
        var addons = (d.addons || []).map(function (id) { var a = find(cfg.addons, id); return a ? esc(a.title) : ""; }).filter(Boolean).join(", ");
        var extra = (addons ? "<dt>Допы</dt><dd>" + addons + "</dd>" : "") + (kids ? "<dt>Дети</dt><dd>" + kids + "</dd>" : "") + (d.comment ? "<dt>Пожелания</dt><dd>" + esc(d.comment) + "</dd>" : "");
        return '<article class="card order' + (o.status === "cancelled" ? " is-cancelled" : "") + '">' +
          '<div class="o-when"><b>' + human(o.visit_date) + "</b><span>" + esc(o.visit_time) + "</span><small>" + DAYS[new Date(o.visit_date + "T12:00").getDay()] + " · № " + o.id + "</small></div>" +
          '<div class="o-what"><b>' + esc(ch ? ch.title : d.character) + "</b><p>" + esc(pr ? pr.title + ", " + pr.minutes + " мин" : d.program) + " · " + esc(d.kids) + " " + plural(+d.kids || 0, "ребёнок", "ребёнка", "детей") + "</p>" + (extra ? "<dl>" + extra + "</dl>" : "") + "</div>" +
          '<div class="o-who"><b>' + esc(o.name) + '</b><a href="tel:+' + esc(String(o.phone).replace(/\D/g, "")) + '">' + esc(fmtPhone(o.phone)) + "</a><p>" + esc(o.address) + "</p></div>" +
          '<div class="o-side"><div class="sum">' + rub(o.total) + "</div>" + statusSelect(o) +
          '<button type="button" class="o-del" data-del-order="' + o.id + '">Удалить</button></div></article>';
      }).join("");
    }).catch(function (e) { flash(e.message, true); });
  }
  $("orders").addEventListener("change", function (e) {
    var id = e.target.dataset.order; if (!id) return;
    e.target.className = "st-select st-" + e.target.value;
    api("order_status", { id: +id, status: e.target.value }).then(function () { flash("Статус заявки № " + id + ": " + STATUS[e.target.value].toLowerCase()); loadOrders(); }).catch(function (err) { flash(err.message, true); });
  });
  $("orders").addEventListener("click", function (e) {
    var id = e.target.dataset.delOrder; if (!id) return;
    if (!confirm("Удалить заявку № " + id + " навсегда? Данные клиента тоже удалятся, восстановить их будет нельзя.\n\nЕсли заказ просто не состоялся, лучше поставьте статус «Отменена».")) return;
    api("order_delete", { id: +id }).then(function () { flash("Заявка № " + id + " удалена"); loadOrders(); }).catch(function (err) { flash(err.message, true); });
  });
  function find(list, id) { return (list || []).filter(function (x) { return x.id === id; })[0]; }

  /* ---------- расписание ---------- */
  function trips(n) { return n + " " + plural(n, "выезд", "выезда", "выездов"); }
  var schSlots = [];
  function shiftDay(n) { var d = new Date($("schDate").value + "T12:00"); d.setDate(d.getDate() + n); setDate($("schDate"), iso(d)); loadSchedule(); }
  $("schPrev").addEventListener("click", function () { shiftDay(-1); });
  $("schNext").addEventListener("click", function () { shiftDay(1); });
  $("schDate").addEventListener("change", loadSchedule);
  function loadSchedule() {
    if (!$("schDate").value) { var t = new Date(); t.setDate(t.getDate() + 1); setDate($("schDate"), iso(t)); }
    var day = $("schDate").value;
    $("schDayName").textContent = human(day) + ", " + DAYS[new Date(day + "T12:00").getDay()];
    $("schPairs").textContent = trips(cfg.pairs);
    api("schedule", { date: day }).then(function (r) {
      schSlots = r.slots;
      $("schSlots").innerHTML = r.slots.length ? r.slots.map(function (s, i) {
        var free = Math.max(0, s.total - s.busy), pct = Math.min(100, Math.round(s.busy / s.total * 100));
        return '<div class="slot-row"><b>' + s.time + '</b><div class="meter"><i class="' + (pct >= 100 ? "full" : pct >= 50 ? "mid" : "") + '" data-w="' + pct + '"></i></div>' +
          "<span>" + (free ? "свободно " + free + " из " + s.total : "занято") + "</span>" +
          (free ? '<button class="btn ghost small" type="button" data-close="' + i + '">Закрыть</button>' : "<span></span>") + "</div>";
      }).join("") : '<p class="muted">В этот день записи нет: он вне сезона.</p>';
      // ширину задаём из скрипта: атрибут style запрещён политикой безопасности
      document.querySelectorAll("#schSlots .meter i").forEach(function (i) { i.style.width = i.dataset.w + "%"; });
      $("closeDay").hidden = !r.slots.some(function (s) { return s.busy < s.total; });
      $("blockTime").innerHTML = r.slots.map(function (s) { return "<option" + (s.busy >= s.total ? " disabled" : "") + ">" + s.time + "</option>"; }).join("");
      // часы одного заказа по телефону показываем одной строкой: «12:00–14:00»
      var groups = [], byGrp = {};
      r.blocks.forEach(function (b) {
        var key = b.grp ? "g" + b.grp : "i" + b.id;
        if (!byGrp[key]) { byGrp[key] = { id: b.id, from: b.visit_time, to: b.visit_time, teams: +b.teams, note: b.note }; groups.push(byGrp[key]); }
        else byGrp[key].to = b.visit_time;
      });
      $("schBlocks").innerHTML = groups.length ? groups.map(function (g) {
        var what = g.teams >= cfg.pairs ? "закрыто полностью" : "занят" + (g.teams > 1 ? "о " : " ") + trips(g.teams);
        var end = (parseInt(g.to, 10) + 1) % 24;
        var when = g.from === g.to ? g.from : g.from + "–" + pad(end) + ":00";
        return '<div class="list-row"><span><b>' + esc(when) + "</b> · " + what + (g.note ? " · " + esc(g.note) : "") + '</span><button class="btn ghost small" data-block="' + g.id + '">Вернуть</button></div>';
      }).join("") : '<p class="muted">Ничего не закрыто</p>';
      $("blockProgram").innerHTML = cfg.programs.map(function (p) { return '<option value="' + esc(p.id) + '">' + esc(p.title) + ", " + esc(p.minutes) + " мин</option>"; }).join("");
      $("schOrders").innerHTML = r.orders.length ? r.orders.map(function (o) {
        return '<div class="list-row"><span><b>' + esc(o.visit_time) + "</b> " + esc(o.name) + " · " + esc(o.address) + '</span><span class="st st-' + esc(o.status) + '">' + STATUS[o.status] + "</span></div>";
      }).join("") : '<p class="muted">Заявок на этот день нет</p>';
    }).catch(function (e) { flash(e.message, true); });
  }
  function closeSlots(list) {
    var day = $("schDate").value;
    return list.reduce(function (p, s) {
      return p.then(function () { return api("block_add", { date: day, time: s.time, teams: s.total - s.busy, note: "" }); });
    }, Promise.resolve());
  }
  $("schSlots").addEventListener("click", function (e) {
    var i = e.target.dataset.close; if (i == null) return;
    var s = schSlots[+i];
    closeSlots([s]).then(function () { flash(s.time + " закрыто"); loadSchedule(); }).catch(function (err) { flash(err.message, true); });
  });
  $("closeDay").addEventListener("click", function () {
    var open = schSlots.filter(function (s) { return s.busy < s.total; });
    if (!open.length || !confirm("Закрыть весь день " + human($("schDate").value) + "? На сайте на этот день больше не запишутся. Заявки, которые уже есть, останутся.")) return;
    closeSlots(open).then(function () { flash("День закрыт"); loadSchedule(); }).catch(function (err) { flash(err.message, true); loadSchedule(); });
  });
  $("blockForm").addEventListener("submit", function (e) {
    e.preventDefault(); var f = e.target;
    api("block_add", { date: $("schDate").value, time: f.time.value, program: f.program.value, teams: 1, note: f.note.value || "Заказ по телефону" }).then(function () { f.note.value = ""; flash("Заказ записан: с " + f.time.value + " занят один выезд на время программы и дороги"); loadSchedule(); }).catch(function (err) { flash(err.message, true); });
  });
  $("schBlocks").addEventListener("click", function (e) {
    var id = e.target.dataset.block; if (!id) return;
    api("block_remove", { id: +id }).then(function () { flash("Время снова открыто"); loadSchedule(); }).catch(function (err) { flash(err.message, true); });
  });

  /* ---------- редактор строк ---------- */
  // fields: [{k, label, type: text|number|date|time|select|percent|mult, options, num}]
  function rowsEditor(title, hint, rows, fields, newRow, max) {
    var box = document.createElement("div"); box.className = "card ed";
    var data = clone(rows || []);
    function cell(f, r, i) {
      var v = r[f.k];
      if (f.type === "photo") return imgBox("char", r.id, r.photo);
      if (f.type === "select") return "<select data-i=\"" + i + "\" data-k=\"" + f.k + "\">" + f.options.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] === v ? " selected" : "") + ">" + esc(o[1]) + "</option>"; }).join("") + "</select>";
      if (f.type === "percent") v = v == null ? "" : Math.round(v * 100);
      var type = f.type === "percent" || f.type === "number" || f.type === "mult" ? "number" : (f.type || "text");
      var step = f.type === "mult" ? ' step="0.05" min="0.1"' : f.type === "percent" ? ' step="1" min="0" max="100"' : f.type === "number" ? ' step="1" min="0"' : "";
      return '<input type="' + type + '"' + step + ' data-i="' + i + '" data-k="' + f.k + '" value="' + esc(v) + '"' + (f.ph ? ' placeholder="' + esc(f.ph) + '"' : "") + ">";
    }
    function draw() {
      box.innerHTML = "<h2>" + esc(title) + "</h2>" + (hint ? '<p class="muted">' + esc(hint) + "</p>" : "") +
        "<table><thead><tr>" + fields.map(function (f) { return '<th class="' + (f.num ? "num" : "") + '">' + esc(f.label) + "</th>"; }).join("") + (max === 0 ? "" : "<th></th>") + "</tr></thead><tbody>" +
        data.map(function (r, i) { return "<tr>" + fields.map(function (f) { return '<td class="' + (f.num ? "num" : "") + '">' + cell(f, r, i) + "</td>"; }).join("") + (max === 0 ? "" : '<td class="del-cell"><button type="button" class="del" data-del="' + i + '" aria-label="Удалить строку" title="Удалить строку">×</button></td>') + "</tr>"; }).join("") +
        "</tbody></table>" + (data.length ? "" : '<p class="ed-empty">Пока ничего нет</p>') +
        (max === 0 || (max && data.length >= max) ? "" : '<button type="button" class="btn ghost small add">+ Добавить</button>');
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
    box.addEventListener("photo", function (e) {
      data.forEach(function (r) { if (r.id === e.detail.id) { if (e.detail.path) r.photo = e.detail.path; else delete r.photo; } });
      draw(); box.dispatchEvent(new CustomEvent("changed"));
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
    ed.characters = rowsEditor("Кто приедет", "Кто едет на праздник. «Иконка» показывается, пока не загружено своё фото: лучше квадратное, персонажи крупно. «Бейдж» — плашка на карточке, например «Чаще всего выбирают».", cfg.characters,
      [{ k: "photo", label: "Фото", type: "photo" }, { k: "title", label: "Название" }, { k: "note", label: "Подпись" }, { k: "icon", label: "Иконка", type: "select", options: [["santa", "Дед Мороз"], ["duo", "Дед Мороз и Снегурочка"], ["trio", "Трио со Снеговиком"]] }, { k: "badge", label: "Бейдж" }],
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
        chs.map(function (c) { return "<tr><td>" + esc(c.title || "Состав") + "</td>" + prs.map(function (p) { var v = (prices[c.id] || {})[p.id]; return '<td><input type="number" min="0" step="100" data-c="' + esc(c.id) + '" data-p="' + esc(p.id) + '" value="' + esc(v == null ? "" : v) + '"></td>'; }).join("") + "</tr>"; }).join("") + "</tbody></table>";
    }
    matrix.addEventListener("input", function (e) { var c = e.target.dataset.c, p = e.target.dataset.p; if (!c) return; prices[c] = prices[c] || {}; prices[c][p] = e.target.value === "" ? null : +e.target.value; });
    ed.characters.addEventListener("changed", drawMatrix); ed.programs.addEventListener("changed", drawMatrix);
    drawMatrix();
    ed.matrix = { get: function () { var out = {}; ed.characters.get().forEach(function (c) { out[c.id] = {}; ed.programs.get().forEach(function (p) { var v = (prices[c.id] || {})[p.id]; if (v != null) out[c.id][p.id] = v; }); }); return out; } };

    ed.kids = rowsEditor("Дети", "Сколько детей входит в цену, максимум на один заказ и доплата за каждого сверх нормы.", [cfg.kids],
      [{ k: "included", label: "Входит в цену", type: "number", num: 1 }, { k: "max", label: "Максимум", type: "number", num: 1 }, { k: "extraPrice", label: "Доплата за ребёнка, ₽", type: "number", num: 1 }], function () { return { included: 5, max: 15, extraPrice: 300 }; }, 0);
    ed.dateRules = rowsEditor("Наценки по датам", "Коэффициент: 1 — обычная цена, 1,5 — на 50% дороже. Подпись видит клиент.", cfg.dateRules,
      [{ k: "from", label: "С", type: "date" }, { k: "to", label: "По", type: "date" }, { k: "mult", label: "Коэффициент", type: "mult", num: 1 }, { k: "label", label: "Подпись" }],
      function () { return { from: cfg.season.start, to: cfg.season.start, mult: 1, label: "" }; });
    ed.timeRules = rowsEditor("Наценки по времени", "Например, вечер 31 декабря после 19:00.", cfg.timeRules,
      [{ k: "date", label: "Дата", type: "date" }, { k: "after", label: "Начиная с", type: "time" }, { k: "mult", label: "Коэффициент", type: "mult", num: 1 }, { k: "label", label: "Подпись" }],
      function () { return { date: cfg.season.end, after: "19:00", mult: 2, label: "" }; });
    ed.demand = rowsEditor("Наценка за спрос", "Когда свободных выездов на это время остаётся мало, цена растёт. Удалите строки, если наценка не нужна.", cfg.demandPricing,
      [{ k: "freeShareAtMost", label: "Свободно не больше, %", type: "percent", num: 1 }, { k: "plus", label: "Наценка, %", type: "percent", num: 1 }, { k: "label", label: "Подпись для клиента" }],
      function () { return { freeShareAtMost: 0.5, plus: 0.1, label: "Это время популярно" }; });
    ed.promo = rowsEditor("Скидка за раннее бронирование", "Действует для заказов, оформленных до указанной даты. Если скидка не нужна, удалите строку.", cfg.promo ? [cfg.promo] : [],
      [{ k: "percent", label: "Скидка, %", type: "number", num: 1 }, { k: "until", label: "Для заказов до", type: "date" }, { k: "text", label: "Текст" }],
      function () { return { percent: 10, until: cfg.season.start, text: "" }; }, 1);
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
    api("config_save", { config: c }).then(function (r) { cfg = r.config || c; flash("Сохранено. Изменения уже на странице записи."); if (me.agency) { me.agency.name = c.name; renderHeader(); } })
      .catch(function (e) { flash(e.message, true); });
  }

  /* ---------- настройки ---------- */
  var SETTINGS = [
    ["Основное", [["name", "Название агентства"], ["city", "Город"], ["phone", "Телефон на странице"], ["colors.accent", "Основной цвет", "color"], ["colors.gold", "Акцентный цвет", "color"]]],
    ["Сезон и часы", [["season.start", "Сезон с", "date"], ["season.end", "Сезон по", "date"], ["hours.from", "Первый визит в", "time"], ["hours.to", "Последний визит в", "time"], ["hours.lastDay.date", "Особый день", "date"], ["hours.lastDay.to", "Особый день: последний визит", "time"], ["pairs", "Сколько выездов одновременно", "number"], ["travelMinutes", "Дорога между визитами, мин", "number"], ["leadHours", "Заказ минимум за, часов", "number"], ["timezone", "Часовой пояс", "tz"]]],
    ["Баннер внизу страницы записи", [["banner.title", "Заголовок (пусто — баннера не будет)"], ["banner.button", "Текст кнопки"], ["banner.text", "Текст", "area"], ["banner.link", "Куда ведёт кнопка: https://… или tel:+7…; пусто — к началу записи"]]],
    ["Реквизиты для согласия и политики", [["legal.operator", "Оператор (ИП или ООО, ФИО)"], ["legal.inn", "ИНН"], ["legal.ogrn", "ОГРН / ОГРНИП"], ["legal.address", "Адрес"], ["legal.email", "Почта для обращений"], ["legal.keepMonths", "Хранить данные после визита, мес.", "number"], ["legal.updated", "Дата редакции политики"], ["legal.policyUrl", "Своя политика: ссылка (если есть)"]]]
  ];
  var BANNER_DEFAULT = { title: "Подарите детям настоящий Новый год!", text: "Забронируйте визит Деда Мороза и создайте волшебство в каждом доме.", button: "Забронировать праздник", link: "" };
  function getPath(o, p) { return p.split(".").reduce(function (a, k) { return a == null ? undefined : a[k]; }, o); }
  function setPath(o, p, v) { var ks = p.split("."), last = ks.pop(); ks.forEach(function (k) { if (o[k] == null || typeof o[k] !== "object") o[k] = {}; o = o[k]; }); o[last] = v; }
  function renderSettings() {
    var root = $("settingsForm");
    var logoCard = '<div class="card"><h2>Оформление страницы записи</h2><div class="design-grid">' +
      '<div><h3>Логотип</h3><p class="muted">Вместо снежинки рядом с названием. PNG, JPG или WebP, лучше на прозрачном фоне.</p>' + imgBox("logo", "", cfg.logo) + "</div>" +
      '<div><h3>Фон первого экрана</h3><p class="muted">Своя фотография вместо общей зимней картинки. Лучше горизонтальная, от 1600 px в ширину.</p>' + imgBox("hero", "", cfg.heroImage) + "</div>" +
      "</div></div>";
    root.innerHTML = logoCard + SETTINGS.map(function (g) {
      return '<div class="card"><h2>' + esc(g[0]) + '</h2><div class="fields">' + g[1].map(function (f) {
        var v = getPath(cfg, f[0]); if (f[0] === "travelMinutes" && v == null) v = 30; if (f[0] === "leadHours" && v == null) v = 3;
        if (f[2] === "color" && (!v || v.toUpperCase() === "#163A2F" || v.toUpperCase() === "#C9A24A")) v = f[0] === "colors.gold" ? "#D9B26A" : "#B3203A";
        if (/^banner\./.test(f[0]) && v == null) v = BANNER_DEFAULT[f[0].slice(7)];
        if (f[2] === "area") return '<label class="wide">' + esc(f[1]) + '<textarea data-path="' + f[0] + '" rows="2">' + esc(v || "") + "</textarea></label>";
        if (f[2] === "tz") return "<label>" + esc(f[1]) + '<select data-path="' + f[0] + '">' + TIMEZONES.map(function (z) { return '<option value="' + z[0] + '"' + (z[0] === (v || "Europe/Moscow") ? " selected" : "") + ">" + z[1] + "</option>"; }).join("") + "</select></label>";
        return "<label>" + esc(f[1]) + '<input data-path="' + f[0] + '" type="' + (f[2] || "text") + '" value="' + esc(v == null ? "" : v) + '"></label>';
      }).join("") + "</div></div>";
    }).join("") +
      '<div class="card"><h2>Уведомления о новых заявках</h2>' +
      '<p class="muted">В уведомлении будут дата, время, программа и сумма. Имя, телефон и адрес клиента видны только здесь, во вкладке «Заявки».</p>' +
      '<div class="notify-row"><b>Telegram</b><span id="tgStatus">Проверяю…</span></div><div class="notify-acts" id="tgActs"></div>' +
      '<div class="fields"><label>Почта, можно несколько через запятую<input data-path="notify.email" type="text" placeholder="zayavki@agency.ru" value="' + esc(getPath(cfg, "notify.email") || "") + '"></label></div>' +
      '<p class="muted">Новую почту сначала сохраните кнопкой внизу, потом проверьте.</p>' +
      '<button class="btn ghost small" id="notifyTest" type="button">Отправить проверочное уведомление</button></div>' +
      (me.role === "super" ? '<div class="card"><h2>Только для владельца сервиса</h2><label class="row"><span><input type="checkbox" id="demoFlag"' + (cfg.demo ? " checked" : "") + "> Демо-режим (заявки не принимаются)</span></label></div>" : "");
    loadNotify();
  }
  var TIMEZONES = [["Europe/Kaliningrad", "Калининград (МСК−1)"], ["Europe/Moscow", "Москва (МСК)"], ["Europe/Samara", "Самара (МСК+1)"], ["Asia/Yekaterinburg", "Екатеринбург (МСК+2)"], ["Asia/Omsk", "Омск (МСК+3)"], ["Asia/Krasnoyarsk", "Красноярск, Новосибирск (МСК+4)"], ["Asia/Irkutsk", "Иркутск (МСК+5)"], ["Asia/Yakutsk", "Якутск (МСК+6)"], ["Asia/Vladivostok", "Владивосток (МСК+7)"], ["Asia/Magadan", "Магадан (МСК+8)"], ["Asia/Kamchatka", "Камчатка (МСК+9)"]];

  /* ---------- картинки: логотип, фон, фото составов ---------- */
  var IMG = {
    logo: { max: 400, empty: "Нет логотипа", def: "" },
    hero: { max: 1920, empty: "Общая зимняя картинка", def: "../assets/img/winter-m.webp" },
    char: { max: 640, empty: "Иконка", def: "" }
  };
  function imgBox(kind, charId, path) {
    var k = IMG[kind], has = /^uploads\//.test(path || "");
    var prev = has ? '<img src="../' + esc(path) + '" alt="">' : (k.def ? '<img src="' + k.def + '" alt=""><span class="img-tag">' + k.empty + "</span>" : "<span>" + k.empty + "</span>");
    return '<div class="img-box img-' + kind + '" data-kind="' + kind + '" data-char="' + esc(charId) + '"><div class="img-prev">' + prev + "</div>" +
      '<div class="acts"><label class="btn ghost small file-btn">' + (has ? "Заменить" : "Загрузить") + '<input type="file" class="img-file" accept="image/png,image/jpeg,image/webp" hidden></label>' +
      (has ? '<button class="btn danger small img-remove" type="button">' + (kind === "hero" ? "Вернуть общую" : "Убрать") + "</button>" : "") + "</div></div>";
  }
  // Уменьшаем картинку в браузере: файл лёгкий, страница записи грузится быстро
  function shrinkImage(file, max) {
    return new Promise(function (ok, bad) {
      if (!/^image\/(png|jpeg|webp)$/.test(file.type)) return bad(new Error("Нужна картинка PNG, JPG или WebP"));
      if (file.size > 25e6) return bad(new Error("Файл слишком большой"));
      var url = URL.createObjectURL(file), img = new Image();
      img.onload = function () {
        var k = Math.min(1, max / Math.max(img.width, img.height)), c = document.createElement("canvas");
        c.width = Math.round(img.width * k); c.height = Math.round(img.height * k);
        c.getContext("2d").drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        var data = c.toDataURL("image/webp", 0.88);
        ok(data.indexOf("data:image/webp") === 0 ? data : c.toDataURL("image/jpeg", 0.88));
      };
      img.onerror = function () { URL.revokeObjectURL(url); bad(new Error("Не получилось открыть картинку")); };
      img.src = url;
    });
  }
  function imgDone(box, path) {
    var kind = box.dataset.kind, ch = box.dataset.char;
    if (kind === "logo") { if (path) cfg.logo = path; else delete cfg.logo; }
    if (kind === "hero") { if (path) cfg.heroImage = path; else delete cfg.heroImage; }
    if (kind === "char") {
      (cfg.characters || []).forEach(function (c) { if (c.id === ch) { if (path) c.photo = path; else delete c.photo; } });
      var ed = box.closest(".ed"); if (ed) ed.dispatchEvent(new CustomEvent("photo", { detail: { id: ch, path: path } }));
      return;
    }
    box.outerHTML = imgBox(kind, ch, path);
  }
  document.addEventListener("change", function (e) {
    if (!e.target.classList || !e.target.classList.contains("img-file") || !e.target.files[0]) return;
    var box = e.target.closest(".img-box"), kind = box.dataset.kind;
    shrinkImage(e.target.files[0], IMG[kind].max).then(function (data) { return api("image_upload", { kind: kind, char: box.dataset.char, data: data }); })
      .then(function (r) { imgDone(box, r.path); flash(kind === "char" ? "Фото загружено. Если состав новый, сохраните цены" : "Картинка загружена, она уже на странице записи"); })
      .catch(function (err) { flash(err.message, true); });
  }, true);
  document.addEventListener("click", function (e) {
    if (!e.target.classList || !e.target.classList.contains("img-remove")) return;
    var box = e.target.closest(".img-box"), kind = box.dataset.kind;
    if (!confirm(kind === "hero" ? "Вернуть общую зимнюю картинку?" : "Убрать картинку? Вместо неё будет иконка.")) return;
    api("image_remove", { kind: kind, char: box.dataset.char }).then(function () { imgDone(box, ""); flash("Готово"); }).catch(function (err) { flash(err.message, true); });
  });

  /* ---------- уведомления ---------- */
  function loadNotify() {
    api("notify_get").then(function (r) {
      var st = $("tgStatus"), acts = $("tgActs"); if (!st) return;
      if (!r.tgReady) { st.textContent = "пока недоступно"; acts.innerHTML = ""; return; }
      st.textContent = r.tgChats ? "подключено, чатов: " + r.tgChats : "не подключено";
      acts.innerHTML = '<button class="btn small" type="button" id="tgConnect">' + (r.tgChats ? "Подключить ещё один чат" : "Подключить Telegram") + "</button>" +
        (r.tgChats ? '<button class="btn danger small" type="button" id="tgOff">Отключить</button>' : "");
    }).catch(function (e) { flash(e.message, true); });
  }
  $("settingsForm").addEventListener("click", function (e) {
    var id = e.target.id;
    if (id === "tgConnect") {
      e.target.disabled = true;
      api("tg_link").then(function (r) {
        $("tgActs").innerHTML = '<a class="btn small" target="_blank" rel="noopener noreferrer" href="' + esc(r.url) + '">Открыть Telegram</a>' +
          '<button class="btn ghost small" type="button" id="tgCheck">Я нажал «Старт»</button>' +
          '<p class="muted">В Telegram нажмите «Старт» в боте. Ссылка работает один час. Каждый сотрудник может подключить свой Telegram.</p>';
      }).catch(function (err) { e.target.disabled = false; flash(err.message, true); });
    }
    if (id === "tgCheck") loadNotify();
    if (id === "tgOff" && confirm("Отключить уведомления в Telegram для всех подключённых чатов?")) api("tg_off").then(function () { flash("Telegram отключён"); loadNotify(); }).catch(function (err) { flash(err.message, true); });
    if (id === "notifyTest") api("notify_test").then(function (r) {
      var parts = [];
      if (r.tg) parts.push("Telegram: " + r.tg);
      if (r.email) parts.push("почта: " + r.email);
      flash(parts.length ? "Проверочное уведомление отправлено (" + parts.join(", ") + ")" : "Некуда отправить: подключите Telegram или сохраните почту", !parts.length);
    }).catch(function (err) { flash(err.message, true); });
  });
  $("saveSettings").addEventListener("click", function () {
    var c = clone(cfg);
    document.querySelectorAll("#settingsForm [data-path]").forEach(function (i) {
      var v = i.type === "number" ? (i.value === "" ? 0 : +i.value) : i.value.trim();
      if (i.dataset.path === "colors.accent" && v.toUpperCase() === "#B3203A") v = "";   // цвета по умолчанию не сохраняем
      if (i.dataset.path === "colors.gold" && v.toUpperCase() === "#D9B26A") v = "";
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
    ["Первый экран", [["heroBadge", "Плашка над заголовком"], ["heroTitle", "Заголовок, белая часть"], ["heroAccent", "Заголовок, золотая строка"], ["heroLead", "Подзаголовок", "area"], ["demoButton", "Кнопка демо"], ["loginLink", "Ссылка «Вход для агентств»"], ["contactButton", "Красная кнопка в шапке"]]],
    ["Блок возможностей", [["itemsTitle", "Заголовок"], ["itemsLead", "Текст под заголовком", "area"], ["stepsTitle", "Заголовок шагов"], ["faqTitle", "Заголовок вопросов"]]],
    ["Баннер внизу", [["finalTitle", "Заголовок"], ["finalText", "Текст", "area"], ["finalDemoButton", "Кнопка демо"], ["finalContactButton", "Кнопка Telegram"]]],
    ["Ссылки и подвал", [["telegram", "Telegram (https://t.me/...)"], ["demoSlug", "Адрес демо-агентства"], ["footer", "Строка слева в подвале"], ["footerTagline", "Строка справа в подвале"]]]
  ];
  function loadSite() {
    $("siteForm").innerHTML = '<div class="card empty"><p class="muted">Загружаю тексты главной…</p></div>';
    api("site_get").then(function (r) {
      if (r.landing) return r.landing;
      return null;
    }).then(function (saved) {
      return fetch("../assets/landing.json", { cache: "no-store" }).then(function (x) { return x.json(); }).then(function (def) {
        // тексты, сохранённые до нового дизайна, дополняем новыми полями по умолчанию
        if (!saved) return def;
        if (saved.heroBadge == null) saved = { telegram: saved.telegram, demoSlug: saved.demoSlug, items: saved.items, steps: saved.steps, faq: saved.faq };
        Object.keys(def).forEach(function (k) { if (saved[k] == null || (Array.isArray(saved[k]) && !saved[k].length)) saved[k] = def[k]; });
        return saved;
      });
    }).then(function (d) {
      siteData = d;
      var root = $("siteForm");
      root.innerHTML = SITE_FIELDS.map(function (g) {
        return '<div class="card"><h2>' + esc(g[0]) + '</h2><div class="fields">' + g[1].map(function (f) {
          var v = esc(d[f[0]] || "");
          return "<label>" + esc(f[1]) + (f[2] === "area" ? '<textarea data-site="' + f[0] + '" rows="3">' + v + "</textarea>" : '<input data-site="' + f[0] + '" value="' + v + '">') + "</label>";
        }).join("") + "</div></div>";
      }).join("");
      siteEd.strip = rowsEditor("Полоса под первым экраном", "Три коротких пункта с иконками.", d.strip, [{ k: "title", label: "Текст" }], function () { return { title: "" }; }, 3);
      siteEd.items = rowsEditor("Карточки возможностей", "", d.items, [{ k: "title", label: "Заголовок" }, { k: "text", label: "Текст" }], function () { return { title: "", text: "" }; });
      siteEd.steps = rowsEditor("Шаги «Как подключиться»", "", d.steps, [{ k: "title", label: "Шаг" }, { k: "text", label: "Пояснение" }], function () { return { title: "", text: "" }; });
      siteEd.faq = rowsEditor("Частые вопросы", "Здесь же цена подключения.", d.faq, [{ k: "q", label: "Вопрос" }, { k: "a", label: "Ответ" }], function () { return { q: "", a: "" }; });
      [siteEd.strip, siteEd.items, siteEd.steps, siteEd.faq].forEach(function (x) { root.appendChild(x); });
    }).catch(function (e) {
      $("siteForm").innerHTML = '<div class="card empty"><b>Не удалось загрузить тексты</b><p class="muted">' + esc(e.message) + '</p><button class="btn ghost small" type="button" id="siteRetry">Попробовать ещё раз</button></div>';
      $("siteRetry").addEventListener("click", loadSite);
    });
  }
  $("saveSite").addEventListener("click", function () {
    var d = clone(siteData || {});
    document.querySelectorAll("#siteForm [data-site]").forEach(function (i) { d[i.dataset.site] = i.value.trim(); });
    d.strip = siteEd.strip.get(); d.items = siteEd.items.get(); d.steps = siteEd.steps.get(); d.faq = siteEd.faq.get();
    api("site_save", { landing: d }).then(function () { siteData = d; flash("Главная сохранена"); }).catch(function (e) { flash(e.message, true); });
  });

  /* ---------- агентства (владелец) ---------- */
  function loadAgencies() {
    api("agencies").then(function (r) {
      $("agencies").innerHTML = r.agencies.map(function (a) {
        return '<div class="card agency-row"><div><b>' + esc(a.name) + "</b>" + (a.demo ? ' <span class="st st-new">демо</span>' : "") + (a.active ? "" : ' <span class="st st-cancelled">выключено</span>') + (a.demo || a.legal ? "" : ' <span class="st st-warn" title="Без них согласие и политика на странице записи неполные">нет реквизитов</span>') +
          '<br><small class="muted">/' + esc(a.slug) + (a.city ? " · " + esc(a.city) : "") + " · вход: " + esc(a.login || "не создан") + "</small></div>" +
          "<div class=\"ag-count\"><b>" + a.orders + "</b> " + plural(a.orders, "заявка", "заявки", "заявок") + "</div>" +
          '<div class="acts"><button class="btn small" data-open="' + a.id + '">Открыть</button>' +
          '<button class="btn ghost small" data-user="' + a.id + '" data-login="' + esc(a.login || a.slug) + '" data-slug="' + esc(a.slug) + '">Вход для агентства</button>' +
          '<button class="btn ' + (a.active ? "danger" : "ghost") + ' small" data-toggle="' + a.id + '" data-active="' + (a.active ? 0 : 1) + '">' + (a.active ? "Выключить" : "Включить") + "</button>" +
          (a.slug === "demo" ? "" : '<button class="btn danger small" data-del="' + a.id + '" data-slug="' + esc(a.slug) + '">Удалить</button>') + "</div></div>";
      }).join("") || '<div class="card empty">Агентств пока нет</div>';
    }).catch(function (e) { flash(e.message, true); });
  }
  /* ---------- несохранённые изменения ---------- */
  var dirty = {};
  function markDirty(t, on) {
    dirty[t] = on;
    var bar = document.querySelector("#tab-" + t + " .save-bar");
    if (bar) bar.classList.toggle("is-dirty", on);
  }
  ["prices", "settings", "site"].forEach(function (t) {
    var sec = $("tab-" + t);
    ["input", "change", "changed"].forEach(function (ev) {
      sec.addEventListener(ev, function (e) {
        if (e.target.closest && e.target.closest(".save-bar, #pwForm, #tgActs")) return;
        if (e.target.id === "notifyTest" || e.target.classList.contains("img-file") || e.target.classList.contains("img-remove")) return;
        markDirty(t, true);
      }, true);
    });
    sec.querySelector(".save-bar .btn").addEventListener("click", function () { setTimeout(function () { if (!document.querySelector("#flash.bad:not([hidden])")) markDirty(t, false); }, 900); });
  });
  window.addEventListener("beforeunload", function (e) { if (Object.keys(dirty).some(function (k) { return dirty[k]; })) { e.preventDefault(); e.returnValue = ""; } });

  /* ---------- даты и время: всегда ДД.ММ.ГГГГ и 24 часа, независимо от языка браузера ---------- */
  var MONTHS_NOM = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];
  function isoToRu(v) { return /^\d{4}-\d{2}-\d{2}$/.test(v || "") ? v.slice(8, 10) + "." + v.slice(5, 7) + "." + v.slice(0, 4) : ""; }
  function ruToIso(v) {
    var m = /^(\d{2})\.(\d{2})\.(\d{4})$/.exec(v); if (!m) return null;
    var d = new Date(+m[3], +m[2] - 1, +m[1]);
    return d.getMonth() === +m[2] - 1 && d.getDate() === +m[1] ? m[3] + "-" + m[2] + "-" + m[1] : null;
  }
  function setDate(inp, v) { inp.value = v || ""; if (inp._txt) inp._txt.value = isoToRu(v); }
  function commitDate(txt, v) {
    var h = txt._iso; if (h.value === v) return;
    h.value = v;
    h.dispatchEvent(new Event("input", { bubbles: true }));
    h.dispatchEvent(new Event("change", { bubbles: true }));
  }
  function upgradeDate(inp) {
    var txt = document.createElement("input");
    txt.type = "text"; txt.className = "dt-text"; txt.inputMode = "numeric"; txt.placeholder = "ДД.ММ.ГГГГ"; txt.maxLength = 10; txt.autocomplete = "off";
    inp.type = "hidden";
    inp.parentNode.insertBefore(txt, inp);
    inp._txt = txt; txt._iso = inp; txt.value = isoToRu(inp.value);
  }
  function upgradeTime(inp) {
    var sel = document.createElement("select"), v = inp.value, times = [];
    for (var h = 0; h < 24; h++) for (var m = 0; m < 60; m += 30) times.push(pad(h) + ":" + pad(m));
    if (v && times.indexOf(v) < 0) { times.push(v); times.sort(); }
    Array.prototype.forEach.call(inp.attributes, function (a) { if (["type", "value", "step"].indexOf(a.name) < 0) sel.setAttribute(a.name, a.value); });
    sel.innerHTML = (v ? "" : '<option value="">—</option>') + times.map(function (t) { return "<option" + (t === v ? " selected" : "") + ">" + t + "</option>"; }).join("");
    inp.parentNode.replaceChild(sel, inp);
  }
  function upgradeAll() {
    document.querySelectorAll('input[type="date"]').forEach(upgradeDate);
    document.querySelectorAll('input[type="time"]').forEach(upgradeTime);
  }
  upgradeAll();
  new MutationObserver(upgradeAll).observe(document.body, { childList: true, subtree: true });

  var pop = document.createElement("div"), popOwner = null, popMonth = null;
  pop.className = "dt-pop"; pop.hidden = true; document.body.appendChild(pop);
  function drawPop() {
    var y = popMonth.getFullYear(), mo = popMonth.getMonth(), sel = popOwner._iso.value, today = iso(new Date());
    var first = (new Date(y, mo, 1).getDay() + 6) % 7, days = new Date(y, mo + 1, 0).getDate(), html = "";
    for (var i = 0; i < first; i++) html += "<span></span>";
    for (var d = 1; d <= days; d++) {
      var v = y + "-" + pad(mo + 1) + "-" + pad(d);
      html += '<button type="button" data-d="' + v + '" class="' + (v === sel ? "on " : "") + (v === today ? "today" : "") + '">' + d + "</button>";
    }
    pop.innerHTML = '<div class="dt-head"><button type="button" data-m="-1" aria-label="Предыдущий месяц">‹</button><b>' + MONTHS_NOM[mo] + " " + y +
      '</b><button type="button" data-m="1" aria-label="Следующий месяц">›</button></div><div class="dt-grid"><i>Пн</i><i>Вт</i><i>Ср</i><i>Чт</i><i>Пт</i><i>Сб</i><i>Вс</i>' + html + "</div>";
  }
  function openPop(t) {
    popOwner = t;
    var v = t._iso.value || iso(new Date());
    popMonth = new Date(+v.slice(0, 4), +v.slice(5, 7) - 1, 1);
    drawPop(); pop.hidden = false;
    var r = t.getBoundingClientRect();
    pop.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 272)) + window.scrollX + "px";
    pop.style.top = r.bottom + window.scrollY + 4 + "px";
  }
  function closePop() { pop.hidden = true; popOwner = null; }
  pop.addEventListener("mousedown", function (e) { e.preventDefault(); }); // фокус остаётся в поле
  pop.addEventListener("click", function (e) {
    var b = e.target.closest("button"); if (!b || !popOwner) return;
    if (b.dataset.m) { popMonth.setMonth(popMonth.getMonth() + +b.dataset.m); drawPop(); return; }
    var t = popOwner; t.value = isoToRu(b.dataset.d); t.classList.remove("bad"); commitDate(t, b.dataset.d); closePop();
  });
  document.addEventListener("focusin", function (e) { if (e.target.classList && e.target.classList.contains("dt-text")) openPop(e.target); });
  document.addEventListener("focusout", function (e) {
    var t = e.target; if (!t.classList || !t.classList.contains("dt-text")) return;
    if (popOwner === t) closePop();
    if (!t.value) commitDate(t, ""); // очистить поле можно, дата сбрасывается при выходе из него
    t.value = isoToRu(t._iso.value); t.classList.remove("bad");
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && popOwner) closePop(); });
  document.addEventListener("input", function (e) {
    var t = e.target; if (!t.classList || !t.classList.contains("dt-text")) return;
    var d = t.value.replace(/\D/g, "").slice(0, 8), out = d.slice(0, 2);
    if (d.length > 2) out += "." + d.slice(2, 4);
    if (d.length > 4) out += "." + d.slice(4);
    t.value = out;
    var v = ruToIso(out);
    t.classList.toggle("bad", out.length === 10 && !v);
    if (v) { commitDate(t, v); if (popOwner === t) { popMonth = new Date(+v.slice(0, 4), +v.slice(5, 7) - 1, 1); drawPop(); } }
  });

  /* ---------- окно с данными для входа ---------- */
  function genPass() {
    var abc = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789", a = new Uint32Array(12), out = "";
    crypto.getRandomValues(a);
    for (var i = 0; i < a.length; i++) out += abc[a[i] % abc.length];
    return out;
  }
  function showCreds(login, pass, slug) {
    var base = location.origin;
    $("credText").value =
      "Ваша страница онлайн-записи: " + base + "/" + slug + "\n" +
      "Личный кабинет: " + base + "/admin/\n" +
      "Логин: " + login + "\n" +
      "Пароль: " + pass + "\n\n" +
      "После первого входа смените пароль во вкладке «Настройки».";
    $("credCopy").textContent = "Скопировать";
    $("credDlg").showModal();
  }
  $("credCopy").addEventListener("click", function () {
    var ta = $("credText"), btn = this;
    function done() { btn.textContent = "Скопировано"; }
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(ta.value).then(done, fallback);
    else fallback();
    function fallback() { ta.select(); try { document.execCommand("copy"); done(); } catch (e) { btn.textContent = "Выделите текст и нажмите Ctrl+C"; } }
  });
  $("credClose").addEventListener("click", function () { $("credDlg").close(); });

  $("agencies").addEventListener("click", function (e) {
    var t = e.target;
    if (t.dataset.open) api("agency_open", { id: +t.dataset.open }).then(function (m) { start(m); tab = "orders"; }).catch(function (err) { flash(err.message, true); });
    if (t.dataset.toggle) api("agency_toggle", { id: +t.dataset.toggle, active: t.dataset.active === "1" }).then(loadAgencies);
    if (t.dataset.del) {
      var typed = prompt("Удалить агентство навсегда вместе со всеми заявками и входом?\n\nЭто нельзя отменить. Для подтверждения введите адрес агентства: " + t.dataset.slug);
      if (typed === null) return;
      if (typed.trim() !== t.dataset.slug) { flash("Адрес введён неверно, агентство не удалено", true); return; }
      api("agency_delete", { id: +t.dataset.del, confirm: typed.trim() }).then(function (m) { tab = "agencies"; start(m); flash("Агентство удалено"); }).catch(function (err) { flash(err.message, true); });
      return;
    }
    if (t.dataset.user) {
      var login = prompt("Логин для агентства", t.dataset.login); if (!login) return;
      var pass = genPass(), slug = t.dataset.slug;
      api("agency_user", { id: +t.dataset.user, login: login, password: pass }).then(function () {
        showCreds(login, pass, slug);
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
