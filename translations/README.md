# IPScope translations

IPScope uses the native Unraid WebGUI translation system. It does not bundle language files into the plugin package.

## Translation source files

- `ipscope.txt`: English master file for `unraid/lang-en_US`.
- `zh_CN/ipscope.txt`: Simplified Chinese page translation draft for `unraid/lang-zh_CN`.
- `javascript.txt`: English source strings used by IPScope JavaScript.
- `zh_CN/javascript.txt`: Simplified Chinese JavaScript translations.

The Unraid page route is `Utilities/IPScope`. The WebGUI translation loader uses the route segments and loads `ipscope.txt` from the active locale directory. PHP labels use the native `_()` function. Front-end JavaScript uses the global `_` translator supplied by the WebGUI layout; Unraid generates `translate.{locale}.js` from the locale's `javascript*.txt` files.

## Local validation on Unraid

After selecting Simplified Chinese in Unraid Display Settings, copy the Chinese file to the language directory:

```bash
install -D -m 0644 translations/zh_CN/ipscope.txt /usr/local/emhttp/languages/zh_CN/ipscope.txt
install -D -m 0644 translations/zh_CN/javascript.txt /usr/local/emhttp/languages/zh_CN/javascript.txt
rm -f /usr/local/emhttp/languages/zh_CN/ipscope.dot
rm -f /usr/local/emhttp/webGui/javascript/translate.zh_CN.js
```

To test English explicitly, copy `translations/ipscope.txt` to `/usr/local/emhttp/languages/en_US/ipscope.txt` and remove the matching `ipscope.dot` cache. The English text is identical to the source labels, so this is mainly a loader/path check.

**Do not copy locale folders into the plugin package or create locale directories under `/usr/local/emhttp/languages` as part of plugin installation.** Unraid's language-pack manager detects locale directories, and plugin-managed locale folders can make it incorrectly report a full language pack as installed. The English master and community translations should be contributed to the official Unraid language repositories instead.

## Checks

Run `bash scripts/check-local.sh`. The i18n smoke test verifies translation-key coverage, active-locale metadata, and that the JSON API remains independent of the WebGUI translation loader.
