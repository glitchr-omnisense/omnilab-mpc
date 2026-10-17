# omnilab/mpc

The IAU's Minor Planet Center for [omniscient/omnilab](https://github.com/glitchr-studio/omnilab): a
minor planet, a comet or a natural satellite by its number, its designation (provisional, packed
or not, primary or secondary) or its name - its designations, name and naming citation, its
orbit as the MPC computes it (the osculating elements with their uncertainties at their epoch,
the perihelion, the period, the MOIDs), its absolute magnitude, how it was observed (the
observations, oppositions and arc, the uncertainty parameter U); a search of the names; and,
outside the contract, its latest observations. No key.

```php
$mpc = (new MpcSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$eros = $mpc->entity('433');                      // or 'Eros', 'A898 PA', 'mpc:433', 'jpl.sbdb:433'
$eros->label;                                     // (433) Eros
$eros->identifiers()->keys();                     // mpc:433, jpl.sbdb:433, mpc:A898 PA
$eros->property('semi_major_axis');               // 1.4582437174 ± 0.0000000000435 au
$eros->value('orbit_type');                       // Amor
$eros->value('observations');                     // 18679, over 1893-2026
$mpc->entity('2024 YR4')->property('moid');       // 0.00275 au
$mpc->search(new Query('eros', limit: 5));        // (433) Eros, (1943) Anteros...
$mpc->observations('2024 YR4', 3);                // the latest first: 2026-02-26 by JWST (274), V 30.3
```

```yaml
omnilab:
    sources:
        mpc:
            factory: mpc
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
```

A body is named in the core's `mpc` scheme by its number, else its designation; JPL's Small-Body
Database names it the same, so its `jpl.sbdb` identifier is among its own and the `Collector`
walks between omnilab/mpc and omnilab/jpl in either direction.

See [docs/](docs/index.md) for what is read, how it maps, and what the MPC does not give here.

## Terms of use

The Minor Planet Center operates under the International Astronomical Union at the Center for
Astrophysics | Harvard & Smithsonian, funded by NASA. Its documentation hub
(docs.minorplanetcenter.net, read on 2026-10-15) publishes no terms of use and no rate for its
APIs; credit the Minor Planet Center as the source of the designations, orbits and observations,
and keep the observers' and programs' credits that the observations carry. An object's
observations come whole, however many (Eros: 18,679 in 1.7 MB, 5 to 15 s): ask them sparingly.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Minor Planet Center, the International Astronomical Union or the Smithsonian Astrophysical Observatory. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
