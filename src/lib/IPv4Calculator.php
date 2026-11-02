<?php
declare(strict_types=1);

namespace IPScope;

final class IPv4Calculator
{
    /** @return array{network:string,broadcast:string,first_host:?string,last_host:?string,total_hosts:int} */
    public function range(string $cidr): array
    {
        if (!preg_match('/^([^\/]+)\/(\d{1,2})$/', trim($cidr), $matches)) {
            throw new \InvalidArgumentException('Invalid IPv4 CIDR: ' . $cidr);
        }

        $ip = $matches[1];
        $prefix = (int)$matches[2];
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || $prefix < 0 || $prefix > 32) {
            throw new \InvalidArgumentException('Invalid IPv4 CIDR: ' . $cidr);
        }

        $ipLong = (int)sprintf('%u', ip2long($ip));
        $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
        $network = $ipLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);
        $total = $prefix >= 31 ? (int)($broadcast - $network + 1) : max(0, (int)($broadcast - $network - 1));

        $first = $prefix >= 31 ? null : $network + 1;
        $last = $prefix >= 31 ? null : $broadcast - 1;

        return [
            'network' => long2ip($network),
            'broadcast' => long2ip($broadcast),
            'first_host' => $first === null ? null : long2ip($first),
            'last_host' => $last === null ? null : long2ip($last),
            'total_hosts' => $total,
        ];
    }

    public function contains(string $cidr, string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        $range = $this->range($cidr);
        $candidate = (int)sprintf('%u', ip2long($ip));
        $network = (int)sprintf('%u', ip2long($range['network']));
        $broadcast = (int)sprintf('%u', ip2long($range['broadcast']));
        return $candidate >= $network && $candidate <= $broadcast;
    }
}
