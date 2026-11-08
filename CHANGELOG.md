# Changelog

## [Unreleased]

### Added

- Add the `UIApiDemo` plugin under `examples/`.

### Changed

- Remove the general limitations section from the README.
- Include the demo source in PHP lint and PHPStan checks.

## [0.1.0] - 2026-11-09

### Changed

- Target Axolotl-PM only.
- Require PHP 8.4 or newer.
- Use the `AxolotlBucketLabs\\UI` namespace.
- Rename the virion to `UI-API`.
- Rename the Composer package to `axolotlbucketlabs/ui-api`.
- Restore PHPStan, PHPUnit and CI checks for the Axolotl-PM target.

### Migration

Consumers of `ninjaknights/nk-ui-pmmp` must update their Composer dependency,
virion declaration and PHP imports before using this version.
