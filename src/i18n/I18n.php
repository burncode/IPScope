<?php
declare(strict_types=1);

/**
 * IPScope-owned translations.
 *
 * Unraid's template.php loads the Dynamix display configuration and exposes
 * the selected locale as $locale (and $display['locale']) before including
 * plugin pages. Read that runtime value; never inspect or modify system
 * language files.
 */
final class IPScopeI18n
{
    private const DEFAULT_LOCALE = 'en';
    private const SUPPORTED_LOCALES = ['en', 'zh'];

    private string $locale;
    private array $messages;
    private array $english;

    public function __construct(?string $unraidLocale, ?string $pluginRoot = null)
    {
        $this->locale = self::resolveLocale($unraidLocale);
        $root = $pluginRoot ?? dirname(__DIR__, 2);
        $localeDir = $root . '/src/i18n/langs';

        $this->english = self::readCatalog($localeDir . '/en.json');
        $selected = $this->locale === self::DEFAULT_LOCALE
            ? $this->english
            : self::readCatalog($localeDir . '/' . $this->locale . '.json');

        $this->messages = $selected;
    }

    public static function resolveLocale(?string $locale): string
    {
        $locale = strtolower(str_replace('-', '_', trim((string)$locale)));
        if ($locale === '') {
            return self::DEFAULT_LOCALE;
        }

        $language = explode('_', $locale, 2)[0];
        return in_array($language, self::SUPPORTED_LOCALES, true)
            ? $language
            : self::DEFAULT_LOCALE;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function translate(string $key, array $replace = []): string
    {
        $message = $this->messages[$key] ?? $this->english[$key] ?? $key;
        foreach ($replace as $name => $value) {
            $message = str_replace('{' . $name . '}', (string)$value, $message);
        }
        return $message;
    }

    public function catalog(): array
    {
        return $this->messages;
    }

    private static function readCatalog(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return [];
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                return [];
            }
        }

        return $decoded;
    }
}
