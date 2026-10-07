<?php
declare(strict_types=1);

namespace Panth\QuickView\Model;

use Magento\Framework\Model\AbstractModel;

class RecentlyViewed extends AbstractModel
{
    const CACHE_TAG = 'panth_recently_viewed';

    protected $_cacheTag = 'panth_recently_viewed';

    protected $_eventPrefix = 'panth_recently_viewed';

    protected function _construct()
    {
        $this->_init(\Panth\QuickView\Model\ResourceModel\RecentlyViewed::class);
    }

    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }
}
