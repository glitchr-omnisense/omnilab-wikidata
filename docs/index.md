# omnilab/wikidata

## Installation

```sh
composer require omnilab/wikidata
```

```php
use Omnilab\Wikidata\WikidataSourceFactory;

$wikidata = (new WikidataSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, but Wikimedia wants to reach the caller
    'language' => 'en',                // optional
    'synonyms' => 20,                  // optional
]);
```

With the Symfony bundle, the factory is registered as `wikidata` (see the core's docs).

## Calls

Every call goes to the Action API, `https://www.wikidata.org/w/api.php`, in JSON
(`formatversion=2`). The SPARQL endpoint (`query.wikidata.org`) is not used: CirrusSearch's
`haswbstatement` finds an item by an identifier in one plain API call.

| Method | Reads | Action API |
|---|---|---|
| `entity()` | a Q-number (`Q60235`, `wikidata:Q60235`, its page or entity URI) | `wbgetentities&ids=` |
| | an identifier its statements carry: PubChem CID, ChEBI, CAS, InChIKey, ChEMBL, UNII, DSSTox, KEGG, UniProt, NCBI Gene, Ensembl, NCBI Taxonomy, GBIF, iNaturalist, SIMBAD, JPL SBDB, USGS earthquake | `query&list=search&srsearch=haswbstatement:P662=2519` first |
| | a label or an alias | `wbsearchentities&search=` first; the first result whose matched text is the one given, whatever its case |
| `search()` | `Query::$text`, no domain (or `other`) | `wbsearchentities` in the source's language, then `wbgetentities` |
| | `Query::$text` and a domain | `query&list=search&srsearch=<text> haswbstatement:<the domain's>`, then `wbgetentities` |

Then one `wbgetentities&props=labels` for the labels of the items' classes (P31, the `kind`) and
of the units not in the source's table; a label read once is kept for the source's life.

An identifier two items carry names neither: `null` (CAS 7732-18-5 is on water and on distilled
water: so are some ISC-GEM earthquakes, `iscgem900408`). An identifier of a scheme the source does
not look up - an InChI, a PDB code - is refused before any call; unknown is `null`.

A domain narrows a search to the items that carry one of these statements:

| Domain | haswbstatement |
|---|---|
| chemistry | `P231` CAS, `P235` InChIKey, `P662` PubChem CID, `P683` ChEBI |
| biology | `P352` UniProt, `P351` Entrez Gene, `P594` Ensembl gene, `P353` HGNC symbol, `P3937` Reactome |
| taxonomy | `P225` taxon name |
| astronomy | `P3083` SIMBAD, `P716` JPL SBDB, `P13228` Gaia DR3, `P5667` NASA Exoplanet Archive |
| earth | `P3196` USGS earthquake ID; `P31` earthquake (Q7944), volcano (Q8072), stratovolcano (Q169358), central volcano (Q190869) |

## What an item holds

`id` (its Q-number), `label`, `description` and `synonyms` (its aliases, those that are
identifiers left out, 20 by default) in the language asked - `mul` (the names alike in every
language) and Wikidata's fallbacks after it; `kind` (the label of its first class, P31: caffeine is
a "type of chemical entity", the cougar a "taxon"); `domain` (told by its first identifier of the
table below, `taxonomy` for an item with a taxon name, `other` without); `url` (its page); `image`
(its chemical structure, P117, else its image, P18, as a Commons file address); `place` (its
coordinates on Earth, P625); `time` (its point in time, P585, when given to the day).

Of each property, the values of its best statements: the preferred ones when there are, else the
normal ones; never the deprecated, nor "unknown value". Qualifiers (the temperature of a pKa, a
solvent) and references are not read.

`identifiers`, in this order:

| Property | Scheme | Looked up by |
|---|---|---|
| P662 PubChem CID | `pubchem.cid` | yes |
| P683 ChEBI ID | `chebi` | yes |
| P231 CAS Registry Number | `cas` | yes |
| P235 InChIKey | `inchikey` | yes |
| P234 InChI | `inchi` | no |
| P592 ChEMBL ID | `chembl` | yes |
| P652 UNII | `unii` | yes |
| P3117 DSSTox substance ID | `dsstox` | yes |
| P665 KEGG ID | `kegg` | yes |
| P352 UniProt protein ID | `uniprot` | yes |
| P351 Entrez Gene ID | `ncbi.gene` | yes |
| P594 Ensembl gene ID | `ensembl` | yes |
| P685 NCBI taxonomy ID | `ncbi.taxon` | yes |
| P14607 GBIF taxon ID (after the 2026 update) | `gbif`: a key of the Catalogue of Life eXtended Release, `4CGXP` | yes |
| P846 GBIF species ID (before the 2026 update) | `gbif`: a number of the legacy GBIF Backbone, `5219404` | yes |
| P3151 iNaturalist taxon ID | `inaturalist` | yes |
| P3083 SIMBAD ID | `simbad` | yes |
| P716 JPL Small-Body Database SPK-ID | `jpl.sbdb` | yes |
| P3937 Reactome ID | `reactome`: `R-HSA-70171` (43,834 items on 2026-10-15) | yes |
| P13228 Gaia ID | `gaia`: a Gaia DR3 source_id, `4472832130942575872` (4,606,263 items) | yes |
| P5667 NASA Exoplanet Archive ID | `exoplanet`: a planet's or a system's name, `51 Peg b`, `Proxima Cen` (12,976 items) | yes |
| P3196 USGS earthquake ID | `usgs.event`: `us7000pn9s`, or a catalogue ComCat keeps (`iscgem895681`, `official20110311054624120_30`) | yes |

