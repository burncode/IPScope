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

ob_start();
ipscope_docker_hook_render();
$output = (string)ob_get_clean();

assertHook(str_contains($output, '/plugins/ipscope/src/www/assets/docker-ip-assistant.css'), 'hook emits the helper stylesheet');
assertHook(str_contains($output, '/plugins/ipscope/src/www/assets/docker-ip-assistant.js'), 'hook emits the helper script');
assertHook(str_contains($output, 'defer'), 'helper script is deferred');
assertHook(!str_contains($output, 'api.php?action=scan'), 'hook does not trigger a scan automatically');

$page = file_get_contents(__DIR__ . '/../packaging/IPScopeDockerHook.page');
assertHook(is_string($page), 'Docker hook page can be read');
assertHook(str_contains($page, 'Menu="Buttons:99"'), 'hook uses the hidden Buttons menu');
assertHook(str_contains($page, "AddContainer|UpdateContainer"), 'hook is limited to Add/Edit Container routes');
assertHook(str_contains($page, 'ipscope_docker_hook_render()'), 'hook page invokes the asset injector');

echo "Docker IP hook tests passed (8 assertions).\n";
