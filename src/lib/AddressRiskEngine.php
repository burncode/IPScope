<?php
declare(strict_types=1);

namespace IPScope;

/** Classifies DHCP-related risk; it never changes inventory ownership state. */
final class AddressRiskEngine
{
    /** @param array<string,mixed> $dhcp @return array<string,mixed> */
    public function evaluate(string $ip, string $network, array $dhcp): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new \\InvalidArgumentException('Risk evaluation requires a valid IPv4 address.');
        $mode = (string)($dhcp['mode'] ?? 'unknown');
        if ($mode === 'unknown') return ['level'=>'review','code'=>'dhcp_configuration_unknown','recommendable'=>false,'message'=>'DHCP configuration is unknown; verify the router DHCP pool before assigning this address.'];
        if ($mode === 'disabled') return ['level'=>'none','code'=>null,'recommendable'=>true,'message'=>null];
        if ($mode !== 'configured') return ['level'=>'review','code'=>'dhcp_configuration_invalid','recommendable'=>false,'message'=>'DHCP configuration is not recognized.'];
        $number = (int)sprintf('%u', ip2long($ip));
        foreach (($dhcp['reservations'] ?? []) as $reservation) {
            if (!is_array($reservation)) continue;
            if (($reservation['ip'] ?? '') === $ip && in_array((string)($reservation['network'] ?? '*'), ['*',$network], true)) {
                return ['level'=>'blocked','code'=>'dhcp_static_reservation','recommendable'=>false,'message'=>'This address is reserved by DHCP.'];
            }
        }
        foreach (($dhcp['pools'] ?? []) as $pool) {
            if (!is_array($pool) || !in_array((string)($pool['network'] ?? '*'), ['*',$network], true)) continue;
            $start = filter_var($pool['start_ip'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            $end = filter_var($pool['end_ip'] ?? null, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
            if ($start === false || $end === false) continue;
            $a = (int)sprintf('%u', ip2long($start)); $b = (int)sprintf('%u', ip2long($end));
            if ($number >= $a && $number <= $b) return ['level'=>'blocked','code'=>'dhcp_dynamic_pool','recommendable'=>false,'message'=>'This address is inside the DHCP dynamic allocation pool.'];
        }
        return ['level'=>'none','code'=>null,'recommendable'=>true,'message'=>null];
    }
}
