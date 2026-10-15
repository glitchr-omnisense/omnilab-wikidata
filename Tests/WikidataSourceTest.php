<?php

namespace Omnilab\Wikidata\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Exception\UnavailableException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use Omnilab\Wikidata\WikidataSourceFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from www.wikidata.org/w/api.php on 2026-10-15, the
 * statements' references and qualifiers taken out: caffeine (Q60235) in
 * English and in French and the label of its class, the cougar (Q35255) and
 * the label of its class, the common bottlenose dolphin (Q174199: GBIF's
 * new key and its legacy number) and the items that carry them (P14607=59R5F,
 * P846=2440447) or not (P14607=4CGXP), the 2025 Myanmar earthquake
 * (Q133574091) and the label of its class, the item that carries its USGS
 * id (P3196=us7000pn9s), the items whose statements carry PubChem CID 2519
 * and ChEBI 27732 (haswbstatement), the two that carry CAS 7732-18-5 (water
 * and distilled water), the items labelled "caffeine", a search for it
 * (three a page) and one among chemistry, each with its items and their
 * classes' labels, an item that does not exist (no-such-entity) and the
 * answer to a call while the servers lag (maxlag).
 */
final class WikidataSourceTest extends TestCase
{
    /** @var list<array<string, string>> */
    private array $calls = [];

    /** @var list<string> */
    private array $agents = [];

