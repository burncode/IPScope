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
    $css = htmlspecialchars(ipscope_docker_hook_asset('src/www/assets/docker-ip-assistant.css'), ENT_QUOTES, 'UTF-8');
    $js = htmlspecialchars(ipscope_docker_hook_asset('src/www/assets/docker-ip-assistant.js'), ENT_QUOTES, 'UTF-8');

    echo '<link rel="stylesheet" href="' . $css . '">' . "\n";
    echo '<script src="' . $js . '" defer></script>' . "\n";
}
