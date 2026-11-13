<?php
declare(strict_types=1);

namespace IPScope;

/** Executes at most one small ARP batch per HTTP request. */
final class LanScanTaskRunner
{
    private LanScanTaskStore $store;
    private LanScanEngine $engine;
    private ?LanDeviceNameResolver $nameResolver;
    private ?LanDeviceIdentityCache $identityCache;

    public function __construct(
        LanScanTaskStore $store,
        LanScanEngine $engine,
        ?LanDeviceNameResolver $nameResolver = null,
        ?LanDeviceIdentityCache $identityCache = null
    ) {
        $this->store = $store;
        $this->engine = $engine;
        $this->nameResolver = $nameResolver;
        $this->identityCache = $identityCache;
    }

    public function step(string $id): array
    {
        $claim = $this->store->claimNextBatch($id);
        if ($claim['status'] !== 'claimed') return $claim['task'];
        try {
            $batch = $claim['batch'];
            $results = $this->engine->execute([
                'interface'=>$claim['task']['interface'],
                'target_count'=>count($batch['targets']),
                'batches'=>[['targets'=>$batch['targets']]],
            ], 100);
            if ($this->nameResolver !== null) {
                foreach ($results['results'] as &$result) {
                    $result['device_name'] = null;
                    $result['name_source'] = null;
                    if (($result['status'] ?? '') === 'responding') {
                        $ip = (string)$result['ip'];
                        $mac = isset($result['mac']) ? (string)$result['mac'] : '';
                        $identity = $mac !== '' && $this->identityCache !== null
                            ? $this->identityCache->get($ip, $mac)
                            : null;
                        if ($identity !== null) {
                            $result['device_name'] = $identity['name'];
                            $result['name_source'] = $identity['name_source'];
                            $result['name_cached'] = true;
                        } else {
                            $identity = $this->nameResolver->resolve($ip);
                            $result['device_name'] = $identity['name'];
                            $result['name_source'] = $identity['name_source'];
                            $result['name_cached'] = false;
                            if ($mac !== '' && $this->identityCache !== null) {
                                $this->identityCache->put($ip, $mac, $identity['name'], $identity['name_source']);
                            }
                        }
                    }
                }
                unset($result);
            }
            return $this->store->appendBatchResults($id, $results['results']);
        } catch (\Throwable $error) {
            $this->store->fail($id, 'ARP batch execution failed.');
            throw $error;
        }
    }
}
