# IPScope data model notes

## Sources are deliberately separate

IPScope must not collapse all observations into a single boolean "used" flag.

| Source | Meaning | Runtime occupancy? |
| --- | --- | --- |
| Docker NetworkSettings.Networks[*].IPAddress | Current endpoint address | Yes, only when container is running |
| Docker NetworkSettings.Networks[*].IPAMConfig.IPv4Address | Requested/static endpoint address retained by inspect | Reservation evidence; not proof of an active endpoint |
| Unraid user template MyIP | Address configured for future container creation/start | Reservation |
| Docker network IPAM | Subnet, gateway, IP range, auxiliary addresses | Network constraints |
| Host interface addresses | Unraid host addresses | System-reserved address |

## Status vocabulary

- occupied: a running container currently reports this IPv4 address.
- reserved: a stopped container or saved Unraid template requests this address.
- conflict: multiple owners claim an address, or template and runtime configuration disagree.
- system: gateway, host address, network/broadcast address, or configured exclusion.
- available_candidate: no known reservation or allocation was found. This is not a guarantee that an external device or DHCP client is not using it.
- unknown: source data is incomplete or contradictory; do not recommend the address.

## Matching rules

1. Associate template reservations by the template's saved network name, not by IP subnet alone.
2. Associate runtime addresses by the Docker network key returned by inspect.
3. A container may be attached to multiple networks; evaluate each network independently.
4. Never treat a stopped container's stale IPAddress as an active allocation.
5. Never discard a stopped container's template MyIP.
6. If a running container's template IP differs from its actual endpoint IP on the same network, report a mismatch; do not silently choose one.
7. Missing or malformed source data must reduce confidence, never increase the available-address count.

## Safety

A Docker-only scan cannot discover DHCP leases or offline static-IP devices. Users should configure exclusions for DHCP pools and known infrastructure addresses. Future optional ARP observations must be reported as evidence, not as authoritative reservations.
