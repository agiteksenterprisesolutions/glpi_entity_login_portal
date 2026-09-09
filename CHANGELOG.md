# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-09-09

### Added

- Support for the **Single Sign-On** plugin (`singlesignon`, OAuth 2.0 / OIDC)
  alongside **samlSSO**. Providers from both can be mixed on the same portal.
- `ProviderSource` interface so support for further SSO plugins can be added
  without touching the rest of the plugin.
- Providers are grouped by their SSO plugin in the portal form.

### Changed

- Provider mappings now record which SSO plugin they belong to, since provider
  ids are only unique within one plugin. Existing 1.0.0 mappings are migrated
  automatically and keep pointing at samlSSO.

### Fixed

- The Single Sign-On plugin replaces GLPI's login template and renders an
  unfiltered provider list from inside it, which bypassed portal filtering.
  GLPI's own login template is now served while this plugin is active, so the
  SSO area stays under the `DISPLAY_LOGIN` hook and can be scoped to the portal.

## [1.0.0] - 2026-09-09

### Added

- Per-entity login pages reachable by slug, showing only that entity's SSO
  providers.
- The generic login page shows the login form with no SSO providers at all.
- Portal management under **Setup > Login portals**, with slug validation
  against GLPI's reserved top-level paths.
- Reverse proxy recipes for nginx, Traefik and Caddy.
