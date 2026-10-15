# omnilab/wikidata

Wikidata for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): an item by its
Q-number, by the identifier of a science base its statements carry - PubChem CID, ChEBI, CAS,
InChIKey, ChEMBL, UNII, DSSTox, KEGG, UniProt, NCBI Gene, Ensembl, NCBI Taxonomy, GBIF,
iNaturalist, SIMBAD, JPL SBDB, USGS earthquake - or by a label; its label, description and aliases in the language
asked, those identifiers as cross-references for the Collector to follow, and a few properties
with their units (mass, melting and boiling points, density, pKa, taxon name, radius, an earthquake's magnitude...); a search
by label, narrowed to a domain when asked. Through Wikidata's Action API, no key.

```php
$wikidata = (new WikidataSourceFactory($http))->create(['mailto' => 'support@glitchr.io', 'language' => 'fr']);
$caffeine = $wikidata->entity('Q60235');
$caffeine->label;                                // caféine
$caffeine->identifier(Scheme::PUBCHEM_CID);      // 2519
$caffeine->properties('melting_point');          // 234 °C, 235 °C
$wikidata->entity(Identifier::cid(2519));        // the same, by the CID its statements carry
$wikidata->entity('CHEBI:27732');                // or its ChEBI id, CAS number, InChIKey...
$wikidata->search(new Query('caffeine', Domain::CHEMISTRY, limit: 10));
```

```yaml
omnilab:
    sources:
        wikidata:
            factory: wikidata
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent: Wikimedia refuses a call that does not say who makes it
                language: 'fr'                 # labels, descriptions, aliases; Wikidata's fallbacks after it
                synonyms: 20                   # how many aliases to keep; 0: none
                throttle: 0.3                  # 200 calls a minute for a client known by its User-Agent: never lower
```

With PubChem and ChEBI configured too, the `Collector` follows a compound from one to the others:
`$collector->collect('pubchem', 'caffeine')` reaches Wikidata by caffeine's PubChem CID.

## Terms of use

Wikidata's structured data is under [CC0](https://creativecommons.org/publicdomain/zero/1.0/).
Wikimedia's [User-Agent policy](https://meta.wikimedia.org/wiki/User-Agent_policy) asks every
client to name itself and a way to reach its operator: a call without such a User-Agent is
refused (403). The source sends `omnilab/1.x (+https://github.com/glitchr-studio/omnilab;
mailto:...)` - set `mailto`, or `user_agent` to name your application. Wikimedia's
[rate limits](https://www.mediawiki.org/wiki/Wikimedia_APIs/Rate_limits), new in 2026, allow an
unauthenticated client with such a User-Agent 200 calls a minute; the
[API etiquette](https://www.mediawiki.org/wiki/API:Etiquette) asks for calls in series. The source
leaves 0.3 s between two and an item costs two or three: cache what it answers.

See [docs/](docs/index.md) for what is read, how it maps, and what Wikidata does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Wikimedia Foundation (Wikidata). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