    private function source(?MockResponse $response = null, array $options = ['throttle' => 0]): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $request) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->calls[] = $query;
            $this->agents[] = (string) preg_replace('~^User-Agent: ~i', '', (string) current(preg_grep('~^User-Agent:~i', $request['headers'] ?? [])));
            if ($response) {
                return $response;
            }
            $lang = explode('|', (string) ($query['languages'] ?? 'en'))[0];
            $fixture = match ($query['action'] ?? null) {
                'wbgetentities' => match ($query['ids'] ?? null) {
                    'Q60235' => "entities-Q60235-$lang.json",
                    'Q35255' => 'entities-Q35255-en.json',
                    'Q174199' => 'entities-Q174199-en.json',
                    'Q113145171' => "labels-Q113145171-$lang.json",
                    'Q16521' => 'labels-Q16521-en.json',
                    'Q133574091' => 'entities-Q133574091-en.json',
                    'Q7944' => 'labels-Q7944-en.json',
                    'Q60235|Q16743231|Q12478159' => 'entities-search-caffeine-1.json',
                    'Q113145171|Q7889|Q215380' => 'labels-search-caffeine-1.json',
                    'Q60235|Q2974480|Q82375285' => 'entities-search-caffeine-chemistry.json',
                    'Q169336|Q35456|Q5139913' => 'labels-search-caffeine-chemistry.json',
                    default => 'error-no-such-entity.json',
                },
                'wbsearchentities' => 'caffeine' === $query['search'] ? (10 === (int) $query['limit'] ? 'label-caffeine-en.json' : 'search-caffeine-1.json') : null,
                'query' => match ($query['srsearch'] ?? null) {
                    'haswbstatement:P662=2519' => 'statement-P662-2519.json',
                    'haswbstatement:P683=27732' => 'statement-P683-27732.json',
                    'haswbstatement:P231=7732-18-5' => 'statement-P231-7732-18-5.json',
                    'haswbstatement:P14607=59R5F' => 'statement-P14607-59R5F.json',
                    'haswbstatement:P846=2440447' => 'statement-P846-2440447.json',
                    'haswbstatement:P14607=4CGXP' => 'statement-P14607-4CGXP.json',
                    'haswbstatement:P3196=us7000pn9s' => 'statement-P3196-us7000pn9s.json',
                    'caffeine haswbstatement:P231|P235|P662|P683' => 'search-caffeine-chemistry.json',
                    default => null,
                },
                default => null,
            };

            return new MockResponse(null === $fixture ? '{"batchcomplete":true,"query":{"searchinfo":{"totalhits":0},"search":[]},"search":[]}' : (string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        return (new WikidataSourceFactory($http))->create($options);
    }

    public function testCaffeineByItsItemWithItsLabelsInEnglish(): void
    {
        $caffeine = $this->source()->entity('Q60235');

        self::assertSame('wikidata:Q60235', $caffeine->id->key());
        self::assertSame('caffeine', $caffeine->label);
        self::assertSame('chemical compound used as a stimulant', $caffeine->description);
        self::assertSame(Domain::CHEMISTRY, $caffeine->domain, 'told by its first identifier, a PubChem CID');
        self::assertSame('type of chemical entity', $caffeine->kind, 'the label of its class (P31)');
        self::assertSame('https://www.wikidata.org/wiki/Q60235', $caffeine->url);
        self::assertSame('https://commons.wikimedia.org/wiki/Special:FilePath/Koffein_-_Caffeine.svg', $caffeine->image, 'its chemical structure (P117) before its picture (P18)');
        self::assertSame('guaranine', $caffeine->synonyms[0]);
        self::assertContains('theine', $caffeine->synonyms);
        self::assertCount(20, $caffeine->synonyms);
        self::assertSame('wikidata', $caffeine->source);
        self::assertSame(['Q60235', 'Q113145171'], array_column($this->calls, 'ids'), 'two calls: the item, the label of its class');
        self::assertSame(['labels|descriptions|aliases|claims', 'en|mul'], [$this->calls[0]['props'], $this->calls[0]['languages']]);
    }

    public function testItsCrossReferences(): void
    {
        $caffeine = $this->source()->entity(Identifier::wikidata('Q60235'));

        self::assertSame(['wikidata:Q60235', 'pubchem.cid:2519', 'chebi:27732', 'cas:58-08-2', 'inchikey:RYYVLZVUVIJVGH-UHFFFAOYSA-N', 'inchi:InChI=1S/C8H10N4O2/c1-10-4-9-6-5(10)7(13)12(3)8(14)11(6)2/h4H,1-3H3', 'chembl:CHEMBL113', 'unii:3G6A5W338E', 'dsstox:DTXSID0020232', 'kegg:D00528'], $caffeine->identifiers()->keys());
        self::assertNull($caffeine->identifier(Scheme::PDB), 'the 22 PDB structures that hold caffeine are not caffeine');
    }

    public function testItsPropertiesWithTheirUnits(): void
    {
        $caffeine = $this->source()->entity('Q60235');

        self::assertSame('C8H10N4O2', $caffeine->value('molecular_formula'), 'Wikidata\'s subscripts made digits');
        self::assertSame('Cn1c(=O)c2c(ncn2C)n(C)c1=O', $caffeine->value('smiles'));
        self::assertSame('CN1C=NC2=C1C(=O)N(C(=O)N2C)C', $caffeine->value('connectivity_smiles'));
        self::assertSame('RYYVLZVUVIJVGH-UHFFFAOYSA-N', $caffeine->value('inchikey'));
        self::assertEquals([new Quantity(194.08, 'Da'), new Quantity(194.08037556, 'Da')], array_map(static fn ($p) => $p->value, $caffeine->properties('mass')), 'every best-ranked statement');
        self::assertEquals([new Quantity(234, '°C'), new Quantity(235, '°C')], array_map(static fn ($p) => $p->value, $caffeine->properties('melting_point')));
        self::assertEquals(new Quantity(1.23, 'g/cm³'), $caffeine->value('density'));
        self::assertEquals(new Quantity(14), $caffeine->value('pka'), 'a pure number: no unit');
        self::assertSame('1.23 g/cm³', (string) $caffeine->property('density'));
        self::assertSame('wikidata', $caffeine->property('mass')->source);
        self::assertSame(['molecular_formula', 'smiles', 'connectivity_smiles', 'inchi', 'inchikey', 'mass', 'melting_point', 'density', 'pka'], $caffeine->keys());
    }

    public function testInTheLanguageAsked(): void
    {
        $caffeine = $this->source(null, ['throttle' => 0, 'language' => 'fr'])->entity('Q60235');

        self::assertSame('caféine', $caffeine->label);
        self::assertSame('alcaloïde qui agit comme stimulant psychotrope et comme léger diurétique', $caffeine->description);
        self::assertSame('type d\'entité chimique', $caffeine->kind);
        self::assertContains('théine', $caffeine->synonyms);
        self::assertNotContains('58-08-2', $caffeine->synonyms, 'a CAS number given as an alias is no name');
        self::assertSame('fr|mul|en', $this->calls[0]['languages']);
    }

    public function testByAnIdentifierItsStatementsCarry(): void
    {
        $source = $this->source();
        foreach ([Identifier::cid(2519), 'CHEBI:27732', 'https://pubchem.ncbi.nlm.nih.gov/compound/2519'] as $id) {
            $this->calls = [];
            self::assertSame('wikidata:Q60235', $source->entity($id)?->id->key(), 'by '.$id);
        }
        self::assertSame(['action' => 'query', 'list' => 'search', 'srsearch' => 'haswbstatement:P662=2519', 'srnamespace' => '0', 'srlimit' => '2', 'format' => 'json', 'formatversion' => '2'], $this->calls[0]);
        self::assertCount(2, $this->calls, 'the search, then the item: its class\'s label is known by then');
    }

    public function testAnIdentifierTwoItemsCarryNamesNeither(): void
    {
        self::assertNull($this->source()->entity(Identifier::cas('7732-18-5')), 'water and distilled water share CAS 7732-18-5');
        self::assertCount(1, $this->calls);
    }

    public function testByLabel(): void
    {
        self::assertSame('wikidata:Q60235', $this->source()->entity('caffeine')?->id->key());
        self::assertSame(['wbsearchentities', 'en', '10'], [$this->calls[0]['action'], $this->calls[0]['language'], $this->calls[0]['limit']]);
    }

    public function testATaxon(): void
    {
        $cougar = $this->source()->entity('Q35255');

        self::assertSame('cougar', $cougar->label);
        self::assertSame(Domain::TAXONOMY, $cougar->domain);
        self::assertSame('taxon', $cougar->kind);
        self::assertSame('Puma concolor', $cougar->value('taxon_name'));
        self::assertSame(['wikidata:Q35255', 'ncbi.taxon:9696', 'gbif:2435099', 'inaturalist:42007'], $cougar->identifiers()->keys(), 'GBIF\'s legacy Backbone number (P846): no key of GBIF\'s 2026 taxonomy (P14607) on it yet');
    }

    public function testGbifsTwoKeysOneSchemeTwoProperties(): void
    {
        $dolphin = $this->source()->entity('Q174199');
        self::assertSame('common bottlenose dolphin', $dolphin->label);
        self::assertSame('Tursiops truncatus', $dolphin->value('taxon_name'));
        self::assertSame(['wikidata:Q174199', 'ncbi.taxon:9739', 'gbif:59R5F', 'gbif:2440447', 'inaturalist:41482'], $dolphin->identifiers()->keys(), 'the key of GBIF\'s 2026 taxonomy (P14607), then the legacy number (P846)');

        $this->calls = [];
        self::assertSame('wikidata:Q174199', $this->source()->entity(Identifier::of(Scheme::GBIF, '59R5F'))?->id->key());
        self::assertSame('haswbstatement:P14607=59R5F', $this->calls[0]['srsearch'], 'a key: P14607');
        $this->calls = [];
        self::assertSame('wikidata:Q174199', $this->source()->entity('gbif:2440447')?->id->key());
        self::assertSame('haswbstatement:P846=2440447', $this->calls[0]['srsearch'], 'a number: P846');
        self::assertNull($this->source()->entity(Identifier::of(Scheme::GBIF, '4CGXP')), 'the lion carries no P14607 yet: its number finds it');
    }

    public function testAnEarthquakeByItsUsgsId(): void
    {
        $quake = $this->source()->entity(Identifier::of(Scheme::USGS_EVENT, 'us7000pn9s'));
        self::assertSame('haswbstatement:P3196=us7000pn9s', $this->calls[0]['srsearch'], 'P3196, the USGS earthquake ID');
        self::assertSame('wikidata:Q133574091', $quake?->id->key());
        self::assertSame('2025 Myanmar earthquake', $quake->label);
        self::assertSame(Domain::EARTH, $quake->domain);
        self::assertSame('earthquake', $quake->kind);
        self::assertSame(['wikidata:Q133574091', 'usgs.event:us7000pn9s'], $quake->identifiers()->keys(), 'the USGS id, which the Collector offers the USGS');
        self::assertSame(7.7, $quake->value('moment_magnitude')?->value);
        self::assertNull($quake->value('moment_magnitude')->unit, 'a magnitude has no unit');
        self::assertSame('2025-03-28', $quake->time?->format('Y-m-d'), 'P585 to the day');
        self::assertSame([22.013, 95.922], [$quake->place?->latitude, $quake->place?->longitude], 'P625');

        $this->calls = [];
        self::assertSame('wikidata:Q133574091', $this->source()->entity('usgs:US7000PN9S')?->id->key(), 'its prefixed form, lower-cased');
        $this->calls = [];
        self::assertNull($this->source()->entity('usgs:official20110311054624120_30'));
        self::assertSame('haswbstatement:P3196=official20110311054624120_30', $this->calls[0]['srsearch'], 'a catalogue\'s long id ComCat keeps, as P3196 gives some (2011 Tohoku)');
    }

    public function testUnknownIsNull(): void
    {
        self::assertNull($this->source()->entity('Q999999999999'));
        self::assertNull($this->source()->entity(Identifier::cid(1999999999)));
        self::assertNull($this->source()->entity('nonexistentcompoundxyz'));
    }

    public function testAnIdentifierItDoesNotLookUpIsRefusedBeforeAnyCall(): void
    {
        foreach (['InChI=1S/C8H10N4O2/c1-10-4-9-6-5(10)7(13)12(3)8(14)11(6)2/h4H,1-3H3', Identifier::of(Scheme::PDB, '1L5Q')] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not looked up: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testSearchByLabelPageByPage(): void
    {
        $page = $this->source()->search(new Query('caffeine', limit: 3));

        self::assertSame(['wikidata:Q60235', 'wikidata:Q16743231', 'wikidata:Q12478159'], array_map(static fn ($e) => $e->id->key(), $page->items));
        self::assertSame(['type of chemical entity', 'video game', 'musical group'], array_map(static fn ($e) => $e->kind, $page->items));
        self::assertSame([Domain::CHEMISTRY, Domain::OTHER, Domain::OTHER], array_map(static fn ($e) => $e->domain, $page->items));
        self::assertSame('3', $page->next);
        self::assertNull($page->total, 'wbsearchentities does not count');
        self::assertCount(3, $this->calls, 'the search, the items, their classes\' labels');
    }

    public function testSearchNarrowedToADomain(): void
    {
        // As recorded: after the search by label, whose classes' labels the source keeps.
        $source = $this->source();
        $source->search(new Query('caffeine', limit: 3));
        $this->calls = [];
        $page = $source->search(new Query('caffeine', Domain::CHEMISTRY, limit: 3));

        self::assertSame('caffeine haswbstatement:P231|P235|P662|P683', $this->calls[0]['srsearch']);
        self::assertSame('Q169336|Q35456|Q5139913', $this->calls[2]['ids'], 'only the classes not known yet');
        self::assertSame(['caffeine', 'caffeine citrate', 'd9-caffeine'], array_map(static fn ($e) => $e->label, $page->items));
        self::assertSame(46, $page->total);
        self::assertSame('3', $page->next);
        self::assertSame('6241', $page->items[1]->identifier(Scheme::PUBCHEM_CID));
        self::assertSame('mixture', $page->items[1]->kind);
    }

    public function testItsUserAgentNamesTheCaller(): void
    {
        $this->source(null, ['throttle' => 0, 'mailto' => 'support@glitchr.io'])->entity('Q60235');

        self::assertSame('omnilab/1.x (+https://github.com/glitchr-studio/omnilab; mailto:support@glitchr.io)', $this->agents[0]);
    }

    public function testErrorsMapTheSameWayAsEverySource(): void
    {
        try {
            $this->source(new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/error-maxlag.json'), ['response_headers' => ['Retry-After' => '5']]))->entity('Q60235');
            self::fail('maxlag is a RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertNotNull($e->retryAfter);
        }
        try {
            $this->source(new MockResponse('Please set a user-agent and respect our robot policy https://w.wiki/4wJS.', ['http_code' => 403]))->entity('Q60235');
            self::fail('a 403 is a ProviderException');
        } catch (ProviderException $e) {
            self::assertSame(403, $e->status);
            self::assertStringContainsString('user-agent', $e->getMessage());
        }
        try {
            $this->source(new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '30']]))->entity('Q60235');
            self::fail('a 429 is a RateLimitedException');
        } catch (RateLimitedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        $this->expectException(UnavailableException::class);
        $this->source(new MockResponse('', ['http_code' => 503]))->entity('Q60235');
    }

    public function testWhatItCanDo(): void
    {
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::CROSS_REFERENCES], $this->source()->capabilities());
        $this->expectException(NotSupportedException::class);
        $this->source()->series(new SeriesQuery(['temperature_2m'], new Point(48.57, 7.75)));
    }

    public function testTheThrottleLeavesAThirdOfASecondBetweenTwoCalls(): void
    {
        $source = $this->source(null, []);
        $start = microtime(true);
        $source->entity('Q60235');

        self::assertCount(2, $this->calls);
        self::assertGreaterThanOrEqual(0.29, microtime(true) - $start, 'two calls, one interval of 0.3 s: Wikimedia\'s 200 a minute');
    }
}
