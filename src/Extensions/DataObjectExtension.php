<?php
/**
 * class DataObjectExtension|Firesphere\SolrSearch\Extensions\DataObjectExtension Adds checking if changes should be
 * pushed to Solr
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 */

namespace Firesphere\SolrSearch\Extensions;

use Exception;
use Firesphere\SearchBackend\Extensions\DataObjectSearchExtension;
use Firesphere\SearchBackend\Models\DirtyClass;
use Firesphere\SolrSearch\Helpers\SolrLogger;
use Firesphere\SolrSearch\Services\SolrCoreService;
use Firesphere\SolrSearch\Tests\DataObjectExtensionTest;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionException;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataExtension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ValidationException;
use SilverStripe\Security\InheritedPermissionsExtension;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;
use Solarium\Exception\HttpException;

/**
 * Class \Firesphere\SolrSearch\Compat\DataObjectExtension
 *
 * Extend every DataObject with the option to update the index.
 *
 * @property DataObject|DataObjectExtension $owner
 */
class DataObjectExtension extends DataExtension
{
    /**
     * @var array Cached permission list
     */
    public static $cachedClasses;
    /**
     * @var SiteConfig Current siteconfig
     */
    protected static $siteConfig;

    /**
     * Push the item to solr if it is not versioned
     * Update the index after write.
     *
     * @throws ValidationException
     * @throws HTTPException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    public function onAfterWrite()
    {
        /** @var DataObject $owner */
        $owner = $this->owner;

        if ($this->shouldPush() && !$owner->hasExtension(Versioned::class)) {
            $this->pushToSolr($owner);
        }
    }

    /**
     * Should this write be pushed to Solr
     * @return bool
     */
    protected function shouldPush()
    {
        if (!Controller::has_curr()) {
            return false;
        }
        $request = Controller::curr()->getRequest();

        return ($request && !($request->getURL() &&
            strpos('dev/build', $request->getURL()) !== false));
    }

    /**
     * Try to push the newly updated item to Solr
     *
     * @param DataObject $owner
     * @throws ValidationException
     * @throws HTTPException
     * @throws ReflectionException
     * @throws InvalidArgumentException
     * @throws \JsonException
     */
    protected function pushToSolr(DataObject $owner)
    {
        $service = new SolrCoreService();
        if (!$service->isValidClass($owner->ClassName)) {
            return;
        }

        /** @var DataObject|DataObjectSearchExtension $owner */
        $record = $owner->getDirtyClass(SolrCoreService::UPDATE_TYPE);

        $ids = json_decode($record->IDs, 1, 512, JSON_THROW_ON_ERROR) ?: [];
        $mode = Versioned::get_reading_mode();
        try {
            Versioned::set_reading_mode(Versioned::LIVE);
            $service->setDebug(false);
            $type = SolrCoreService::UPDATE_TYPE;
            // If the object should not show in search, remove it
            if ($owner->ShowInSearch !== null && (bool)$owner->ShowInSearch === false) {
                $type = SolrCoreService::DELETE_TYPE;
            }
            $service->updateItems(ArrayList::create([$owner]), $type);
            // If we don't get an exception, mark the item as clean
            // Added bonus, array_flip removes duplicates
            $this->clearIDs($owner, $ids, $record);
            // @codeCoverageIgnoreStart
        } catch (Exception $error) {
            Versioned::set_reading_mode($mode);
            $this->registerException($ids, $record, $error);
        }
        // @codeCoverageIgnoreEnd
        Versioned::set_reading_mode($mode);
    }

    /**
     * Remove the owner ID from the dirty ID set
     *
     * @param DataObject $owner
     * @param array $ids
     * @param DirtyClass $record
     * @throws ValidationException
     * @throws \JsonException
     */
    protected function clearIDs(DataObject $owner, array $ids, DirtyClass $record): void
    {
        $values = array_flip($ids);
        unset($values[$owner->ID]);

        $record->IDs = json_encode(array_keys($values), JSON_THROW_ON_ERROR);
        $record->write();
    }

    /**
     * Register the exception of the attempted index for later clean-up use
     *
     * @codeCoverageIgnore This is actually tested through reflection. See {@link DataObjectExtensionTest}
     * @param array $ids
     * @param DirtyClass $record
     * @param Exception $error
     * @throws ValidationException
     * @throws HTTPException
     * @throws NotFoundExceptionInterface
     */
    protected function registerException(array $ids, DirtyClass $record, Exception $error): void
    {
        /** @var DataObject $owner */
        $owner = $this->owner;
        $ids[] = $owner->ID;
        // If we don't get an exception, mark the item as clean
        $record->IDs = json_encode($ids);
        $record->write();
        $logger = Injector::inst()->get(LoggerInterface::class);
        $logger->warn(
            sprintf(
                'Unable to alter %s with ID %s',
                $owner->ClassName,
                $owner->ID
            )
        );
        $solrLogger = new SolrLogger();
        $solrLogger->saveSolrLog('Index');

        $logger->error($error->getMessage());
    }

    /**
     * Reindex this owner object in Solr
     * This is a simple stub for the push method, for semantic reasons
     * It should never be called on Objects that are not a valid class for any Index
     * It does not check if the class is valid to be pushed to Solr
     *
     * @throws HTTPException
     * @throws ReflectionException
     * @throws ValidationException
     * @throws InvalidArgumentException|\JsonException
     */
    public function doReindex()
    {
        $this->pushToSolr($this->owner);
    }

    /**
     * Push the item to Solr after publishing
     *
     * @throws ValidationException
     * @throws HTTPException
     * @throws ReflectionException
     * @throws InvalidArgumentException|\JsonException
     */
    public function onAfterPublish(): void
    {
        if ($this->shouldPush()) {
            /** @var DataObject $owner */
            $owner = $this->owner;
            $this->pushToSolr($owner);
        }
    }

    /**
     * Attempt to remove the item from Solr
     *
     * @throws ValidationException
     * @throws HTTPException
     * @throws NotFoundExceptionInterface|\JsonException
     */
    public function onAfterDelete(): void
    {
        /** @var DataObject|DataObjectSearchExtension $owner */
        $owner = $this->owner;
        /** @var DirtyClass $record */
        $record = $owner->getDirtyClass(SolrCoreService::DELETE_TYPE);
        $record->IDs = $record->IDs ?? '[]'; // If the record is new, or the IDs list is null, default
        $ids = json_decode($record->IDs, 1, 512, JSON_THROW_ON_ERROR);

        try {
            (new SolrCoreService())
                ->updateItems(ArrayList::create([$owner]), SolrCoreService::DELETE_TYPE);
            // If successful, remove it from the array
            // Added bonus, array_flip removes duplicates
            $this->clearIDs($owner, $ids, $record);
            // @codeCoverageIgnoreStart
        } catch (Exception $error) {
            $this->registerException($ids, $record, $error);
        }
        // @codeCoverageIgnoreEnd
    }
}
