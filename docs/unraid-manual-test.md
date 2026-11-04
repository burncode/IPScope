# Unraid 7.3.x manual validation

This is the first real-host gate. Do not install the dashboard into the WebGUI until the CLI scan succeeds.

## 1. Confirm runtime capabilities

Run as root on Unraid:

```sh
php -v
php -m | grep -Ei 'simplexml|libxml'
docker version --format '{{.Server.Version}}'
```

The parser needs SimpleXML/libxml. The collector needs access to the Docker CLI and Docker daemon.

## 2. Copy the repository to a temporary working directory

Use a local clone or upload the source tree to a temporary directory, for example:

```sh
cd /boot/config/plugins
git clone <your-repository-access-url> IPScope-dev
cd IPScope-dev
```

For a private repository, use your normal authenticated Git transport. Do not put a personal access token in shell history or in the plugin source.

## 3. Run fixture checks

```sh
sh scripts/check-local.sh
```

Expected final line:

```
All local IPScope checks passed.
```

## 4. Run a real read-only scan

```sh
php src/ipscope-scan.php --output=/tmp/ipscope-snapshot.json
```

Inspect the summary and a few records:

```sh
php -r '$s=json_decode(file_get_contents("/tmp/ipscope-snapshot.json"),true); echo json_encode($s["summary"]??[],JSON_PRETTY_PRINT),PHP_EOL;'
```

Check:
- Expected Docker networks appear.
- Running containers show their current IPv4 addresses.
- Stopped containers do not show stale runtime endpoint IPs as occupied.
- Saved Unraid templates retain configured MyIP reservations.
- Conflicts are explainable and do not include false duplicates.

## 5. Check template path

Default path:

```
/boot/config/plugins/dockerMan/templates-user
```

If your installation uses a different path, pass it explicitly:

```sh
php src/ipscope-scan.php --templates=/actual/template/path --output=/tmp/ipscope-snapshot.json
```

## 6. Report results

Please provide:
- Output of `php -v`
- Whether `sh scripts/check-local.sh` passes
- The `summary` object from the snapshot
- One network object and one stopped-container template object (redact names/IPs if desired)
- Any errors from the scan

Do not send the full snapshot if it contains private container names or LAN details.


## 7. T017-D exclusion rule management and audit

After installing the T017-D build, open **Exclusions** and verify:

- Edit a rule's network, match type, IP/CIDR and reason; save and confirm it persists after a page refresh.
- Cancel an edit and confirm the original rule remains unchanged.
- Select several rules and bulk-enable, bulk-disable, then bulk-delete a disposable test rule.
- Add an IP rule covered by an enabled CIDR rule. Both remain saved, and the rule list reports an overlap warning instead of silently deleting or merging either rule.
- Add a global CIDR rule and a network-specific IP rule inside that range. The overlap is reported because both rules can affect the same address on that network.
- Confirm the coverage column counts addresses matched by each enabled rule.
- Confirm addresses already occupied, reserved, or assigned to a system service are counted as in-use and remain visible in the Conflicts view.
- Confirm disabled rules do not affect inventory state or generate active-overlap warnings.

The audit is based on the latest saved inventory snapshot. Run **Scan now** after network/container changes before interpreting coverage counts. Overlap warnings are advisory; they do not change address ownership or automatically rewrite rules.
