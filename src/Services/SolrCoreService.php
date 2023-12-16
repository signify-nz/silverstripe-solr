<?php
/**
 * class SolrCoreService|Firesphere\SolrSearch\Services\SolrCoreService Base service for communicating with the core
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 */

namespace Firesphere\SolrSearch\Services;

use Exception;
use Firesphere\SearchBackend\Config\SearchConfig;
use Firesphere\SearchBackend\Services\BaseService;
use Firesphere\SolrSearch\Factories\DocumentFactory;
use Firesphere\SolrSearch\Helpers\FieldResolver;
use Firesphere\SolrSearch\Indexes\SolrIndex;
use Firesphere\SolrSearch\Traits\CoreAdminTrait;
use Firesphere\SolrSearch\Traits\CoreServiceTrait;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use LogicException;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\SS_List;
use Solarium\Client;
use Solarium\Core\Client\Adapter\Psr18Adapter;
use Solarium\Core\Client\Client as CoreClient;
use Solarium\QueryType\Update\Query\Query;
use Solarium\QueryType\Update\Result;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Class SolrCoreService provides the base connection to Solr.
 *
 * Default service to connect to Solr and handle all base requirements to support Solr.
 * Default constants are available to support any set up.
 *
 * @package Firesphere\Solr\Search
 */
class SolrCoreService extends BaseService
{
    use Injectable;
    use Configurable;
    use CoreServiceTrait;
    use CoreAdminTrait;

    /**
     * Unique ID in Solr
     */
    const ID_FIELD = 'id';
    /**
     * SilverStripe ID of the object
     */
    const CLASS_ID_FIELD = 'ObjectID';
    /**
     * Name of the field that can be used for queries
     */
    const CLASSNAME = 'ClassName';
    /**
     * Solr update types
     */
    const DELETE_TYPE_ALL = 'deleteall';
    /**
     * string
     */
    const DELETE_TYPE = 'delete';
    /**
     * string
     */
    const UPDATE_TYPE = 'update';
    /**
     * string
     */
    const CREATE_TYPE = 'create';

    /**
     * @var array Base indexes that exist
     */
    protected $baseIndexes = [];
    /**
     * @var array Available config versions
     */
    protected static $solr_versions = [
        "9.99999999.0",
        "7.99999999.0",
        "5.99999999.0",
        "4.99999999.0",
    ];

    /**
     * SolrCoreService constructor.
     *
     * @throws ReflectionException
     */
    public function __construct()
    {
        $endpointConfig = SearchConfig::getConfig(SearchConfig::SOLR_CONFIG);
        $this->setupClient($endpointConfig);
        parent::__construct(SolrIndex::class);
    }

    /**
     * @param array $endpointConfig
     * @return void
     */
    public function setupClient(array $endpointConfig): void
    {
        $httpClient = Psr18ClientDiscovery::find();
        $requestFactory = Psr17FactoryDiscovery::findRequestFactory();
        $streamFactory = Psr17FactoryDiscovery::findStreamFactory();
        $eventDispatcher = new EventDispatcher();
        $adapter = new Psr18Adapter($httpClient, $requestFactory, $streamFactory);
        $this->client = new Client($adapter, $eventDispatcher, ['endpoint' => ['localhost' => $endpointConfig]]);
        $this->admin = $this->client->createCoreAdmin();
    }

    /**
     * Update items in the list to Solr
     *
     * @param SS_List|DataObject $items
     * @param string $type
     * @param null|string $index
     * @return bool|Result
     * @throws ReflectionException
     * @throws Exception
     * @throws NotFoundExceptionInterface
     */
    public function updateItems($items, $type, $index = null)
    {
        $indexes = $this->getValidIndexes($index);

        $result = false;
        $items = ($items instanceof DataObject) ? ArrayList::create([$items]) : $items;
        $items = ($items instanceof SS_List) ? $items : ArrayList::create($items);

        $hierarchy = FieldResolver::getHierarchy($items->first()->ClassName);

        foreach ($indexes as $indexString) {
            /** @var SolrIndex $index */
            $index = Injector::inst()->get($indexString);
            $classes = $index->getClasses();
            $inArray = array_intersect($classes, $hierarchy);
            // No point in sending a delete|update|create for something that's not in the index
            if (!count($inArray)) {
                continue;
            }

            $result = $this->doManipulate($items, $type, $index);
        }

        return $result;
    }

