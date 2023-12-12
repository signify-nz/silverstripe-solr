<?php


namespace Firesphere\SolrSearch\Tests;

use Firesphere\SolrSearch\Indexes\SolrIndex;
use SilverStripe\Dev\TestOnly;

class TestIndexTwo extends SolrIndex implements TestOnly
{
    public function getIndexName(): string
    {
        return 'TestIndexTwo';
    }
}
