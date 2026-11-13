# Changelog

All notable IPScope changes are documented here.

## [0.1.2] - Unreleased

### Added
- LAN device discovery with device-name resolution and recognizable device-type hints.
- DHCP configuration awareness and address-risk checks.
- Docker IP assistant for Unraid container creation and editing.
- Paginated IP recommendations with complete candidate membership validation.
- Quick inventory refresh from the Docker IP assistant.
- English and Simplified Chinese plugin-owned translations.

### Improved
- Conservative IP recommendation gates for stale snapshots and unavailable LAN discovery.
- Merge of recent active LAN scan evidence into recommendation decisions.
- Docker IP assistant loading, selection, pagination, and refresh interactions.
- Clearer template-read failure reporting and reduced runtime dependence on device identity caching.

### Safety
- Recommendations remain advisory; an unresponsive address is not guaranteed to be unused.
- Docker allocations, saved-template reservations, LAN observations, DHCP risks, and user exclusions remain distinct evidence sources.
- Existing container and network configuration is not modified automatically.
