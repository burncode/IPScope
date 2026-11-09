<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/ProcessRunner.php';
require_once __DIR__ . '/../src/lib/ArpProbeEngine.php';

use IPScope\ProcessRunner;
use IPScope\ArpProbeEngine;

$runner = new class extends ProcessRunner {
    public array $calls = [];
    public function run(array $command, int $timeoutSeconds = 20): array {
        $this->calls[] = $command;
        if (in_array('192.168.50.1', $command, true)) {
            return ['exit_code'=>0,'stdout'=>'Unicast reply from 192.168.50.1 [aa:bb:cc:dd:ee:ff] 1.2ms','stderr'=>''];
        }
        if (in_array('192.168.50.2', $command, true)) {
            return ['exit_code'=>1,'stdout'=>'Sent 1 probes (1 broadcast(s))','stderr'=>'Received 0 response(s)'];
        }
        return ['exit_code'=>1,'stdout'=>'','stderr'=>'permission denied'];
    }
};
$engine = new ArpProbeEngine($runner);
$hit = $engine->probe('192.168.50.1', 'bond0');
$miss = $engine->probe('192.168.50.2', 'bond0');
$error = $engine->probe('192.168.50.3', 'bond0');
$assertions = [
    $hit['status'] === 'responding',
    $hit['mac'] === 'aa:bb:cc:dd:ee:ff',
    $miss['status'] === 'no_response',
    $error['status'] === 'no_response',
    count($runner->calls) === 3,
];
try { $engine->probe('not-an-ip', 'bond0'); $assertions[] = false; } catch (InvalidArgumentException $e) { $assertions[] = true; }
try { $engine->probe('192.168.50.4', 'bond0;reboot'); $assertions[] = false; } catch (InvalidArgumentException $e) { $assertions[] = true; }
try { $engine->probeMany(['192.168.50.1','192.168.50.2'], 'bond0', 1); $assertions[] = false; } catch (InvalidArgumentException $e) { $assertions[] = true; }
try { $engine->probeMany(['192.168.50.1','192.168.50.1'], 'bond0', 1); $assertions[] = count($runner->calls) === 3; } catch (Throwable $e) { $assertions[] = false; }
foreach ($assertions as $i => $passed) {
    if (!$passed) { fwrite(STDERR, 'ARP probe test failed at assertion '.($i+1).PHP_EOL); exit(1); }
}
echo 'ARP probe tests passed ('.count($assertions).' assertions).'.PHP_EOL;
