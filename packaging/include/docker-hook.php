<?php
declare(strict_types=1);

/**
 * Inject the IPScope IP helper only into Unraid's Docker Add/Edit Container page.
 * This is a PageBuilder Buttons hook; it does not patch Unraid source files.
 */
function ipscope_docker_hook_asset(string $path): string
{
    $url = '/plugins/ipscope/' . ltrim($path, '/');
    return function_exists('autov') ? autov($url, true) : $url;
}

function ipscope_docker_hook_render(): void
{
    require_once dirname(__DIR__, 2) . '/src/i18n/I18n.php';
    $unraidLocale = isset($GLOBALS['locale']) && is_string($GLOBALS['locale']) ? $GLOBALS['locale'] : 'en';
    $pluginRoot = dirname(__DIR__, 2);
    $translator = new IPScopeI18n($unraidLocale, $pluginRoot);
    $english = new IPScopeI18n('en', $pluginRoot);
    $config = [
        'locale' => $translator->locale(),
        'messages' => $translator->catalog(),
        'fallback' => $english->catalog(),
    ];
    $configJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $i18n = htmlspecialchars(ipscope_docker_hook_asset('src/www/assets/i18n.js'), ENT_QUOTES, 'UTF-8');
    $css = htmlspecialchars(ipscope_docker_hook_asset('src/www/assets/docker-ip-assistant.css'), ENT_QUOTES, 'UTF-8');
    $js = htmlspecialchars(ipscope_docker_hook_asset('src/www/assets/docker-ip-assistant.js'), ENT_QUOTES, 'UTF-8');

    echo '<link rel="stylesheet" href="' . $css . '">' . "\n";
    echo '<script>window.IPScopeI18nConfig = ' . ($configJson !== false ? $configJson : '{}') . ';</script>' . "\n";
    echo '<script src="' . $i18n . '" defer></script>' . "\n";
    echo '<script src="' . $js . '" defer></script>' . "\n";
}
