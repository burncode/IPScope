<?php
declare(strict_types=1);

require_once __DIR__ . '/../packaging/include/docker-hook.php';

function assertHook(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function renderHookForLocale(string $locale): string
{
    $GLOBALS['locale'] = $locale;
    ob_start();
    ipscope_docker_hook_render();
    return (string)ob_get_clean();
}

assertHook(is_file(ipscope_docker_hook_plugin_root() . '/src/i18n/I18n.php'), 'Docker hook resolves the plugin root and its i18n runtime');
$englishOutput = renderHookForLocale('en_US');
$chineseOutput = renderHookForLocale('zh_CN');

assertHook(str_contains($englishOutput, '/plugins/ipscope/src/www/assets/docker-ip-assistant.css'), 'hook emits the helper stylesheet');
assertHook(str_contains($englishOutput, '/plugins/ipscope/src/www/assets/docker-ip-assistant.js'), 'hook emits the helper script');
assertHook(str_contains($englishOutput, 'defer'), 'helper script is deferred');
assertHook(!str_contains($englishOutput, 'api.php?action=scan'), 'hook does not trigger a scan automatically');
assertHook(str_contains($englishOutput, '"locale":"en"'), 'English Unraid locale is passed to the plugin runtime');
assertHook(str_contains($englishOutput, '"common.scan_now":"Scan now"'), 'English catalog is injected into Docker page');
assertHook(str_contains($chineseOutput, '"locale":"zh"'), 'Chinese Unraid locale is passed to the plugin runtime');
assertHook(str_contains($chineseOutput, '"common.scan_now":"立即扫描"'), 'Chinese catalog is injected into Docker page');

$page = file_get_contents(__DIR__ . '/../packaging/IPScopeDockerHook.page');
assertHook(is_string($page), 'Docker hook page can be read');
assertHook(str_contains($page, 'Menu="Buttons:99"'), 'hook uses the hidden Buttons menu');
assertHook(str_contains($page, "AddContainer|UpdateContainer"), 'hook is limited to Add/Edit Container routes');
assertHook(str_contains($page, 'ipscope_docker_hook_render()'), 'hook page invokes the asset injector');

unset($GLOBALS['locale']);

echo "Docker IP hook tests passed (12 assertions, en/zh locale injection).\n";
