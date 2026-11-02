# IPScope local API

The API is served by the Unraid WebGUI PHP environment after plugin installation.

## Read the latest snapshot

```http
GET /plugins/ipscope/api.php?action=snapshot
```

Returns the last successfully saved snapshot. It does not trigger Docker inspection.

## Run a scan

```http
POST /plugins/ipscope/api.php?action=scan
```

Runs a read-only Docker/template scan, atomically saves the result, and returns the new snapshot.

## Response shape

Success:

```json
{
  "ok": true,
  "snapshot": {
    "schema_version": 1,
    "generated_at": "2026-11-03T00:00:00+00:00",
    "summary": {
      "network_count": 2,
      "container_count": 8,
      "template_count": 8,
      "conflict_count": 0
    },
    "networks": [],
    "containers": [],
    "templates": [],
    "conflicts": []
  }
}
```

Errors return `ok: false` with a short message. Detailed exceptions are written to the Unraid system log; internal paths and command output are not exposed to the browser.

## Runtime paths

- Snapshot: `/var/local/emhttp/plugins/ipscope/snapshot.json` (RAM-backed runtime state)
- User templates: `/boot/config/plugins/dockerMan/templates-user`
- Docker executable: `/usr/bin/docker`

For isolated testing, these paths can be overridden using server-side environment variables `IPSCOPE_SNAPSHOT_PATH`, `IPSCOPE_TEMPLATE_DIR`, and `IPSCOPE_DOCKER_BIN`.

## Security and behavior

- The API exposes inventory data only; it has no Docker mutation actions.
- Snapshot reads do not rescan.
- Scans require POST.
- The endpoint relies on the Unraid WebGUI's normal authentication boundary and must be installed under its protected plugin route.
