(function () {
  "use strict";
  // Подставляем в правила исполнителя, даты бесплатного периода и контакты из настроек сервиса
  function each(sel, fn) { document.querySelectorAll(sel).forEach(fn); }
  fetch("api/service.php", { cache: "no-store" }).then(function (r) { return r.ok ? r.json() : null; }).then(function (s) {
    if (!s) return;
    if (s.processor) each(".js-processor", function (x) { x.textContent = s.processor; });
    if (s.freeEnd) each(".js-free", function (x) { x.textContent = s.freeEnd; });
    if (s.seasonEnd) each(".js-season", function (x) { x.textContent = s.seasonEnd; });
    if (s.trialDays) each(".js-trial", function (x) { x.textContent = s.trialDays; });
    if (s.site) each(".js-site", function (x) { x.textContent = s.site.replace(/^https?:\/\//, ""); });
    if (/^https:\/\//.test(s.telegram || "")) each(".js-tg", function (x) { x.href = s.telegram; });
    if (/^[^\s@<>"]+@[^\s@<>"]+$/.test(s.email || "")) {
      each(".js-email", function (x) { x.textContent = s.email; x.href = "mailto:" + s.email; });
      each(".js-email-wrap", function (x) { x.hidden = false; });
    }
  }).catch(function () {});
})();
