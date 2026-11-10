<?php
declare(strict_types=1);

namespace IPScope;

/** Executes at most one small ARP batch per HTTP request. */
final class LanScanTaskRunner
{
    private LanScanTaskStore $store;
    private LanScanEngine $engine;

    public function __construct(LanScanTaskStore $store, LanScanEngine $engine)
    {
        $this->store = $store;
        $this->engine = $engine;
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
            return $this->store->appendBatchResults($id, $results['results']);
        } catch (\\Throwable $error) {
            $this->store->fail($id, 'ARP batch execution failed.');
            throw $error;
        }
    }
}
