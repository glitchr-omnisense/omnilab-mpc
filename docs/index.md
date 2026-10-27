# omnilab/mpc

The IAU's Minor Planet Center, through its REST APIs (`https://data.minorplanetcenter.net/api/`),
for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): minor planets, comets and
natural satellites by number, designation or name - designations, naming citation, orbit with its
uncertainties, absolute magnitude, observations and arc - as entities; a search of names; outside
the contract, their latest observations. No key.

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

## Credentials

None: the MPC's APIs take no key and no account. Give `mailto`, the address the MPC writes to
before it blocks a caller.

## Options

The factory's options, with the defaults it applies (`MpcSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: whom the MPC writes to before it blocks a caller |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the MPC publishes no rate |
| `base_uri` | `string` | `https://data.minorplanetcenter.net/api/` | no | the API the source calls |

## Capabilities

`capabilities()` answers `entity`, `search`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a number, a designation (packed or not, a comet's), a name, a `mpc` or `jpl.sbdb` identifier, its MPC page: two calls (the identifier, the orbit); a name two bodies share is null; another base refused before any call |
| `search` | yes | the names that hold the text, the closest first (the MPC's similarity), 100 a page at most, with a `total`; a domain other than astronomy answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no | an observation has an observatory code, not a place: `observations()`, outside the contract |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | the `jpl.sbdb` identifier of the same value (JPL names the body the same), and its primary designation |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

Outside the contract, on `Omnilab\Mpc\MpcSource`: `observations(Identifier|string $id, int $limit
= 20): list<Observation>` - a body's latest observations, 1,000 at most
([below](#observations-outside-the-contract)).

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Mpc\MpcSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$mpc = (new MpcSourceFactory(HttpClient::create(['timeout' => 60])))->create(['mailto' => 'you@example.org']);

$apophis = $mpc->entity('Apophis');
echo $apophis->label, ' (', $apophis->id, '): a = ', $apophis->property('semi_major_axis'), ', e = ', $apophis->value('eccentricity'), ', H = ', $apophis->value('absolute_magnitude'), "\n";
var_dump($mpc->entity('Halley'));   // two bodies bear the name: neither

foreach ($mpc->observations('2024 YR4', 2) as $o) {
    printf("  %s  RA %.5f Dec %.5f  %s at %s (%s)\n", $o->time->format('Y-m-d H:i:s'), $o->ra, $o->dec, $o->magnitude ?? '-', $o->observatory, $o->method);
}
$page = $mpc->search(new Query('eros', limit: 3));
printf("%d names hold \"eros\"; the first: %s\n", $page->total, $page->first()->label);
```

```
(99942) Apophis (mpc:99942): a = 0.9223592225 ± 0.0000000003 au, e = 0.1911492198 ± 0.0000000124, H = 18.999 mag
NULL
  2026-02-26 21:11:58  RA 216.52967 Dec -18.32644  30.3 at 274 (space-based)
  2026-02-26 19:51:47  RA 216.53337 Dec -18.32676  29.9 at 274 (space-based)
13 names hold "eros"; the first: (433) Eros
```

(as answered on 2026-10-27: six calls; 2024 YR4's latest observations are the James Webb Space
Telescope's, observatory `274`; the elements are `Quantity`s with their uncertainties)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        mpc: { factory: mpc, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
```

`observations()` is a method of `MpcSource`, not of the interface the bundle injects: check the
instance before calling it.

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

The MPC's own: its APIs take a JSON body on a GET (a POST answers 405); a body it cannot read is
its 400 with its schema, a `ProviderException`. A name nobody has (`found: 0`) or two bodies share
(`found: 2`) is null. Before any call: an identifier of another base (`wikidata:...`,
`simbad:...`, `CHEBI:...`) given to `entity()` or `observations()` (`NotSupportedException`).

## Verified, and not

Against the real service on 2026-10-15: Eros by its number and name (two calls, 1.6 s), Apophis by
its name and its packed designation (`K04M04N`), 1P/Halley and C/2020 F3 (NEOWISE) - cometary
elements only -, 2024 YR4 by `jpl.sbdb:2024 YR4` (MOID 0.00275 au, U 1); `Halley` (two bodies) and
an unknown name null; the search for `eros` (thirteen names, (433) Eros first); the latest
observations of 2024 YR4 (JWST, 2026-02-26), Apophis (7.4 s) and Eros (14.5 s, 18,679
observations); `get-orb` answered by a POST: 405; the Designation Identifier given a bare string:
400 with its schema. The fixtures in `Tests/Fixtures/` are its answers.

Again on 2026-10-27: Apophis by its name, `Halley` null, 2024 YR4's latest observations (JWST),
the search for `eros` (13 names; the example above).

Not verified: a natural satellite or an interstellar object; a 429 (no rate published, none met);
the Identifier's other comparison (`%`); a mirror (`base_uri`).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Minor Planet Center, the IAU, the Center for Astrophysics | Harvard & Smithsonian
or NASA. The MPC publishes no terms of use nor rate for its APIs; credit the Minor Planet Center
as the source, keep the observers' and programs' credits the observations carry, and ask the
observations sparingly, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-mpc#terms-of-use) says. Whoever runs
the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-mpc#responsibility) section.
