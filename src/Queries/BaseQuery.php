<?php
/**
 * class BaseQuery|Firesphere\SolrSearch\Queries\BaseQuery Base of a Solr Query
 *
 * @package Firesphere\Solr\Search
 * @author Simon `Firesphere` Erkelens; Marco `Sheepy` Hermo
 * @copyright Copyright (c) 2018 - now() Firesphere & Sheepy
 */

namespace Firesphere\SolrSearch\Queries;

use Firesphere\SearchBackend\Interfaces\QueryInterface;
use Firesphere\SearchBackend\Queries\CoreQuery;
use Firesphere\SolrSearch\Traits\BaseQueryTrait;
use Firesphere\SolrSearch\Traits\GetterSetterTrait;
use SilverStripe\Core\Injector\Injectable;

/**
 * Class BaseQuery is the base of every query executed.
 *
 * Build a query to execute agains Solr. Uses as simle as possible an interface.
 *
 * @package Firesphere\Solr\Search
 */
class BaseQuery extends CoreQuery implements QueryInterface
{
//    use GetterSetterTrait;
//    use BaseQueryTrait;
    use Injectable;

    /**
     * @var array Always get the ID. If you don't, you need to implement your own solution
     */
    protected $fields = [];
    /**
     * @var bool Enable spellchecking?
     */
    protected $spellcheck = true;
    /**
     * @var bool Follow spellchecking if there are no results
     */
    protected $followSpellcheck = false;
    /**
     * @var int Minimum results a facet query has to have
     */
    protected $facetsMinCount = 1;
    /**
     * @var array Highlighted items
     */
    protected $highlight = [];

    /**
     * Get the fields to return
     *
     * @return array
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * Set fields to be returned
     *
     * @param array $fields
     * @return $this
     */
    public function setFields($fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Get the facet count minimum to use
     *
     * @return int
     */
    public function getFacetsMinCount(): int
    {
        return $this->facetsMinCount;
    }

    /**
     * Set the minimum count of facets to be returned
     *
     * @param mixed $facetsMinCount
     * @return $this
     */
    public function setFacetsMinCount($facetsMinCount): self
    {
        $this->facetsMinCount = $facetsMinCount;

        return $this;
    }

    /**
     * Get the excludes
     *
     * @return array
     */
    public function getExclude(): array
    {
        return $this->exclude;
    }

    /**
     * Set the query excludes
     *
     * @param array $exclude
     * @return $this
     */
    public function setExclude($exclude): self
    {
        $this->exclude = $exclude;

        return $this;
    }

    /**
     * Add a highlight parameter
     *
     * @param $field
     * @return $this
     */
    public function addHighlight($field): self
    {
        $this->highlight[] = $field;

        return $this;
    }

    /**
     * Get the highlight parameters
     *
     * @return array
     */
    public function getHighlight(): array
    {
        return $this->highlight;
    }

    /**
     * Set the highlight parameters
     *
     * @param array $highlight
     * @return $this
     */
    public function setHighlight($highlight): self
    {
        $this->highlight = $highlight;

        return $this;
    }

    /**
     * Do we have spellchecking
     *
     * @return bool
     */
    public function hasSpellcheck(): bool
    {
        return $this->spellcheck;
    }

    /**
     * Set the spellchecking on this query
     *
     * @param bool $spellcheck
     * @return self
     */
    public function setSpellcheck(bool $spellcheck): self
    {
        $this->spellcheck = $spellcheck;

        return $this;
    }

    /**
     * Set if we should follow spellchecking
     *
     * @param bool $followSpellcheck
     * @return BaseQuery
     */
    public function setFollowSpellcheck(bool $followSpellcheck): BaseQuery
    {
        $this->followSpellcheck = $followSpellcheck;

        return $this;
    }

    /**
     * Should spellcheck suggestions be followed
     *
     * @return bool
     */
    public function shouldFollowSpellcheck(): bool
    {
        return $this->followSpellcheck;
    }
}
