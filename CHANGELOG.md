# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Migrated user values to the new user config API
- Group memberships assigned by the app are now tracked, only those are removed when the mapping no longer applies.
  Memberships assigned by other means (administrator, LDAP, other apps) are no longer removed.
  See the README for details on the upgrade behaviour.
  - **Upgrading from version 1.2.x or earlier:**
    Older versions did not record which memberships were assigned by the app.
    Upon the first login of a user after the upgrade:
    - If prune groups was already enabled, all group memberships of the user are recorded as assigned by the app,
      since older versions removed any membership not coming from a mapping upon login anyway.
    - If it is disabled, existing memberships stay where they are and are never removed by the app.
      Only memberships assigned after the upgrade are recorded by the app.
- Renamed group prune option

### Removed

- Support for Nextcloud 31

## [1.2.2] - 2026-09-24

### Fixed

- Import missing dialog styles

## [1.2.1] - 2026-09-22

### Changed

- README: Added hint for instances without Pretty URLs
- Updated dependencies
- Support for Nextcloud v35

## [1.2.0] - 2026-07-30

### Added

- Nextcloud groups can now also be assigned based on hitobito event participations of the user

### Changed

- Updated dependencies
- Support for Nextcloud v34

## [1.1.3] - 2026-03-17

### Changed

- Updated dependencies

## [1.1.2] - 2026-02-19

### Changed

- Updated dependencies
- Support for Nextcloud v33

## [1.1.1] - 2025-12-18

### Fixed

- Shares not added to/removed from user (https://github.com/cevi/nextcloud-hitobito-login/issues/23)

### Changed

- Updated dependencies

## [1.1.0] - 2025-10-09

### Changed

- Build system from Webpack to Vite
- Migrate to Vue 3 (@nextcloud/vue@v9)

### Added

- Prettier code formatting

### Deprecated

- Support for Nextcloud 29 and 30

## [1.0.3] - 2025-10-03

### Changed

- Updated dependencies

### Fixed

- optional-before-required parameter ordering issue (thanks @about2crash)
- stylelint config issue

## [1.0.2] - 2025-07-22

### Added

- Settings service
- Option to set Hitobito as default login
- Documentation of redirect-url
- Documentation clarifying the non-affiliation with Puzzle ITC

## [1.0.1] - 2025-06-05

### Added

- Release pipeline

### Fixed

- Use base_url config value for login button
- Group wildcard parsing error

## [1.0.0] - 2025-05-10

### Added

- First release
