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
| | an identifier its statements carry: PubChem CID, ChEBI, CAS, InChIKey, ChEMBL, UNII, DSSTox, KEGG, UniProt, NCBI Gene, Ensembl, NCBI Taxonomy, GBIF, iNaturalist, SIMBAD, JPL SBDB | `query&list=search&srsearch=haswbstatement:P662=2519` first |
| | a label or an alias | `wbsearchentities&search=` first; the first result whose matched text is the one given, whatever its case |
| `search()` | `Query::$text`, no domain (or `other`) | `wbsearchentities` in the source's language, then `wbgetentities` |
| | `Query::$text` and a domain | `query&list=search&srsearch=<text> haswbstatement:<the domain's>`, then `wbgetentities` |

Then one `wbgetentities&props=labels` for the labels of the items' classes (P31, the `kind`) and
of the units not in the source's table; a label read once is kept for the source's life.

An identifier two items carry names neither: `null` (CAS 7732-18-5 is on water and on distilled
water). An identifier of a scheme the source does not look up - an InChI, a PDB code, a USGS
event - is refused before any call; unknown is `null`.

A domain narrows a search to the items that carry one of these statements:

| Domain | haswbstatement |
|---|---|
| chemistry | `P231` CAS, `P235` InChIKey, `P662` PubChem CID, `P683` ChEBI |
| biology | `P352` UniProt, `P351` Entrez Gene, `P594` Ensembl gene, `P353` HGNC symbol |
| taxonomy | `P225` taxon name |
| astronomy | `P3083` SIMBAD, `P716` JPL SBDB |
| earth | `P31` earthquake (Q7944), volcano (Q8072), stratovolcano (Q169358), central volcano (Q190869) |

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
| P846 GBIF species ID (before the 2026 update) | `gbif` | yes |
| P3151 iNaturalist taxon ID | `inaturalist` | yes |
| P3083 SIMBAD ID | `simbad` | yes |
| P716 JPL Small-Body Database SPK-ID | `jpl.sbdb` | yes |

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
| `richter_magnitude` | P2528 earthquake magnitude on the Richter scale | Quantity |

A unit is the symbol of a table of 29 common units (Da, °C, K, °F, g/cm³, kg, m, km, s, a, km/h,
Pa, ly, pc, au, M☉, %...), else the unit item's label in the language asked. A bound (± ) becomes
the Quantity's uncertainty, half the interval.

## What Wikidata does not give here

- PDB structures (P638) are not cross-references: on caffeine they are the 22 structures that
  hold it - none is caffeine itself, so the Merger must not take one for it.
- GBIF's taxon ids of its 2026 backbone (P14607) are letters (`^[23456789BCDFGHJKLMNPQRSTVWXYZ]{1,6}$`),
  not the core's numeric `gbif` scheme; the old keys (P846) are read, and api.gbif.org still
  answers them.
- No other statement is read: the item-valued ones (subclass of, has part...), the qualifiers, the
  references, the sitelinks. No SPARQL, no lexemes, no properties as entities.
- The search index (CirrusSearch) follows edits after a delay: a statement just added may not be
  found by `haswbstatement` yet, while `wbgetentities` already gives it.

## Verified, and not

Against the real service on 2026-10-15, without a key: every property number above, read from
Wikidata (`wbgetentities&ids=P662|P683|...`), and every unit and class item of the tables; caffeine
(Q60235) in English and French with its identifiers, units and classes; caffeine found by its
PubChem CID, its ChEBI id, its CAS number, its InChIKey and its label; the cougar (Q35255) with its
NCBI, GBIF and iNaturalist ids; CAS 7732-18-5 on two items; an item that does not exist
(`no-such-entity`); a search by label and its second page; a search among chemistry (46 items);
the domain filters (Tohoku and Etna among earth, Andromeda among astronomy, TP53 among biology,
"puma" among taxonomy); the answer while the servers lag (`maxlag`, 200 with `Retry-After: 5`,
asked with `maxlag=-1`); a call without a User-Agent, or with a library's generic one, refused
(403). The fixtures in `Tests/Fixtures/` are its answers, the statements' references and
qualifiers taken out.

Not verified: a 429 of Wikimedia's rate limits (the handling is the core's, `Retry-After` read);
the `ratelimited` API error, handled as a `RateLimitedException` as the etiquette describes it; the
delay of the search index after an edit.
