(function () {
  "use strict";
  if (location.protocol === "http:" && !/^(localhost|127\.0\.0\.1|\[::1\])$/.test(location.hostname)) { location.replace("https://" + location.host + location.pathname + location.search + location.hash); return; }
  var $ = function (id) { return document.getElementById(id); };
  var token = "", resetEmail = "";

  function post(action, data) {
    return fetch("api/register.php?action=" + action, { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data || {}) })
      .catch(function () { throw new Error("Нет связи с сервером. Проверьте интернет."); })
      .then(function (r) { return r.json().catch(function () { return { error: "Сервер ответил с ошибкой (код " + r.status + ")" }; }).then(function (j) { if (!r.ok) { var e = new Error(j.error || "Ошибка"); e.status = r.status; throw e; } return j; }); });
  }
  function err(id, msg) { var e = $(id); e.textContent = msg || ""; e.hidden = !msg; }
  function busy(form, on) { var b = form.querySelector('button[type="submit"]'); b.disabled = on; b.classList.toggle("is-busy", on); }
  function show(id) {
    ["regForm", "codeForm", "regDone", "resetForm", "reset2Form", "resetDone"].forEach(function (x) { $(x).hidden = x !== id; });
    var f = $(id).querySelector("input:not(.hp)"); if (f) setTimeout(function () { f.focus(); }, 30);
  }

  /* ---------- адрес страницы из названия ---------- */
  var TR = { "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e", "ж": "zh", "з": "z", "и": "i", "й": "y", "к": "k", "л": "l", "м": "m", "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t", "у": "u", "ф": "f", "х": "h", "ц": "ts", "ч": "ch", "ш": "sh", "щ": "sch", "ъ": "", "ы": "y", "ь": "", "э": "e", "ю": "yu", "я": "ya" };
  function slugify(s) {
    return s.toLowerCase().replace(/[«»"'`]/g, "").split("").map(function (c) { return TR[c] != null ? TR[c] : c; }).join("")
      .replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 40).replace(/-+$/, "");
  }
  var f = $("regForm"), slugTouched = false, slugTimer = null;
  // Автозаполнение выключено по-настоящему: Chrome не слушает autocomplete="off" и узнаёт поля по имени,
  // поэтому имена полей случайные при каждом открытии страницы, а поле почты — обычное текстовое
  var F = {};
  ["name", "city", "phone", "email", "password", "slug", "agreeRules", "agreePd"].forEach(function (k) { F[k] = f.elements[k]; });
  Object.keys(F).forEach(function (k) {
    var el = F[k]; if (el.type === "checkbox") return;
    el.name = "f" + Math.random().toString(36).slice(2, 10);
    if (el.type !== "password") el.setAttribute("autocomplete", "x-" + Math.random().toString(36).slice(2, 8)); // незнакомое значение: подсказок нет
    if (el.type === "email") { el.type = "text"; el.inputMode = "email"; el.spellcheck = false; el.autocapitalize = "off"; }
  });
  // агентство или частный аниматор: подписи и подсказки меняются
  var kind = "agency";
  [].forEach.call(f.querySelectorAll('input[name="kind"]'), function (r) {
    r.addEventListener("change", function () {
      kind = r.value;
      $("nameLabel").dataset.t = kind === "solo" ? "Ваше имя или псевдоним" : "Название агентства";
      $("nameHint").textContent = kind === "solo" ? "Например «Дед Мороз Иван» — так вас увидят клиенты." : "Так его увидят клиенты на странице записи.";
    });
  });
  F.name.addEventListener("input", function () { if (!slugTouched) { F.slug.value = slugify(F.name.value); checkSlug(); } });
  F.slug.addEventListener("input", function () {
    slugTouched = true;
    var v = F.slug.value.toLowerCase().replace(/[^a-z0-9-]/g, "");
    if (v !== F.slug.value) F.slug.value = v;
    checkSlug();
  });
  function checkSlug() {
    clearTimeout(slugTimer);
    var v = F.slug.value, hint = $("slugHint");
    hint.className = "";
    if (!v) { hint.textContent = "Латинские буквы, цифры и дефис. Можно поменять до отправки."; return; }
    slugTimer = setTimeout(function () {
      post("slug", { slug: v }).then(function (r) {
        if (F.slug.value !== v) return;
        hint.textContent = r.ok ? "Адрес свободен: anisis.ru/" + v : r.error;
        hint.className = r.ok ? "ok" : "bad";
      }).catch(function () {});
    }, 400);
  }

  /* ---------- телефон: +7 (999) 123-45-67 ---------- */
  F.phone.addEventListener("input", function () {
    var d = F.phone.value.replace(/\D/g, "");
    if (d[0] === "8") d = "7" + d.slice(1);
    if (d && d[0] !== "7") d = "7" + d;
    d = d.slice(0, 11);
    var o = d ? "+7" : "";
    if (d.length > 1) o += " (" + d.slice(1, 4);
    if (d.length >= 4) o += ")";
    if (d.length > 4) o += " " + d.slice(4, 7);
    if (d.length > 7) o += "-" + d.slice(7, 9);
    if (d.length > 9) o += "-" + d.slice(9, 11);
    F.phone.value = o;
  });

  /* ---------- показать пароль ---------- */
  document.addEventListener("click", function (e) {
    var b = e.target.closest ? e.target.closest("[data-eye]") : null; if (!b) return;
    var i = b.parentNode.querySelector("input"), on = i.type === "password";
    i.type = on ? "text" : "password"; b.textContent = on ? "Скрыть" : "Показать"; b.setAttribute("aria-label", on ? "Скрыть пароль" : "Показать пароль");
  });

  /* ---------- регистрация ---------- */
  function check(form) {
    var d = {
      kind: kind, name: F.name.value.trim(), city: F.city.value.trim(), phone: F.phone.value, email: F.email.value.trim(), password: F.password.value,
      slug: F.slug.value.trim(), agreeRules: F.agreeRules.checked, agreePd: F.agreePd.checked
    };
    var bad = null;
    if (d.name.length < 2) bad = [F.name, kind === "solo" ? "Укажите ваше имя" : "Укажите название агентства"];
    else if (!d.city) bad = [F.city, "Укажите город"];
    else if (d.phone.replace(/\D/g, "").length !== 11) bad = [F.phone, "Проверьте номер телефона"];
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email)) bad = [F.email, "Проверьте адрес почты"];
    else if (d.password.length < 8) bad = [F.password, "Пароль — не короче 8 символов"];
    else if (d.slug.length < 3) bad = [F.slug, "Придумайте адрес страницы, от 3 символов"];
    else if (!d.agreeRules || !d.agreePd) bad = [F.agreeRules, "Нужно принять правила сервиса и согласие на обработку данных"];
    if (bad) { bad[0].focus(); throw new Error(bad[1]); }
    return d;
  }
  f.addEventListener("submit", function (e) {
    e.preventDefault(); err("regErr", "");
    var d; try { d = check(f); } catch (x) { err("regErr", x.message); return; }
    busy(f, true);
    post("start", d).then(function (r) { if (window.bpGoal) bpGoal("register_start"); token = r.token; $("codeEmail").textContent = r.email || d.email; $("codeForm").code.value = ""; show("codeForm"); startResendTimer(); })
      .catch(function (x) { err("regErr", x.message); })
      .finally(function () { busy(f, false); });
  });
  var cf = $("codeForm");
  cf.code.addEventListener("input", function () { cf.code.value = cf.code.value.replace(/\D/g, "").slice(0, 6); if (cf.code.value.length === 6) cf.requestSubmit ? cf.requestSubmit() : cf.dispatchEvent(new Event("submit")); });
  cf.addEventListener("submit", function (e) {
    e.preventDefault(); err("codeErr", "");
    if (cf.code.value.length !== 6) { err("codeErr", "Введите 6 цифр из письма"); return; }
    busy(cf, true);
    post("verify", { token: token, code: cf.code.value }).then(function (r) {
      if (window.bpGoal) bpGoal(kind === "solo" ? "register_done_solo" : "register_done");
      show("regDone");
      if (r.manual) $("doneText").textContent = "Страница отправлена на проверку и откроется для клиентов в ближайшее время. А пока настройте цены и оформление в панели.";
      else setTimeout(function () { location.href = "admin/"; }, 1200);
    }).catch(function (x) {
      err("codeErr", x.message);
      if (x.status === 410 || x.status === 409) setTimeout(function () { show("regForm"); err("regErr", x.message); }, 1800);
    }).finally(function () { busy(cf, false); });
  });
  var resendLeft = 0, resendTimer = null;
  function startResendTimer() {
    resendLeft = 60; clearInterval(resendTimer);
    var b = $("resend");
    function tick() { b.disabled = resendLeft > 0; b.textContent = resendLeft > 0 ? "Отправить ещё раз через " + resendLeft + " с" : "Отправить код ещё раз"; resendLeft--; if (resendLeft < 0) clearInterval(resendTimer); }
    tick(); resendTimer = setInterval(tick, 1000);
  }
  $("resend").addEventListener("click", function () {
    post("resend", { token: token }).then(function () { err("codeErr", ""); startResendTimer(); }).catch(function (x) { err("codeErr", x.message); });
  });
  $("backToForm").addEventListener("click", function () { show("regForm"); });

  /* ---------- восстановление пароля ---------- */
  var rf = $("resetForm"), r2 = $("reset2Form");
  rf.addEventListener("submit", function (e) {
    e.preventDefault(); err("resetErr", "");
    resetEmail = rf.email.value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(resetEmail)) { err("resetErr", "Проверьте адрес почты"); return; }
    busy(rf, true);
    post("reset_start", { email: resetEmail }).then(function () { show("reset2Form"); }).catch(function (x) { err("resetErr", x.message); }).finally(function () { busy(rf, false); });
  });
  r2.code.addEventListener("input", function () { r2.code.value = r2.code.value.replace(/\D/g, "").slice(0, 6); });
  r2.addEventListener("submit", function (e) {
    e.preventDefault(); err("reset2Err", "");
    if (r2.code.value.length !== 6) { err("reset2Err", "Введите 6 цифр из письма"); return; }
    if (r2.password.value.length < 8) { err("reset2Err", "Пароль — не короче 8 символов"); return; }
    busy(r2, true);
    post("reset_finish", { email: resetEmail, code: r2.code.value, password: r2.password.value }).then(function () { show("resetDone"); })
      .catch(function (x) { err("reset2Err", x.message); if (x.status === 410) setTimeout(function () { show("resetForm"); }, 1800); })
      .finally(function () { busy(r2, false); });
  });

  /* ---------- режим страницы и сведения о сервисе ---------- */
  function mode() {
    var reset = location.hash === "#reset";
    $("sideReg").hidden = reset; $("sideReset").hidden = !reset;
    document.title = (reset ? "Восстановление пароля" : "Подключить агентство") + " — Аниматорская Система";
    show(reset ? "resetForm" : "regForm");
  }
  window.addEventListener("hashchange", mode);
  mode();
  fetch("api/service.php", { cache: "no-store" }).then(function (r) { return r.ok ? r.json() : null; }).then(function (s) {
    if (s && s.priceEarly) {
      var rub = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " ₽"; };
      document.querySelectorAll(".js-p1").forEach(function (x) { x.textContent = rub(s.priceEarly); });
      document.querySelectorAll(".js-p2").forEach(function (x) { x.textContent = rub(s.priceFull); });
      document.querySelectorAll(".js-ph").forEach(function (x) { x.textContent = s.earlyHours; });
      if (s.soloEarly) { document.querySelectorAll(".js-s1").forEach(function (x) { x.textContent = rub(s.soloEarly); }); document.querySelectorAll(".js-s2").forEach(function (x) { x.textContent = rub(s.soloFull); }); }
    }
    if (!s) return;
    document.querySelectorAll(".js-free").forEach(function (x) { x.textContent = s.freeEnd; });
    var rb = function (n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, " ") + " ₽"; };
    if (s.freeUntil) document.querySelectorAll(".js-free-until").forEach(function (x) { x.textContent = s.freeUntil; });
    if (s.priceMonth) document.querySelectorAll(".js-pm").forEach(function (x) { x.textContent = rb(s.priceMonth); });
    if (s.soloMonth) document.querySelectorAll(".js-sm").forEach(function (x) { x.textContent = rb(s.soloMonth); });
    document.querySelectorAll(".js-tg").forEach(function (x) { if (/^https:\/\//.test(s.telegram)) x.href = s.telegram; });
    if (!s.regOpen) { $("regClosed").hidden = false; f.querySelector('button[type="submit"]').disabled = true; }
  }).catch(function () {});
})();
