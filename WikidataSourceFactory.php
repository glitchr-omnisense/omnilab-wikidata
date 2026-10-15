<?php

namespace Omnilab\Wikidata;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Wikidata: any item, its labels in a language, its identifiers in the
 * science bases and a few of its properties - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: Wikimedia refuses a request that does not say who calls and how to reach them
 *     language: 'en'                   # labels, descriptions, aliases: "fr", "de", "ja"... with Wikidata's fallbacks
 *     synonyms: 20                     # how many aliases to keep (0: none)
 *     throttle: 0.3                    # seconds between two calls: 200 a minute for a client identified by its User-Agent
 *     base_uri: 'https://www.wikidata.org/w/api.php'
 */
final class WikidataSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'wikidata',
            'omnilab.required_options' => [],
            'language' => 'en',
            'synonyms' => 20,
            'throttle' => 0.3,
            'base_uri' => WikidataSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new WikidataSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('language'),
            (int) $c->get('synonyms'),
            (string) $c->get('base_uri', WikidataSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
