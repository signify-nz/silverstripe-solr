<?php


namespace tests\mocks;

use Firesphere\SolrSearch\Indexes\SolrIndex;
use SilverStripe\Dev\TestOnly;

class TestIndexFour extends SolrIndex implements TestOnly
{

    /**
     * @inheritDoc
     */
    public function getIndexName()
    {
        return 'index4';
    }
}
