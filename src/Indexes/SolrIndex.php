<?php
/**
 * class BaseIndex|Firesphere\SolrSearch\Indexes\BaseIndex is the base for indexing items
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 */

namespace Firesphere\SolrSearch\Indexes;

use Exception;
use Firesphere\SearchBackend\Config\SearchConfig;
use Firesphere\SearchBackend\Indexes\CoreIndex;
use Firesphere\SearchBackend\Queries\CoreQuery;
use Firesphere\SearchBackend\Traits\QueryTraits\QueryFilterTrait;
use Firesphere\SolrSearch\Factories\QueryComponentFactory;
use Firesphere\SolrSearch\Factories\SchemaFactory;
use Firesphere\SolrSearch\Helpers\SolrLogger;
use Firesphere\SolrSearch\Helpers\Synonyms;
use Firesphere\SolrSearch\Interfaces\ConfigStore;
use Firesphere\SolrSearch\Queries\BaseQuery;
use Firesphere\SolrSearch\Results\SearchResult;
use Firesphere\SolrSearch\Services\SolrCoreService;
use Firesphere\SolrSearch\States\SiteState;
use Firesphere\SolrSearch\Traits\BaseIndexTrait;
use Firesphere\SolrSearch\Traits\GetterSetterTrait;
use ReflectionException;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\ValidationException;
use SilverStripe\View\ArrayData;
use Solarium\Exception\HttpException;
use Solarium\QueryType\Select\Query\Query;
use Solarium\QueryType\Select\Result\Result;

/**
 * Base for creating a new Solr core.
 *
 * Base index settings and methods. Should be extended with at least a name for the index.
 * This is an abstract class that can not be instantiated on it's own
 *
 * @package Firesphere\Solr\Search
 */
abstract class SolrIndex extends CoreIndex
{
    use Extensible;
    use Configurable;
    use Injectable;
    use GetterSetterTrait;
    use BaseIndexTrait;
    use QueryFilterTrait;

    /**
     * {@link SchemaFactory}
     *
     * @var SchemaFactory Schema factory for generating the schema
     */
    protected $schemaFactory;
    /**
     * {@link QueryComponentFactory}
     *
     * @var QueryComponentFactory Generator for all components
     */
    protected $queryFactory;
    /**
     * @var array The query terms as an array
     */
    protected $queryTerms = [];
    /**
     * @var Query Query that will hit the client
     */
    protected $clientQuery;
    /**
     * @var bool Signify if a retry should occur if nothing was found and there are suggestions to follow
     */
    private $retry = false;

    /**
     * BaseIndex constructor.
     */
    public function __construct()
    {
        $this->client = Injector::inst()->get(SolrCoreService::class)->getClient();
        // Add the core name to the endpoint config
        $this->client->getEndpoint('localhost')->setOptions(['core' => $this->getIndexName()], false);

        // Set up the schema service, only used in the generation of the schema
        /** @var SchemaFactory $schemaFactory */
        $schemaFactory = Injector::inst()->get(SchemaFactory::class, false);
        $schemaFactory->setIndex($this);
        $schemaFactory->setStore(Director::isDev());
        $this->schemaFactory = $schemaFactory;
        $this->queryFactory = Injector::inst()->get(QueryComponentFactory::class, false);

        $this->extend('onBeforeInit');
        $this->init();
        $this->extend('onAfterInit');
    }

    /**
     * Build a full config for all given endpoints
     * This is to add the current index to e.g. an index or select
     *
     * @param array $endpoints
     * @return array
     */
    public function getConfig($endpoints): array
    {
        foreach ($endpoints as $host => $endpoint) {
            $endpoints[$host]['core'] = $this->getIndexName();
        }

        return $endpoints;
    }

    /**
     * Default returns a SearchResult. It can return an ArrayData if FTS Compat is enabled
     *
     * @param BaseQuery $query
     * @return SearchResult|ArrayData|mixed
     * @throws HTTPException
     * @throws ValidationException
     * @throws ReflectionException
     * @throws Exception
     */
    public function doSearch($query)
    {
        SiteState::alterQuery($query);
        // Build the actual query parameters
        $this->clientQuery = $this->buildSolrQuery($query);
        // Set the sorting
        $this->clientQuery->addSorts($query->getSort());

        $this->extend('onBeforeSearch', $query, $this->clientQuery);

        try {
            $result = $this->client->select($this->clientQuery);
        } catch (Exception $error) {
            // @codeCoverageIgnoreStart
            $logger = new SolrLogger();
            $logger->saveSolrLog('Query');
            throw $error;
            // @codeCoverageIgnoreEnd
        }

        // Handle the after search first. This gets a raw search result
        $this->extend('onAfterSearch', $result);
        $searchResult = new SearchResult($result, $query, $this);
        if ($this->doRetry($query, $result, $searchResult)) {
            // We need to override the spellchecking with the previous spellcheck
            // @todo refactor this to a cleaner way
            $collation = $result->getSpellcheck();
            $retryResults = $this->spellcheckRetry($query, $searchResult);
            $this->retry = false;
            return $retryResults->setCollatedSpellcheck($collation);
        }

        // And then handle the search results, which is a useable object for SilverStripe
        $this->extend('updateSearchResults', $searchResult);

        return $searchResult;
    }

