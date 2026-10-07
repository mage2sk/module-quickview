<?php
declare(strict_types=1);

namespace Panth\QuickView\Model\ResourceModel\RecentlyViewed;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'view_id';

    protected function _construct()
    {
        $this->_init(
            \Panth\QuickView\Model\RecentlyViewed::class,
            \Panth\QuickView\Model\ResourceModel\RecentlyViewed::class
        );
    }
}
