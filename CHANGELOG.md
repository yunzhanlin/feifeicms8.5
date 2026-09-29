# Changelog

## 8.5.0-beta1 - 2026-09-29

FeiFeiCMS 8.5 Beta 1 is the first public beta of the ThinkPHP 8 upgrade.

### Added

- Browser installer at `/install.php` and `/install`, including runtime checks, MySQL 8 schema installation, administrator creation, `.env` generation and an installation lock.
- PHP 8.2-8.5, MySQL 8.4, Redis and Meilisearch runtime support.
- FeiFeiCMS 4.3/7.4-compatible administration structure and MXOne frontend templates.
- FeiFeiCMS 7.3 JSON and MacCMS JSON collection adapters, source-aware merging and independent episode-scenario storage.
- Configurable FeiFeiCMS-compatible URL rules and one-based playback source/episode URLs.

### Changed

- Replaced the ThinkPHP 2.1 runtime with ThinkPHP 8.1 while retaining the original source in `legacy/` as the compatibility reference.
- Normalized the database into the `ffx_*` schema and separated media, sources, episodes, scenarios, users, comments and collection tasks.
- Moved the release identity to `config/version.php`; the backend footer, health endpoint and version page share this source.

### Beta notice

Back up existing sites before migration. This beta installs into an empty MySQL 8 database and does not overwrite or automatically import an existing FeiFeiCMS database.
