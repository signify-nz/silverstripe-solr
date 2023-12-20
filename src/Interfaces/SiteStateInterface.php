<?php
/**
 * class SiteStateInterface|Firesphere\SolrSearch\Interfaces\SiteStateInterface Interface for managing the
 * state of a site
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 */

namespace Firesphere\SolrSearch\Interfaces;

use Firesphere\SolrSearch\Queries\SolrQuery;

/**
 * Interface SiteStateInterface defines the methods every State altering must implement.
 *
 * These methods must exist in the SiteStates that are available
 *
 * @package Firesphere\Solr\Search
 */
interface SiteStateInterface
{
    /**
     * Is this state applicable to this extension
     * E.g. in case of Fluent, the state "SubsiteID1" does not make sense
     *
     * @param string|int $state
     * @return bool
     */
    public function stateIsApplicable($state): bool;

    /**
     * Reset the SiteState to it's default state
     *
     * @param string|int|null $state
     * @return mixed
     */
    public function setDefaultState($state = null);

    /**
     * Return the current state of the site
     *
     * @return string|int|null
     */
    public function currentState();

    /**
     * Activate a given state. This should only be done if the state is applicable
     *
     * @param string|int $state
     * @return mixed
     */
    public function activateState($state);

    /**
     * Method to alter the query. Can be no-op.
     *
     * @param SolrQuery $query
     * @return mixed
     */
    public function updateQuery(&$query);
}