    /**
     * From the given BaseQuery, generate a Solarium ClientQuery object
     * @todo refactor to implement {@link QueryBuilderInterface}
     * @param BaseQuery $query
     * @return Query
     */
    public function buildSolrQuery(BaseQuery $query): Query
    {
        $clientQuery = $this->client->createSelect();
        $factory = $this->buildFactory($query, $clientQuery);

        $clientQuery = $factory->buildQuery();
        $this->queryTerms = $factory->getQueryArray();

        $queryData = implode(' ', $this->queryTerms);
        $clientQuery->setQuery($queryData);

        return $clientQuery;
    }

    /**
     * Build a factory to use in the SolrQuery building. {@link static::buildSolrQuery()}
     *
     * @param BaseQuery $query
     * @param Query $clientQuery
     * @return QueryComponentFactory|mixed
     */
    protected function buildFactory(BaseQuery $query, Query $clientQuery)
    {
        $factory = $this->queryFactory;

        $helper = $clientQuery->getHelper();

        $factory->setQuery($query);
        $factory->setClientQuery($clientQuery);
        $factory->setHelper($helper);
        $factory->setIndex($this);

        return $factory;
    }

    /**
     * Check if the query should be retried with spellchecking
     * Conditions are:
     * It is not already a retry with spellchecking
     * Spellchecking is enabled
     * If spellchecking is enabled and nothing is found OR it should follow spellchecking none the less
     * There is a spellcheck output
     *
     * @param BaseQuery $query
     * @param Result $result
     * @param SearchResult $searchResult
     * @return bool
     */
    protected function doRetry(BaseQuery $query, Result $result, SearchResult $searchResult): bool
    {
        return !$this->retry &&
            $query->hasSpellcheck() &&
            ($query->shouldFollowSpellcheck() || $result->getNumFound() === 0) &&
            $searchResult->getCollatedSpellcheck();
    }

    /**
     * Retry the query with the first collated spellcheck found.
     *
     * @param BaseQuery $query
     * @param SearchResult $searchResult
     * @return SearchResult|mixed|ArrayData
     * @throws HTTPException
     * @throws ValidationException
     * @throws ReflectionException
     */
    protected function spellcheckRetry(BaseQuery $query, SearchResult $searchResult)
    {
        $terms = $query->getTerms();
        $spellChecked = $searchResult->getCollatedSpellcheck();
        // Remove the fuzzyness from the collated check
        $term = preg_replace('/~\d+/', '', $spellChecked);
        $terms[0]['text'] = $term;
        $query->setTerms($terms);
        $this->retry = true;

        return $this->doSearch($query);
    }

    /**
     * Get all fields that are required for indexing in a unique way
     *
     * @return array
     */
    public function getFieldsForIndexing(): array
    {
        $facets = [];
        foreach ($this->getFacetFields() as $field) {
            $facets[] = $field['Field'];
        }
        // Return values to make the key reset
        // Only return unique values
        // And make it all a single array
        $fields = array_values(
            array_unique(
                array_merge(
                    $this->getFulltextFields(),
                    $this->getSortFields(),
                    $facets,
                    $this->getFilterFields()
                )
            )
        );

        $this->extend('updateFieldsForIndexing', $fields);

        return $fields;
    }

    /**
     * Upload config for this index to the given store
     *
     * @param ConfigStore $store
     */
    public function uploadConfig(ConfigStore $store): void
    {
        // @todo use types/schema/elevate rendering
        // Upload the config files for this index
        // Create a default schema which we can manage later
        $schema = (string)$this->schemaFactory->generateSchema();
        $store->uploadString(
            $this->getIndexName(),
            'schema.xml',
            $schema
        );

        $this->getSynonyms($store);

        // Upload additional files
        foreach (glob($this->schemaFactory->getExtrasPath() . '/*') as $file) {
            if (is_file($file)) {
                $store->uploadFile($this->getIndexName(), $file);
            }
        }
    }

    /**
     * Add synonyms. Public to be extendable
     *
     * @param ConfigStore $store Store to use to write synonyms
     * @param bool $defaults Include UK to US synonyms
     * @return string
     */
    public function getSynonyms($store = null, $defaults = true)
    {
        $synonyms = Synonyms::getSynonymsAsString($defaults);
        /** @var DataList|SearchSynonym[] $syn */
        $syn = SearchSynonym::get();
        foreach ($syn as $synonym) {
            $synonyms .= $synonym->getCombinedSynonym();
        }

        // Upload synonyms
        if ($store) {
            $store->uploadString(
                $this->getIndexName(),
                'synonyms.txt',
                $synonyms
            );
        }

        return $synonyms;
    }

    /**
     * Get the final, generated terms
     *
     * @return array
     */
    public function getQueryTerms(): array
    {
        return $this->queryTerms;
    }

    /**
     * Get the QueryComponentFactory. {@link QueryComponentFactory}
     *
     * @return QueryComponentFactory
     */
    public function getQueryFactory(): QueryComponentFactory
    {
        return $this->queryFactory;
    }

    /**
     * Retrieve the Solarium client Query object for this index operation
     *
     * @return Query
     */
    public function getClientQuery(): Query
    {
        return $this->clientQuery;
    }

    /**
     * @return bool
     */
    public function isRetry(): bool
    {
        return $this->retry;
    }
}
