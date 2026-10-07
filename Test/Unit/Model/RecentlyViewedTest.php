<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Model;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Panth\QuickView\Model\RecentlyViewed;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed as RecentlyViewedResource;
use PHPUnit\Framework\TestCase;

class RecentlyViewedTest extends TestCase
{
    /**
     * @return RecentlyViewed
     */
    private function model(): RecentlyViewed
    {
        $resource = $this->createStub(RecentlyViewedResource::class);
        $resource->method('getIdFieldName')->willReturn('view_id');
        return new RecentlyViewed($this->createStub(Context::class), $this->createStub(Registry::class), $resource);
    }

    public function testIdentitiesUseCacheTagAndId(): void
    {
        $model = $this->model();
        $model->setId(17);

        $this->assertSame(['panth_recently_viewed_17'], $model->getIdentities());
    }

    public function testIdentitiesForUnsavedModelEndWithSeparator(): void
    {
        $this->assertSame(['panth_recently_viewed_'], $this->model()->getIdentities());
    }

    public function testCacheTagConstantMatchesEventPrefix(): void
    {
        $this->assertSame('panth_recently_viewed', RecentlyViewed::CACHE_TAG);
        $this->assertSame('panth_recently_viewed', $this->model()->getEventPrefix());
    }
}
