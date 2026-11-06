# T020-A — Docker IP Assistant

## Scope

Add an advisory IP helper to Unraid Docker's native Add Container and Edit Container pages.

The helper is injected through an IPScope-owned PageBuilder `Buttons` page whose condition matches only `AddContainer` and `UpdateContainer` requests. It does not patch Unraid files.

## User flow

1. Open Docker → Add Container, or edit an existing container.
2. Select a custom Docker network that has a managed IPv4 pool in the latest IPScope snapshot.
3. In the native Fixed IP address area, click **Find available IPs**.
4. Choose one of the returned addresses to fill Unraid's existing `contMyIP` field.
5. Review the entire template and click Unraid's native Apply/Create action yourself.

The helper never submits the form, creates a container, changes a Docker network, or writes a template.

## Safety and freshness

- Candidates come from the existing read-only recommendations API.
- The API only returns addresses marked available inside a managed IPv4 host range.
- Built-in networks and networks absent from the current snapshot do not produce suggestions.
- The helper displays the snapshot age and warns when it is older than five minutes.
- The user must still account for DHCP leases, offline devices, and hosts outside Docker.

## Files

- `packaging/IPScopeDockerHook.page`: narrowly scoped PageBuilder hook.
- `packaging/include/docker-hook.php`: emits cache-busted helper assets.
- `src/www/assets/docker-ip-assistant.js`: network-aware suggestion UI and fixed-IP field fill.
- `src/www/assets/docker-ip-assistant.css`: compact Unraid-compatible styling.
- `scripts/build-package.sh`: includes hook and PHP injector in the package.
- `scripts/install-local.sh`: verifies hook assets are present in the package.
- `scripts/check-local.sh`, `tests/docker-ip-hook.php`: static and PHP hook checks.

## Local validation on Unraid

Run as root from the repository checkout:

```sh
git fetch origin
git checkout -b feat/t020-a-docker-ip-assistant origin/feat/t020-a-docker-ip-assistant
bash scripts/check-local.sh
bash scripts/install-local.sh
```

Then hard-refresh the browser and test:

- Docker → Add Container with a managed custom network.
- Docker → Edit Container with a managed custom network.
- Switching to Bridge, Host, Container, or None hides the helper with Unraid's native Fixed IP area.
- Selecting a candidate fills only the Fixed IP field; it does not submit the form.
- Changing networks clears the previous candidate list.
- Missing snapshot, unsupported network, empty candidate list, API error, and stale snapshot states are clear.
- Existing IPScope scan, inventory, exclusions, and recommendations remain functional.

## Not in scope

- Automatic IP assignment or container creation.
- DHCP lease discovery or LAN-wide ARP probing.
- Modifying Unraid's DockerMan source.
- Changing or saving templates without the user's native Apply action.