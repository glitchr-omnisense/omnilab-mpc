# omnilab/mpc

## Installation

```sh
composer require omnilab/mpc
```

```php
use Omnilab\Mpc\MpcSourceFactory;

$mpc = (new MpcSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, in the User-Agent
    'throttle' => 0,                   // optional: seconds between two calls
]);
```

With the Symfony bundle, the factory is registered as `mpc` when the bundle knows it; otherwise
register `MpcSourceFactory` as a service (it is autoconfigured as a `SourceFactoryInterface`).

## Which API

The MPC's REST APIs at `https://data.minorplanetcenter.net/api/`, documented in its
documentation hub: the **Designation Identifier** (`query-identifier`: any designation, packed or
not, a number or a name, `=` or `ILIKE` with wildcards), the **Orbits** API (`get-orb`: one
object's orbit in the `mpc_orb` JSON format) and the **Observations** API (`get-obs`: every
observation of one object, in ADES or the 80-column format). Each takes its query as a JSON body
**on a GET** - a POST answers 405 -, which the core's `HttpSource::getForJson()` sends.

| Method | Reads | Calls |
|---|---|---|
| `entity()` | a number (`433`, `(433)`), a designation (`2004 MN4`, packed `K04M04N`, a comet's `1P`, `C/2020 F3`, an old one `A898 PA`), a name (`Eros`, `Apophis`), an `Identifier` of the `mpc` or `jpl.sbdb` scheme, the MPC's object page | `query-identifier {"ids":[...]}`, then `get-orb {"desig": number or primary designation}` |
| `search()` | `Query::$text`: the names that hold it, whatever its case; a domain other than astronomy answers nothing | `query-identifier {"ids":["%text%"],"comparison":"ILIKE"}` |
| `observations()` | outside the contract: an identifier as `entity()` reads it, how many (20 by default, 1,000 at most) | `get-obs {"desigs":[...],"output_format":["OBS80"]}` |

A name two bodies share (`Halley`: 1P/Halley and (2688) Halley; the Identifier answers
`found: 2` with its `disambiguation_list`) is `null` rather than a guess - ask `1P` or `2688`. A
name nobody has is `null` (`found: 0`), without asking an orbit. An identifier of another base
(`wikidata:...`, `simbad:...`, `CHEBI:...`) is refused before any call.

The search answers every match at once, the closest first (the MPC's `similarity`): `Page::$next`
is an offset into that list (one call per page), `total` its length. Each match holds its
designations, name and citation, not its orbit (`entity($match->id)` reads it).

## What an entity holds

`id` in the `mpc` scheme (its number, else its primary designation), `label` as the IAU writes it
(`(433) Eros`, `(99942) Apophis`, `2024 YR4`, a comet `1P/Halley` or `C/2020 F3 (NEOWISE)`),
`domain` astronomy, `kind` the MPC's object type lower case (`minor planet`, `comet`, `natural
satellite`, `interstellar object`), `synonyms` (its name, its primary and secondary provisional
designations), `description` its naming citation (the MPC's `\N` is none), `url` its MPC page,
`identifiers` its own, `jpl.sbdb` with the same value (JPL names it the same) and, for a numbered
body, its primary designation in the `mpc` scheme. No `related`, no place nor time.

| Key | mpc_orb | Value |
|---|---|---|
| `orbit_type` | categorization.orbit_type_str (minor planets only: the MPC gives `Apollo` for 1P/Halley) | text: Amor, Apollo, Aten, MBA... |
| `epoch`, `epoch_mjd` | epoch_data (MJD, TT) | text `2026-06-09 00:00`; number |
| `semi_major_axis`, `eccentricity`, `inclination`, `ascending_node`, `argument_of_perihelion`, `mean_anomaly` | KEP (a comet has none: COM gives e, i, node, argperi) ± coefficient_uncertainties | Quantity: au, -, deg |
| `perihelion_distance`, `perihelion_time`, `perihelion_time_mjd` | COM: q, peri_time | Quantity au; text; Quantity d (MJD) |
| `orbital_period` | orbit_fit_statistics.period | Quantity, d |
| `absolute_magnitude`, `slope_parameter` | magnitude_data: H, G | Quantity mag; number |
| `moid`, `moid_venus`, `moid_mars`, `moid_jupiter` | moid_data | Quantity, au |
| `uncertainty_parameter`, `orbit_quality`, `rms` | U_param, orbit_quality, normalized_RMS | number; text; number |
| `observations`, `observations_used`, `radar_observations`, `oppositions`, `arc` | nobs_total, nobs_total_sel, nobs_radar, nopp, arc_length_total | number; text `1893-2026` |
| `non_gravitational` | non_grav_booleans.non_gravs | boolean |
| `orbit_computed` | software_data.fitting_datetime | text, UTC |

The keys are those omnilab/jpl gives for the same elements, so that the `Merger` sets the two
orbits side by side, each with its source.

## Observations (outside the contract)

`observations()` gives `Observation`s, the latest first: `time` (UTC, to the millisecond the line
gives), `ra` and `dec` (degrees, J2000), `magnitude` and `band`, `observatory` (the MPC's code:
`568` Maunakea, `W68` ATLAS Chile, `274` the James Webb Space Telescope), `method` (CCD, CMOS,
photographic, space-based... from column 15), `discovery` (the asterisk), and the 80-column `line`.
Why outside the contract: an observation has a time and an observatory code, not a `Point` the core
reads (`occurrences()` asks one of each answer, and a space telescope has none), and the MPC
answers every observation of an object at once, so there is no page to ask. A radar's lines and
the second line of a satellite's or a roving observer's observation are not read.

## The Collector: JPL and the MPC

The two bases name a body by the same designation; an entity of either carries the `jpl.sbdb`
identifier, which both read. From the MPC to JPL (`collect('mpc', '433', 1, ['jpl'])`) and from JPL
to the MPC (`collect('jpl', '433', 1, ['mpc'])`) the two orbits merge: two semi-major axes, two
eccentricities, each with its source.

## What the MPC does not give here

The ADES and XML observations, the NEO Confirmation Page and its observations, the observatory
codes (a code is not resolved to its place), the MPECs, the orbit's covariance and Cartesian
state, the non-gravitational parameters' values, the natural satellites' orbits (the Identifier
reads them; `get-orb` was not asked one), WAMO, the submission status and the summary counts.

## Verified, and not

Against the real service on 2026-10-15: Eros by its number and name (two calls, 1.6 s), Apophis by
its name and its packed designation (`K04M04N`), 1P/Halley and C/2020 F3 (NEOWISE) - cometary
elements only -, 2024 YR4 by `jpl.sbdb:2024 YR4` (MOID 0.00275 au, U 1); `Halley` (two bodies) and
an unknown name null; the search for `eros` (thirteen names, (433) Eros first); the latest
observations of 2024 YR4 (JWST, 2026-02-26), Apophis (7.4 s) and Eros (14.5 s, 18,679
observations); `get-orb` answered by a POST: 405; the Designation Identifier given a bare string:
400 with its schema. The fixtures in `Tests/Fixtures/` are its answers.

Not verified: a natural satellite or an interstellar object; a 429 (no rate published, none met);
the Identifier's other comparison (`%`); a mirror (`base_uri`).