Properties:

| Key | Property | Value |
|---|---|---|
| `molecular_formula` | P274 chemical formula | text, its subscripts made digits (C₈H₁₀N₄O₂ is C8H10N4O2) |
| `smiles`, `connectivity_smiles` | P2017 isomeric SMILES, P233 canonical SMILES | text |
| `inchi`, `inchikey` | P234, P235 | text |
| `mass` | P2067 mass | Quantity (a compound's in Da: its monoisotopic mass, as Wikidata states it) |
| `melting_point`, `boiling_point`, `flash_point` | P2101, P2102, P2128 | Quantity |
| `density` | P2054 | Quantity |
| `pka` | P1117 | Quantity, no unit |
| `taxon_name` | P225 | text |
| `radius`, `orbital_period`, `redshift`, `distance_from_earth` | P2120, P2146, P1090, P2583 | Quantity |
| `elevation` | P2044 elevation above sea level | Quantity |
| `richter_magnitude` | P2528 earthquake magnitude on the Richter scale | Quantity, no unit |
| `moment_magnitude` | P2527 earthquake magnitude on the moment magnitude scale | Quantity, no unit |

A unit is the symbol of a table of 29 common units (Da, °C, K, °F, g/cm³, kg, m, km, s, a, km/h,
Pa, ly, pc, au, M☉, %...), else the unit item's label in the language asked. A bound (± ) becomes
the Quantity's uncertainty, half the interval.

## What Wikidata does not give here

- PDB structures (P638) are not cross-references: on caffeine they are the 22 structures that
  hold it - none is caffeine itself, so the Merger must not take one for it.
- GBIF's two keys are one scheme read from two properties: P14607, the key of GBIF's taxonomy
  since June 2026 (the Catalogue of Life eXtended Release: `^[23456789BCDFGHJKLMNPQRSTVWXYZ]{1,6}$`),
  and P846, the legacy Backbone's number. An item gives both when it has both, P14607's first; a
  key is looked for in P14607, a number in P846. On 2026-10-15, 2663 items carried P14607 (the
  bottlenose dolphin, `59R5F`), not the lion (Q140: P846 `5219404` only): a lookup by the new key
  of a taxon not yet given one is `null`, by its number it is found - the GBIF source gives both.
- P3196, the USGS earthquake ID, was on 1,511 items on 2026-10-15 (1,531 statements: 17 items
  carry several, 16 ids are on two items): `us...` ids for most (967), ISC-GEM's for 466, and for the
  great historical earthquakes the ids of the catalogues ComCat keeps (`official...`: Tohoku 2011,
  Sumatra 2004, Chile 2010, San Francisco 1906) - which the core's `usgs.event` scheme reads, and
  the USGS answers. One statement is an "unknown value", which is never read. The magnitude Wikidata
  gives is usually on the moment scale (P2527); its time is a day (P585, no hour), its place
  the epicentre (P625).
- No other statement is read: the item-valued ones (subclass of, has part...), the qualifiers, the
  references, the sitelinks. No SPARQL, no lexemes, no properties as entities.
- The search index (CirrusSearch) follows edits after a delay: a statement just added may not be
  found by `haswbstatement` yet, while `wbgetentities` already gives it.

## Verified, and not

Against the real service on 2026-10-15, without a key: every property number above, read from
Wikidata (`wbgetentities&ids=P662|P683|...`), and every unit and class item of the tables; caffeine
(Q60235) in English and French with its identifiers, units and classes; caffeine found by its
PubChem CID, its ChEBI id, its CAS number, its InChIKey and its label; the cougar (Q35255) with its
NCBI, GBIF and iNaturalist ids; the bottlenose dolphin (Q174199) found by its P14607 key `59R5F` and
by its P846 number `2440447`, the lion by its number but not by its key `4CGXP` (no P14607 on it);
CAS 7732-18-5 on two items; the 2025 Myanmar earthquake (Q133574091) found by its USGS id
`us7000pn9s`, its moment magnitude 7.7, its day and epicentre, and `iscgem900408` on two items;
every P3196 value (read through the query service, once, for this count) matched against the
`usgs.event` scheme: all read; that evening 51 Peg b (Q242309) with its SIMBAD and NASA Exoplanet
Archive names (`* 51 Peg b`, `51 Peg b`), Barnard's star (Q14268) found by its Gaia DR3 source_id
`4472832130942575872` (P13228), glycolysis (Q45317172) by its Reactome id `R-HSA-70171` (P3937),
and the first 20,000 P3937 values all of the `reactome` scheme's shape; an item that does not exist
(`no-such-entity`); a search by label and its second page; a search among chemistry (46 items);
the domain filters (Tohoku and Etna among earth, the Myanmar earthquake first for "Myanmar" with P3196, Andromeda among astronomy, TP53 among biology,
"puma" among taxonomy); the answer while the servers lag (`maxlag`, 200 with `Retry-After: 5`,
asked with `maxlag=-1`); a call without a User-Agent, or with a library's generic one, refused
(403). The fixtures in `Tests/Fixtures/` are its answers, the statements' references and
qualifiers taken out.

Not verified: a 429 of Wikimedia's rate limits (the handling is the core's, `Retry-After` read);
the `ratelimited` API error, handled as a `RateLimitedException` as the etiquette describes it; the
delay of the search index after an edit.
