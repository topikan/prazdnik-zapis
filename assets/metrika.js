/* Яндекс Метрика (счётчик 114709359). Отдельным файлом: политика безопасности сайта запрещает встроенные скрипты.
   Подключена на главной, регистрации и демо. На страницах записи агентств её нет — там данные их клиентов. */
(function (m, e, t, r, i, k, a) {
  m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
  m[i].l = 1 * new Date();
  for (var j = 0; j < document.scripts.length; j++) { if (document.scripts[j].src === r) return; }
  k = e.createElement(t); a = e.getElementsByTagName(t)[0]; k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
})(window, document, "script", "https://mc.yandex.ru/metrika/tag.js?id=114709359", "ym");
ym(114709359, "init", { ssr: true, webvisor: true, clickmap: true, ecommerce: "dataLayer", referrer: document.referrer, url: location.href, accurateTrackBounce: true, trackLinks: true });
// цель для отчётов: bpGoal("register_done") и т. п.
window.bpGoal = function (name) { try { ym(114709359, "reachGoal", name); } catch (e) {} };
