<?php

namespace Omnilab\Wikidata;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Point;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wikidata, through its Action API (www.wikidata.org/w/api.php): an item by
 * its Q-number, by the identifier of another base its statements carry
 * (a PubChem CID, a ChEBI id, a CAS number, an InChIKey, a UniProt
 * accession, an NCBI taxon... found with CirrusSearch's haswbstatement) or
 * by a label; its label, description and aliases in the language asked,
 * those identifiers as cross-references, a few properties with their units,
 * and a search by label, narrowed to a domain when asked.
 *
 * No key. Wikimedia asks for a User-Agent that names the caller and a way to
 * reach them (a request without one is refused, 403), calls in series, and
 * allows an unauthenticated client with such a User-Agent 200 calls a
 * minute: the source leaves 0.3 s between two (the "throttle" option). An
 * item costs two calls (its statements, then the labels of its class and of
 * units the source does not know), three when found by another identifier.
 */
final class WikidataSource extends HttpSource
{
    public const BASE_URI = 'https://www.wikidata.org/w/api.php';

    /** The most items one search page is asked for (wbsearchentities and wbgetentities stop at 50). */
    public const MAX_PER_PAGE = 50;

    /**
     * The identifier properties read as cross-references, in the order an
     * item's domain is told by: the property => the scheme, and whether
     * entity() looks an item up by it.
     */
    private const IDENTIFIERS = [
        'P662' => [Scheme::PUBCHEM_CID, true],
        'P683' => [Scheme::CHEBI, true],
        'P231' => [Scheme::CAS, true],
        'P235' => [Scheme::INCHIKEY, true],
        'P234' => [Scheme::INCHI, false],
        'P592' => [Scheme::CHEMBL, true],
        'P652' => [Scheme::UNII, true],
        'P3117' => [Scheme::DSSTOX, true],
        'P665' => [Scheme::KEGG, true],
        'P352' => [Scheme::UNIPROT, true],
        'P351' => [Scheme::NCBI_GENE, true],
        'P594' => [Scheme::ENSEMBL, true],
        'P685' => [Scheme::NCBI_TAXON, true],
        // GBIF's key since its 2026 taxonomy (4CGXP), then the legacy Backbone's number (5219404): one scheme, two properties.
        'P14607' => [Scheme::GBIF, true],
        'P846' => [Scheme::GBIF, true],
        'P3151' => [Scheme::INATURALIST, true],
        'P3937' => [Scheme::REACTOME, true],
        'P3083' => [Scheme::SIMBAD, true],
        // A Gaia DR3 source_id (4472832130942575872), a planet's or a system's name at the NASA Exoplanet Archive (51 Peg b).
        'P13228' => [Scheme::GAIA, true],
        'P5667' => [Scheme::EXOPLANET, true],
        'P716' => [Scheme::JPL_SBDB, true],
        // An earthquake's id in the USGS catalogue (ComCat): us7000pn9s, or one of the catalogues it keeps (iscgem..., official...).
        'P3196' => [Scheme::USGS_EVENT, true],
        // Genes, diseases, phenotypes, variants: HGNC:4827, MIM 141900, ORPHA:232, MONDO_0017146, HP:0001250, EFO's 0000400, VCV 13961, rs113488022.
        'P354' => [Scheme::HGNC, true],
        'P492' => [Scheme::OMIM, true],
        'P5270' => [Scheme::MONDO, true],
        'P1550' => [Scheme::ORPHANET, true],
        'P3841' => [Scheme::HP, true],
        'P11956' => [Scheme::EFO, true],
        'P1929' => [Scheme::CLINVAR, true],
        'P6861' => [Scheme::DBSNP, true],
        // Classes: a GO term, an InterPro entry, a Pfam family, an EC number - related things on a gene's or a protein's item (CLASSES).
        'P686' => [Scheme::GO, true],
        'P2926' => [Scheme::INTERPRO, true],
        'P3519' => [Scheme::PFAM, true],
        'P591' => [Scheme::EC, true],
        // Taxa in WoRMS, ITIS, the Catalogue of Life, the Paleobiology Database, eBird, the IUCN Red List.
        'P850' => [Scheme::WORMS, true],
        'P815' => [Scheme::ITIS, true],
        'P10585' => [Scheme::COL, true],
        'P10907' => [Scheme::PBDB, true],
        'P3444' => [Scheme::EBIRD, true],
        'P627' => [Scheme::IUCN, true],
        // A minor planet or comet at the Minor Planet Center (99942), a USGS water monitoring location (its NWIS number), a CODATA constant (mp).
        'P5736' => [Scheme::MPC, true],
        'P13020' => [Scheme::USGS_SITE, true],
        'P1645' => [Scheme::CODATA, true],
    ];

    /**
     * Classes, not things: on an item of a gene or a protein (one that
     * carries GENE_OR_PROTEIN), an EC number, an InterPro entry, a Pfam
     * family or a GO term is what it belongs to - related under these
     * relations, not among its identifiers: two kinases sharing EC 2.7.10.1
     * are not one. On the item of the class itself (hydrolase activity, the
     * globin family) it is the same thing: an identifier.
     */
    private const CLASSES = ['P591' => 'enzyme', 'P2926' => 'domain', 'P3519' => 'domain', 'P686' => 'go'];

    /** An item of a gene (Entrez Gene ID) or of a protein (UniProt). */
    private const GENE_OR_PROTEIN = ['P351', 'P352'];

    /**
     * Other things an item is tied to, always: the property => the scheme,
     * the relation. A crystal structure of the COD, a material of the
     * Materials Project is one determination, one polymorph: magnesium and
     * sodium chloride have several, none of them the element or the compound.
     */
    private const RELATED = [
        'P9824' => [Scheme::COD, 'structure'],
        'P9902' => [Scheme::MATERIALS_PROJECT, 'structure'],
    ];

    /**
     * A lookup by these schemes leaves out the items that carry these
     * statements: an HGNC id or a MIM number is on the gene's item and,
     * often, on its protein's (HGNC:4827 on HBB, Q14905542, and hemoglobin
     * beta, Q424422); a class is on many genes' and proteins' items.
     */
    private const LOOKUP_EXCLUDES = ['P354' => ['P352'], 'P492' => ['P352'], 'P591' => self::GENE_OR_PROTEIN, 'P2926' => self::GENE_OR_PROTEIN, 'P3519' => self::GENE_OR_PROTEIN, 'P686' => self::GENE_OR_PROTEIN];

    /** The properties read as such: the property => the key, the label (null: a quantity, a string otherwise). */
    private const PROPERTIES = [
        'P274' => ['molecular_formula', 'Molecular formula'],
        'P2017' => ['smiles', 'SMILES (isomeric)'],
        'P233' => ['connectivity_smiles', 'Canonical SMILES'],
        'P234' => ['inchi', 'InChI'],
        'P235' => ['inchikey', 'InChIKey'],
        'P2067' => ['mass', 'Mass'],
        'P2101' => ['melting_point', 'Melting point'],
        'P2102' => ['boiling_point', 'Boiling point'],
        'P2128' => ['flash_point', 'Flash point'],
        'P2054' => ['density', 'Density'],
        'P1117' => ['pka', 'pKa'],
        'P225' => ['taxon_name', 'Taxon name'],
        'P2120' => ['radius', 'Radius'],
        'P2146' => ['orbital_period', 'Orbital period'],
        'P1090' => ['redshift', 'Redshift'],
        'P2583' => ['distance_from_earth', 'Distance from Earth'],
        'P2044' => ['elevation', 'Elevation above sea level'],
        'P2528' => ['richter_magnitude', 'Magnitude (Richter scale)'],
        'P2527' => ['moment_magnitude', 'Magnitude (moment magnitude scale)'],
    ];

    /** The units met most, by item: their symbols, so that no call is made for them. */
    private const UNITS = [
        'Q483261' => 'Da', 'Q25267' => '°C', 'Q11579' => 'K', 'Q42289' => '°F',
        'Q13147228' => 'g/cm³', 'Q844211' => 'kg/m³', 'Q11570' => 'kg', 'Q41803' => 'g',
        'Q11573' => 'm', 'Q828224' => 'km', 'Q174728' => 'cm', 'Q174789' => 'mm',
        'Q11574' => 's', 'Q7727' => 'min', 'Q25235' => 'h', 'Q573' => 'd', 'Q1092296' => 'a', 'Q577' => 'year',
        'Q180154' => 'km/h', 'Q182429' => 'm/s', 'Q44395' => 'Pa', 'Q21064807' => 'kPa',
        'Q531' => 'ly', 'Q12129' => 'pc', 'Q1811' => 'au', 'Q180892' => 'M☉', 'Q48440' => 'R☉',
        'Q11229' => '%', 'Q13035094' => 'J/mol',
    ];

    /** search() narrowed to a domain: the statements an item of it carries (haswbstatement, one of them). */
    private const DOMAINS = [
        'chemistry' => 'P231|P235|P662|P683',
        // Genes, proteins, pathways, GO terms, protein families, diseases, variants.
        'biology' => 'P352|P351|P594|P353|P3937|P686|P2926|P492|P5270|P6861',
        'taxonomy' => 'P225',
        'astronomy' => 'P3083|P716|P13228|P5667|P5736',
        // Earthquakes and volcanoes: a USGS earthquake id, or instance of earthquake, volcano, stratovolcano, central volcano; a USGS water site.
        'earth' => 'P3196|P31=Q7944|P31=Q8072|P31=Q169358|P31=Q190869|P13020',
    ];

    /** @var array<string, string> the labels already read, by item */
    private array $labels = [];

    /**
     * @param string $language the language of labels, descriptions and aliases ("en", "fr", "de"...), with Wikidata's fallbacks
     * @param int    $synonyms how many aliases to keep (0: none)
     */
    public function __construct(
        HttpClientInterface $http,
        private readonly string $language = 'en',
        private readonly int $synonyms = 20,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = 0.3,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'wikidata';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES];
    }

    public function entity(Identifier|string $id): ?Entity
    {
        $schemes = [Scheme::WIKIDATA, ...array_map(static fn (array $p) => $p[0], array_filter(self::IDENTIFIERS, static fn (array $p) => $p[1]))];
        if ($id instanceof Identifier) {
            $identifier = \in_array($id->scheme, $schemes, true) ? $id : throw NotSupportedException::identifier('wikidata', (string) $id);
        } else {
            $text = trim($id);
            $identifier = Identifier::parse($text);
            if (null !== $identifier && !\in_array($identifier->scheme, $schemes, true)) {
                throw NotSupportedException::identifier('wikidata', $id);
            }
            if (null === $identifier) {
                return '' === $text ? null : $this->byLabel($text);
            }
        }
        $qid = Scheme::WIKIDATA === $identifier->scheme ? $identifier->value : $this->byStatement($identifier);

        return null === $qid ? null : ($this->items([$qid])[0] ?? null);
    }

    /**
     * Items by label or alias (wbsearchentities, in the source's language),
     * or - for a domain - by text among the items that carry a statement of
     * that domain (CirrusSearch with haswbstatement); each read whole.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ('' === $text) {
            return new Page();
        }
        $limit = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $offset = max(0, (int) $query->cursor);
        $filter = null === $query->domain ? null : (self::DOMAINS[$query->domain->value] ?? null);
        if (null === $filter) {
            $data = $this->api(['action' => 'wbsearchentities', 'search' => $text, 'language' => $this->language, 'uselang' => $this->language, 'type' => 'item', 'limit' => $limit, 'continue' => $offset ?: null]);
            $ids = array_column($data['search'] ?? [], 'id');
            $next = isset($data['search-continue']) ? (string) $data['search-continue'] : null;
            $total = null;
        } else {
            $data = $this->api(['action' => 'query', 'list' => 'search', 'srsearch' => $text.' haswbstatement:'.$filter, 'srnamespace' => 0, 'srlimit' => $limit, 'sroffset' => $offset ?: null, 'srprop' => '', 'srinfo' => 'totalhits']);
            $ids = array_column($data['query']['search'] ?? [], 'title');
            $next = isset($data['continue']['sroffset']) ? (string) $data['continue']['sroffset'] : null;
            $total = isset($data['query']['searchinfo']['totalhits']) ? (int) $data['query']['searchinfo']['totalhits'] : null;
        }

        return new Page($ids ? $this->items($ids) : [], $next, $total);
    }

    /**
     * The item that carries the identifier (haswbstatement:P662=2519): null
     * when none does, or when several do - an identifier two items share
     * names neither for sure. The search index follows Wikidata's edits
     * after a delay, not at once: a statement just added may not be found.
     */
    private function byStatement(Identifier $identifier): ?string
    {
        $property = Scheme::GBIF === $identifier->scheme
            ? (ctype_digit($identifier->value) ? 'P846' : 'P14607')
            : array_search($identifier->scheme, array_map(static fn (array $p) => $p[0], self::IDENTIFIERS), true);
        // The value as Wikidata writes it: GO:0005829, HP:0001250, MONDO_0017146, a NWIS site without its agency.
        $value = match ($identifier->scheme) {
            Scheme::GO, Scheme::HP => $identifier->display(),
            Scheme::MONDO => 'MONDO_'.$identifier->value,
            Scheme::USGS_SITE => substr($identifier->value, \strlen('USGS-')),
            default => $identifier->value,
        };
        $statement = str_contains($value, ' ') ? '"'.$property.'='.str_replace('"', '', $value).'"' : $property.'='.$value;
        $excluded = implode('', array_map(static fn (string $p) => ' -haswbstatement:'.$p, self::LOOKUP_EXCLUDES[$property] ?? []));
        $data = $this->api(['action' => 'query', 'list' => 'search', 'srsearch' => 'haswbstatement:'.$statement.$excluded, 'srnamespace' => 0, 'srlimit' => 2, 'srprop' => '']);
        $found = array_column($data['query']['search'] ?? [], 'title');

        return 1 === \count($found) ? $found[0] : null;
    }

    /** The item whose label or alias is the text, whatever its case, the first Wikidata ranks; null when none is. */
    private function byLabel(string $text): ?Entity
    {
        $data = $this->api(['action' => 'wbsearchentities', 'search' => $text, 'language' => $this->language, 'uselang' => $this->language, 'type' => 'item', 'limit' => 10]);
        foreach ($data['search'] ?? [] as $result) {
            if (mb_strtolower((string) ($result['match']['text'] ?? '')) === mb_strtolower($text)) {
                return $this->items([(string) $result['id']])[0] ?? null;
            }
        }

        return null;
    }

    /**
     * Items read whole (wbgetentities), in the order asked, those that do
     * not exist left out; then one call for the labels of their classes and
     * of the units the source does not know.
     *
     * @param list<string> $ids
     *
     * @return list<Entity>
     */
    private function items(array $ids): array
    {
        $data = $this->api(['action' => 'wbgetentities', 'ids' => implode('|', $ids), 'props' => 'labels|descriptions|aliases|claims', 'languages' => $this->languages(), 'languagefallback' => 1], ['no-such-entity']);
        $items = array_values(array_filter(array_map(static fn (string $id) => $data['entities'][$id] ?? null, $ids), static fn (?array $item) => \is_array($item) && !isset($item['missing']) && isset($item['claims'])));
        $wanted = [];
        foreach ($items as $item) {
            foreach (self::best($item['claims']['P31'] ?? []) as $class) {
                $wanted[] = $class['id'] ?? null;
            }
            foreach (array_keys(self::PROPERTIES) as $property) {
                foreach (self::best($item['claims'][$property] ?? []) as $value) {
                    $wanted[] = \is_array($value) ? self::unitItem($value['unit'] ?? null) : null;
                }
            }
        }
        $this->label(array_values(array_unique(array_filter($wanted, fn (?string $id) => null !== $id && !isset(self::UNITS[$id]) && !isset($this->labels[$id])))));

        return array_map(fn (array $item) => $this->toEntity($item), $items);
    }

    /** @param list<string> $ids items whose labels to read, at most 50 a call */
    private function label(array $ids): void
    {
        foreach (array_chunk($ids, self::MAX_PER_PAGE) as $chunk) {
            $data = $this->api(['action' => 'wbgetentities', 'ids' => implode('|', $chunk), 'props' => 'labels', 'languages' => $this->languages(), 'languagefallback' => 1], ['no-such-entity']);
            foreach ($data['entities'] ?? [] as $id => $entity) {
                $label = self::pick($entity['labels'] ?? [], $this->language);
                if (null !== $label) {
                    $this->labels[(string) $id] = $label;
                }
            }
        }
    }

    /** @param array<string, mixed> $item an entity of wbgetentities (formatversion 2) */
    private function toEntity(array $item): Entity
    {
        $qid = (string) $item['id'];
        $claims = $item['claims'];

        $identifiers = [];
        $related = [];
        $domain = null;
        $geneOrProtein = [] !== array_intersect(self::GENE_OR_PROTEIN, array_keys($claims));
        foreach (self::IDENTIFIERS as $property => [$scheme]) {
            $relation = $geneOrProtein ? (self::CLASSES[$property] ?? null) : null;
            foreach (self::best($claims[$property] ?? []) as $value) {
                if (\is_string($value) && null !== ($identifier = Identifier::tryOf($scheme, $value))) {
                    if (null !== $relation) {
                        $related[$relation][] = $identifier;
                        continue;
                    }
                    $identifiers[] = $identifier;
                    $domain ??= $scheme->domain();
                }
            }
        }
        foreach (self::RELATED as $property => [$scheme, $relation]) {
            foreach (self::best($claims[$property] ?? []) as $value) {
                if (\is_string($value) && null !== ($identifier = Identifier::tryOf($scheme, $value))) {
                    $related[$relation][] = $identifier;
                }
            }
        }
        if (isset($claims['P225'])) {
            $domain = Domain::TAXONOMY;
        }

        $properties = [];
        foreach (self::PROPERTIES as $property => [$key, $label]) {
            foreach (self::best($claims[$property] ?? []) as $value) {
                $value = $this->value($value, 'P274' === $property);
                if (null !== $value) {
                    $properties[] = new Property($key, $label, $value, 'wikidata');
                }
            }
        }

        $label = self::pick($item['labels'] ?? [], $this->language) ?? $qid;
        $synonyms = [];
        foreach ([$this->language, 'mul'] as $language) {
            foreach ($item['aliases'][$language] ?? [] as $alias) {
                $alias = trim((string) ($alias['value'] ?? ''));
                // An identifier given as an alias ("58-08-2") is no name.
                if ('' !== $alias && mb_strtolower($alias) !== mb_strtolower($label) && null === Identifier::parse($alias)) {
                    $synonyms[mb_strtolower($alias)] ??= $alias;
                }
            }
        }
        $class = self::best($claims['P31'] ?? [])[0]['id'] ?? null;
        $image = self::best($claims['P117'] ?? [])[0] ?? self::best($claims['P18'] ?? [])[0] ?? null;
        $coordinates = self::best($claims['P625'] ?? [])[0] ?? null;
        $time = self::best($claims['P585'] ?? [])[0] ?? null;

        return new Entity(
            id: Identifier::wikidata($qid),
            label: $label,
            domain: $domain ?? Domain::OTHER,
            kind: null === $class ? null : ($this->labels[$class] ?? null),
            synonyms: \array_slice(array_values($synonyms), 0, $this->synonyms),
            description: self::pick($item['descriptions'] ?? [], $this->language),
            properties: $properties,
            identifiers: new Identifiers($identifiers),
            related: array_map(static fn (array $ids) => new Identifiers($ids), $related),
            url: 'https://www.wikidata.org/wiki/'.$qid,
            image: \is_string($image) ? 'https://commons.wikimedia.org/wiki/Special:FilePath/'.rawurlencode(str_replace(' ', '_', $image)) : null,
            time: \is_array($time) ? self::when($time) : null,
            place: \is_array($coordinates) && isset($coordinates['latitude'], $coordinates['longitude']) && (null === ($coordinates['globe'] ?? null) || str_ends_with((string) $coordinates['globe'], '/Q2'))
                ? new Point((float) $coordinates['latitude'], (float) $coordinates['longitude']) : null,
            source: 'wikidata',
        );
    }

    /** A statement's value as a property's: a Quantity with its unit's symbol, a string (a formula's subscripts made digits). */
    private function value(mixed $value, bool $formula): Quantity|string|null
    {
        if (\is_string($value)) {
            $value = trim($formula ? strtr($value, ['₀' => '0', '₁' => '1', '₂' => '2', '₃' => '3', '₄' => '4', '₅' => '5', '₆' => '6', '₇' => '7', '₈' => '8', '₉' => '9']) : $value);

            return '' === $value ? null : $value;
        }
        if (!\is_array($value) || !isset($value['amount']) || !is_numeric($amount = ltrim((string) $value['amount'], '+'))) {
            return null;
        }
        $number = static fn (string $n): int|float => preg_match('~^-?\d+$~', $n) ? (int) $n : (float) $n;
        $uncertainty = isset($value['upperBound'], $value['lowerBound']) ? abs((float) $value['upperBound'] - (float) $value['lowerBound']) / 2 : null;
        $unit = self::unitItem($value['unit'] ?? null);

        return new Quantity($number($amount), null === $unit ? null : (self::UNITS[$unit] ?? $this->labels[$unit] ?? $unit), $uncertainty ?: null);
    }

    /**
     * Calls the Action API (JSON, formatversion 2). Its errors come with a
     * 200: "maxlag" and "ratelimited" are a RateLimitedException, the codes
     * listed as $empty an empty answer, any other a ProviderException.
     *
     * @param array<string, mixed> $query
     * @param list<string>         $empty
     */
    private function api(array $query, array $empty = []): array
    {
        $data = $this->getJson($this->baseUri, $query + ['format' => 'json', 'formatversion' => 2]) ?? [];
        $error = $data['error'] ?? null;
        if (\is_array($error)) {
            $code = (string) ($error['code'] ?? '');
            if (\in_array($code, $empty, true)) {
                return [];
            }
            if ('maxlag' === $code || 'ratelimited' === $code) {
                throw new RateLimitedException('wikidata', isset($error['lag']) ? (int) ceil((float) $error['lag']) : null);
            }
            throw new ProviderException('wikidata', \sprintf('%s: %s', $code, (string) ($error['info'] ?? '')));
        }

        return $data;
    }

    /** "fr|mul|en": the language asked, the multilingual terms (names alike in every language), English; their fallbacks come with languagefallback. */
    private function languages(): string
    {
        return implode('|', array_unique([$this->language, 'mul', 'en']));
    }

    /**
     * The values of a property's best statements: the preferred ones when
     * there are, else the normal ones; never the deprecated; no "unknown
     * value" nor "no value".
     *
     * @param list<array<string, mixed>> $statements
     *
     * @return list<mixed>
     */
    private static function best(array $statements): array
    {
        $ranked = ['preferred' => [], 'normal' => []];
        foreach ($statements as $statement) {
            $snak = $statement['mainsnak'] ?? [];
            if ('value' === ($snak['snaktype'] ?? null) && isset($ranked[$statement['rank'] ?? 'normal'], $snak['datavalue']['value'])) {
                $ranked[$statement['rank'] ?? 'normal'][] = $snak['datavalue']['value'];
            }
        }

        return $ranked['preferred'] ?: $ranked['normal'];
    }

    /** A label or description in the language asked, else in the one Wikidata fell back to. */
    private static function pick(array $terms, string $language): ?string
    {
        $term = $terms[$language] ?? $terms['mul'] ?? $terms['en'] ?? (\is_array(reset($terms)) ? reset($terms) : null);
        $value = \is_array($term) ? trim((string) ($term['value'] ?? '')) : '';

        return '' === $value ? null : $value;
    }

    /** "http://www.wikidata.org/entity/Q483261" is Q483261; "1" (no unit) is null. */
    private static function unitItem(?string $unit): ?string
    {
        return null !== $unit && preg_match('~/entity/(Q\d+)$~', $unit, $m) ? $m[1] : null;
    }

    /** A time value of Wikidata ("+2011-03-11T00:00:00Z", its precision a day or finer); null when coarser. */
    private static function when(array $time): ?\DateTimeImmutable
    {
        if ((int) ($time['precision'] ?? 0) < 11 || !isset($time['time'])) {
            return null;
        }

        return self::time(ltrim((string) $time['time'], '+'));
    }
}
