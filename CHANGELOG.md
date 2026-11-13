# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab MPC: a minor planet, comet or natural satellite of the IAU's Minor Planet Center by its
  number, designation (provisional, packed or not) or name - its designations, name and naming
  citation, its orbit as the MPC computes it (osculating elements with their uncertainties,
  perihelion, period, MOIDs), absolute magnitude, observations, oppositions, arc and uncertainty
  parameter; a search of the names; its latest observations - through the MPC's APIs, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/mpc` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
