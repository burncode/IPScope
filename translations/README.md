# IPScope translations

IPScope follows Unraid's native WebGUI translation architecture. **The plugin must never install, overwrite, or modify files under `/usr/local/emhttp/languages`.** Language data is distributed through the official Unraid language repositories, not by the IPScope PLG/package.

## Repository layout

```text
translations/
├── en_US/
│   ├── IPScope/
│   │   └── ipscope.txt
│   └── javascript.fragment.txt
└── zh_CN/
    ├── IPScope/
    │   └── ipscope.txt
    └── javascript.fragment.txt
```

### PHP page translations

The page entry is `Menu="Utilities"`, `Title="IPScope"`, so the plugin page translation follows the official plugin-folder convention:

- English master contribution: `unraid/lang-en_US/IPScope/ipscope.txt`
- Simplified Chinese contribution: `unraid/lang-zh_CN/IPScope/ipscope.txt`

This mirrors established plugin layouts in the official language repositories, such as `Unassigned Devices/unassigneddevices.txt` and `Unassigned Devices/unassigneddevicessettings.txt`.

### JavaScript translations

Unraid language repositories maintain a shared root-level `javascript.txt` for WebGUI JavaScript strings. IPScope's `javascript.fragment.txt` files are **contribution fragments only**:

- Merge the English fragment into `unraid/lang-en_US/javascript.txt`.
- Merge the Chinese fragment into `unraid/lang-zh_CN/javascript.txt`.

Do not install these fragments as standalone files, and never overwrite the existing system `javascript.txt`. A language-repository pull request must add only IPScope's missing keys to the existing shared catalog.

## Release and installation rules

- The IPScope PLG and package contain no language files.
- The installer must not create locale directories or write under `/usr/local/emhttp/languages`.
- The installer must not delete or regenerate Unraid translation caches.
- Users receive translations through the Unraid language-pack update process after the corresponding language-repository changes are accepted and distributed.
- If a translation is not yet available in the installed language pack, IPScope safely displays its English source string.

## Validation

Run `bash scripts/check-local.sh`. The i18n smoke test checks source-key coverage, Unraid-normalized keys, contribution-file layout, and ensures the package build does not install language files or modify system language directories.
