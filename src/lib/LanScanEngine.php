<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Executes a pre-built bounded plan. Results are observations only; no-response
 * is never translated into an available/recommendable address.
 */
final class LanScanEngine
{
    private ArpProbeEngine $probeEngine;

    public function __construct(ArpProbeEngine $probeEngine)
    {
        $this->probeEngine = $probeEngine;
    }

    /** @return array<string,mixed> */
    public function execute(array $plan, int $intervalMs = 100): array
    {
        $interface = (string)($plan['interface'] ?? '');
        $batches = $plan['batches'] ?? null;
        if (!is_array($batches) || !preg_match('/^[a-zA-Z0-9_.:-]{1,32}$/', $interface)) {
            throw new \\InvalidArgumentException('Invalid LAN scan plan.');
        }
        $plannedTargetCount = 0;
        foreach ($batches as $batch) {
            if (!is_array($batch) || !is_array($batch['targets'] ?? null) || count($batch['targets']) > 64) {
                throw new \\InvalidArgumentException('Invalid or oversized scan batch.');
            }
            $plannedTargetCount += count($batch['targets']);
            if ($plannedTargetCount > 256) {
                throw new \\InvalidArgumentException('A scan execution cannot exceed 256 targets.');
            }
        }
        if (isset($plan['target_count']) && (int)$plan['target_count'] !== $plannedTargetCount) {
            throw new \\InvalidArgumentException('Scan plan target count does not match its batches.');
        }
        if ($intervalMs < 100 || $intervalMs > 2000) {
            throw new \\InvalidArgumentException('Scan interval must be between 100 and 2000 milliseconds.');
        }

        $results = [];
        $completedBatches = 0;
        foreach ($batches as $batch) {
            $batchResults = $this->probeEngine->probeMany($batch['targets'], $interface, 64, $intervalMs);
            foreach ($batchResults as $result) {
                $result['observed_at'] = date(DATE_ATOM);
                $results[] = $result;
            }
            $completedBatches++;
        }

        $counts = ['responding'=>0,'no_response'=>0,'probe_error'=>0,'response_unparsed'=>0];
        foreach ($results as $result) {
            $status = (string)($result['status'] ?? 'probe_error');
            if (!isset($counts[$status])) $counts[$status] = 0;
            $counts[$status]++;
        }

        return [
            'status' => 'completed',
            'interface' => $interface,
            'target_count' => (int)($plan['target_count'] ?? count($results)),
            'completed_count' => count($results),
            'completed_batches' => $completedBatches,
            'batch_count' => count($batches),
            'counts' => $counts,
            'results' => $results,
            'availability_inferred' => false,
            'completed_at' => date(DATE_ATOM),
        ];
    }
}
