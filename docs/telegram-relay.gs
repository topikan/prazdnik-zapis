/**
 * Реле Telegram для БроньПраздник (Google Apps Script).
 * Сайт на российском хостинге отправляет запрос сюда, Google пересылает его в Telegram.
 *
 * Настройка:
 * 1. script.google.com → «Новый проект», вставьте этот код вместо содержимого Code.gs, сохраните.
 * 2. «Настройки проекта» (шестерёнка) → «Свойства скрипта» → добавьте два свойства:
 *      BOT_TOKEN — токен бота от @BotFather
 *      RELAY_KEY — длинная случайная строка (её же впишите в config.local.php как tg_relay_key)
 * 3. «Начать развертывание» → «Новое развертывание» → тип «Веб-приложение»:
 *      Запуск от имени: «Я», У кого есть доступ: «Все». Разрешите доступ, когда Google спросит.
 * 4. Скопируйте ссылку веб-приложения (…/exec) в config.local.php как tg_relay_url.
 */
var ALLOWED = ["getMe", "sendMessage", "getUpdates", "deleteWebhook"];

function doPost(e) {
  var props = PropertiesService.getScriptProperties();
  var req;
  try { req = JSON.parse(e.postData.contents); } catch (err) { return json({ ok: false, description: "bad json" }); }
  var key = props.getProperty("RELAY_KEY");
  if (!key || req.key !== key) return json({ ok: false, description: "forbidden" });
  if (ALLOWED.indexOf(req.method) < 0) return json({ ok: false, description: "method not allowed" });
  var res = UrlFetchApp.fetch("https://api.telegram.org/bot" + props.getProperty("BOT_TOKEN") + "/" + req.method, {
    method: "post",
    contentType: "application/json",
    payload: JSON.stringify(req.params || {}),
    muteHttpExceptions: true
  });
  return ContentService.createTextOutput(res.getContentText()).setMimeType(ContentService.MimeType.JSON);
}

function doGet() { return json({ ok: true, description: "relay is running" }); }

function json(o) { return ContentService.createTextOutput(JSON.stringify(o)).setMimeType(ContentService.MimeType.JSON); }
