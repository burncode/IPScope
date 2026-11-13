(function (window) {
  "use strict";

  var config = window.IPScopeI18nConfig || {};
  var messages = config.messages && typeof config.messages === "object"
    ? config.messages
    : {};
  var fallback = config.fallback && typeof config.fallback === "object"
    ? config.fallback
    : {};

  function translate(key, replacements) {
    var text = Object.prototype.hasOwnProperty.call(messages, key)
      ? messages[key]
      : (Object.prototype.hasOwnProperty.call(fallback, key) ? fallback[key] : key);

    if (replacements && typeof replacements === "object") {
      Object.keys(replacements).forEach(function (name) {
        text = text.split("{" + name + "}").join(String(replacements[name]));
      });
    }
    return text;
  }

  window.IPScopeI18n = {
    locale: typeof config.locale === "string" ? config.locale : "en",
    t: translate
  };
})(window);