    /**
     * Execute the manipulation of solr documents
     *
     * @param SS_List $items
     * @param $type
     * @param SolrIndex $index
     * @return Result
     * @throws Exception
     */
    public function doManipulate($items, $type, SolrIndex $index): Result
    {
        $client = $index->getClient();

        $update = $this->getUpdate($items, $type, $index, $client);

        // commit immediately when in dev mode

        return $client->update($update);
    }

    /**
     * get the update object ready
     *
     * @param SS_List $items
     * @param string $type
     * @param SolrIndex $index
     * @param CoreClient $client
     * @return mixed
     * @throws Exception
     */
    protected function getUpdate($items, $type, SolrIndex $index, CoreClient $client)
    {
        // get an update query instance
        $update = $client->createUpdate();

        switch ($type) {
            case static::DELETE_TYPE:
                // By pushing to a single array, we have less memory usage and no duplicates
                // This is faster, and more efficient, because we only do one DB query
                $delete = $items->map('ID', 'ClassName')->toArray();
                array_walk($delete, static function (&$item, $key) {
                    $item = sprintf('%s-%s', $item, $key);
                });
                $update->addDeleteByIds(array_values($delete));
                // Remove the deletion array from memory
                break;
            case static::DELETE_TYPE_ALL:
                $update->addDeleteQuery('*:*');
                break;
            case static::UPDATE_TYPE:
            case static::CREATE_TYPE:
                $this->updateIndex($index, $items, $update);
                break;
        }
        $update->addCommit();

        return $update;
    }

    /**
     * Create the documents and add to the update
     *
     * @param SolrIndex $index
     * @param SS_List $items
     * @param Query $update
     * @throws Exception
     */
    public function updateIndex($index, $items, $update): void
    {
        $fields = $index->getFieldsForIndexing();
        $factory = $this->getFactory($items);
        $docs = $factory->buildItems($fields, $index, $update);
        if (count($docs)) {
            $update->addDocuments($docs);
        }
    }

    /**
     * Get the document factory prepared
     * @param SS_List $items
     * @return DocumentFactory
     * @throws NotFoundExceptionInterface
     * @todo move to Base Service
     */
    protected function getFactory($items): DocumentFactory
    {
        $factory = Injector::inst()->get(DocumentFactory::class);
        $factory->setItems($items);
        $factory->setClass($items->first()->ClassName);
        $factory->setDebug($this->isDebug());

        return $factory;
    }

    /**
     * Check the Solr version to use
     * In version compare, we have the following results:
     *       1 means "result version is higher"
     *       0 means "result version is equal"
     *      -1 means "result version is lower"
     * We want to use the version "higher or equal to", because the
     * configs are for version X-and-up.
     * We loop through the versions available from high to low
     * therefore, if the version is lower, we want to check the next config version
     *
     * If no valid version is found, throw an error
     *
     * @param HandlerStack|null $handler Used for testing the solr version
     * @throws LogicException
     * @return int
     */
    public function getSolrVersion($handler = null): int
    {
        $config = self::config()->get('config');
        $firstEndpoint = array_shift($config['endpoint']);
        $clientConfig = [
            'base_uri' => 'http://' . $firstEndpoint['host'] . ':' . $firstEndpoint['port'],
        ];

        if ($handler) {
            $clientConfig['handler'] = $handler;
        }

        $clientOptions = $this->getSolrAuthentication($firstEndpoint);

        $client = new GuzzleClient($clientConfig);

        $result = $client->get('solr/admin/info/system?wt=json', $clientOptions);
        $result = json_decode($result->getBody(), 1);

        foreach (static::$solr_versions as $version) {
            $compare = version_compare($version, $result['lucene']['solr-spec-version']);
            if ($compare !== -1) {
                list($v) = explode('.', $version);
                return (int)$v;
            }
        }

        throw new LogicException('No valid version of Solr found!', 255);
    }

    /**
     * This will add the authentication headers to the request.
     * It's intended to become a helper in the end.
     *
     * @param array $firstEndpoint
     * @return array|array[]
     */
    private function getSolrAuthentication($firstEndpoint): array
    {
        $clientOptions = [];

        if (isset($firstEndpoint['username']) && isset($firstEndpoint['password'])) {
            $clientOptions = [
                'auth' => [
                    $firstEndpoint['username'],
                    $firstEndpoint['password']
                ]
            ];
        }

        return $clientOptions;
    }
}
