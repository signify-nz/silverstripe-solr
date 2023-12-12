<?php


namespace Firesphere\SolrSearch\Tests;

use Firesphere\SolrSearch\Indexes\SolrIndex;
use SilverStripe\Dev\TestOnly;

class TestIndexThree extends SolrIndex implements TestOnly
{
    public function init()
    {
        return;
    }

    public function getIndexName()
    {
        return 'TestIndexThree';
    }
}
