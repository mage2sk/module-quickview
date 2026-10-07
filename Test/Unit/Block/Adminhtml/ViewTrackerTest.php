<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Block\Adminhtml;

use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\UrlInterface;
use Panth\QuickView\Block\Adminhtml\ViewTracker;
use Panth\QuickView\Model\RecentlyViewed;
use Panth\QuickView\Model\RecentlyViewedFactory;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed\Collection;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed\CollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ViewTrackerTest extends TestCase
{
    /**
     * @var ObjectManagerInterface|null
     */
    private ?ObjectManagerInterface $previousObjectManager = null;

    /**
     * Queue of collection specs; each create() consumes one (or a default empty one).
     *
     * @var array
     */
    private array $collectionQueue = [];

    /**
     * Filters recorded per created collection, in creation order.
     *
     * @var array
     */
    private array $filters = [];

    /**
     * @var array
     */
    private array $selectCalls = [];

    /**
     * @var TimezoneInterface|null
     */
    private ?TimezoneInterface $timezone = null;

    /**
     * @var array
     */
    private array $products = [];

    /**
     * @var array
     */
    private array $customers = [];

    /**
     * @var ProductFactory|null
     */
    private ?ProductFactory $productFactory = null;

    /**
     * @var RecentlyViewedFactory|null
     */
    private ?RecentlyViewedFactory $viewedFactory = null;

    /**
     * @var ImageHelper|null
     */
    private ?ImageHelper $imageHelper = null;

    protected function setUp(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $this->previousObjectManager = $property->getValue();
        $objectManager = $this->createStub(ObjectManagerInterface::class);
        $objectManager->method('get')->willReturnCallback(fn($class) => $this->createStub($class));
        ObjectManager::setInstance($objectManager);
    }

    protected function tearDown(): void
    {
        $property = new \ReflectionProperty(ObjectManager::class, '_instance');
        $property->setValue(null, $this->previousObjectManager);
    }

    /**
     * Build a collection stub from a spec.
     *
     * @param array $spec size, items, columnValues, rows
     * @return Collection
     */
    private function collection(array $spec): Collection
    {
        $index = count($this->filters);
        $this->filters[$index] = [];

        $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
        $statement->method('fetchAll')->willReturn($spec['rows'] ?? []);

        $select = $this->createStub(Select::class);
        foreach (['columns', 'group', 'order', 'limit'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($select, $method, $index) {
                $this->selectCalls[$index][] = [$method, [$args[0] ?? null]];
                return $select;
            });
        }
        $select->method('query')->willReturn($statement);

        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            function ($field, $condition = null) use ($collection, $index) {
                $this->filters[$index][] = [$field, $condition];
                return $collection;
            }
        );
        $collection->method('setOrder')->willReturnCallback(function ($field, $dir = 'DESC') use ($collection, $index) {
            $this->selectCalls[$index][] = ['setOrder', [$field, $dir]];
            return $collection;
        });
        $collection->method('setPageSize')->willReturnCallback(function ($size) use ($collection, $index) {
            $this->selectCalls[$index][] = ['setPageSize', [$size]];
            return $collection;
        });
        $collection->method('getSize')->willReturn($spec['size'] ?? 0);
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getColumnValues')->willReturn($spec['columnValues'] ?? []);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($spec['items'] ?? []));
        return $collection;
    }

    /**
     * Build a product double; null id means "not found".
     *
     * @param int|null $id
     * @param string|null $name
     * @param bool $throws
     * @return Product
     */
    private function productDouble(?int $id, ?string $name = 'Lamp', bool $throws = false): Product
    {
        $product = $this->createPartialMock(Product::class, [
            'load', 'getId', 'getName', 'getSku', 'getFinalPrice', 'getProductUrl', 'getTypeId',
            'getStatus', 'getVisibility',
        ]);
        if ($throws) {
            $product->method('load')->willThrowException(new \RuntimeException('broken'));
        } else {
            $product->method('load')->willReturnSelf();
        }
        $product->method('getId')->willReturn($id);
        $product->method('getName')->willReturn($name);
        $product->method('getSku')->willReturn('SKU-' . $id);
        $product->method('getFinalPrice')->willReturn(19.5);
        $product->method('getProductUrl')->willReturn('https://example.test/p' . $id);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getStatus')->willReturn(1);
        $product->method('getVisibility')->willReturn(4);
        return $product;
    }

    /**
     * Build a customer double; null id means "not found".
     *
     * @param int|null $id
     * @param string $name
     * @param bool $throws
     * @return Customer
     */
    private function customerDouble(?int $id, string $name = 'Ann Lee', bool $throws = false): Customer
    {
        $customer = $this->createPartialMock(Customer::class, ['load', 'getId', 'getName', 'getGroupId']);
        if ($throws) {
            $customer->method('load')->willThrowException(new \RuntimeException('broken'));
        } else {
            $customer->method('load')->willReturnSelf();
        }
        $customer->method('getId')->willReturn($id);
        $customer->method('getName')->willReturn($name);
        $customer->method('getGroupId')->willReturn(1);
        $customer->setData('email', 'ann@example.test');
        $customer->setData('created_at', '2024-01-02 03:04:05');
        return $customer;
    }

    /**
     * @return ViewTracker
     */
    private function block(): ViewTracker
    {
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturnCallback(
            fn() => $this->collection(array_shift($this->collectionQueue) ?? [])
        );

        $productFactory = $this->productFactory;
        if ($productFactory === null) {
            $productFactory = $this->createStub(ProductFactory::class);
            $productFactory->method('create')->willReturnCallback(
                fn() => array_shift($this->products) ?? $this->productDouble(null)
            );
        }

        $customerFactory = $this->createStub(CustomerFactory::class);
        $customerFactory->method('create')->willReturnCallback(
            fn() => array_shift($this->customers) ?? $this->customerDouble(null)
        );

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn($route = null, $params = []) => 'url:' . $route . ($params ? '?' . http_build_query($params) : '')
        );
        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);

        $priceHelper = $this->createStub(PriceHelper::class);
        $priceHelper->method('currency')->willReturnCallback(
            static fn($value) => '$' . number_format((float)$value, 2)
        );

        return new ViewTracker(
            $context,
            $collectionFactory,
            $this->viewedFactory ?? $this->createStub(RecentlyViewedFactory::class),
            $productFactory,
            $customerFactory,
            $this->timezone ?? $this->createStub(TimezoneInterface::class),
            $this->imageHelper ?? $this->createStub(ImageHelper::class),
            $priceHelper
        );
    }

    /**
     * @param array $data
     * @return DataObject
     */
    private function view(array $data): DataObject
    {
        return new DataObject($data);
    }

    public function testTimeAgoUnknownForEmptyValue(): void
    {
        $block = $this->block();

        $this->assertSame('Unknown', $block->getTimeAgo(null));
        $this->assertSame('Unknown', $block->getTimeAgo(''));
    }

    public function testTimeAgoBuckets(): void
    {
        $block = $this->block();
        $ago = static fn(int $seconds) => gmdate('Y-m-d H:i:s', time() - $seconds);

        $this->assertSame('Just now', $block->getTimeAgo($ago(5)));
        $this->assertSame('1 minute ago', $block->getTimeAgo($ago(65)));
        $this->assertSame('5 minutes ago', $block->getTimeAgo($ago(5 * 60 + 10)));
        $this->assertSame('1 hour ago', $block->getTimeAgo($ago(3600 + 30)));
        $this->assertSame('3 hours ago', $block->getTimeAgo($ago(3 * 3600 + 30)));
        $this->assertSame('1 day ago', $block->getTimeAgo($ago(86400 + 30)));
        $this->assertSame('6 days ago', $block->getTimeAgo($ago(6 * 86400 + 30)));
    }

    public function testTimeAgoOlderThanAWeekShowsDate(): void
    {
        $this->assertSame('Mar 04, 2020 13:45', $this->block()->getTimeAgo('2020-03-04 13:45:00'));
    }

    public function testFutureTimestampIsJustNow(): void
    {
        $this->assertSame('Just now', $this->block()->getTimeAgo(gmdate('Y-m-d H:i:s', time() + 3600)));
    }

    public function testViewStatsCountsAndDateFilters(): void
    {
        $this->collectionQueue = [
            ['size' => 100],
            ['size' => 7],
            ['size' => 30],
            ['size' => 60],
            ['rows' => [['customer_id' => 1], ['customer_id' => 2]]],
            ['rows' => [['visitor_id' => 'a'], ['visitor_id' => 'b'], ['visitor_id' => 'c']]],
        ];

        $stats = $this->block()->getViewStats();

        $this->assertSame(
            ['total' => 100, 'today' => 7, 'week' => 30, 'month' => 60, 'unique_visitors' => 5],
            $stats
        );
        $this->assertSame([], $this->filters[0]);
        $now = $this->utcNow();
        $this->assertSame([['viewed_at', ['gteq' => $now->format('Y-m-d') . ' 00:00:00']]], $this->filters[1]);
        $monday = $now->modify('monday this week')->format('Y-m-d');
        $this->assertSame([['viewed_at', ['gteq' => $monday . ' 00:00:00']]], $this->filters[2]);
        $this->assertSame([['viewed_at', ['gteq' => $now->format('Y-m-01') . ' 00:00:00']]], $this->filters[3]);
        $this->assertSame([['customer_id', ['notnull' => true]]], $this->filters[4]);
        $this->assertSame([['group', ['customer_id']]], $this->selectCalls[4]);
        $this->assertSame([['visitor_id', ['notnull' => true]], ['customer_id', ['null' => true]]], $this->filters[5]);
        $this->assertSame([['group', ['visitor_id']]], $this->selectCalls[5]);
    }

    public function testMostViewedProductsSkipsMissingAndBrokenProducts(): void
    {
        $this->collectionQueue = [[
            'items' => [
                $this->view(['product_id' => 10, 'view_count' => 9]),
                $this->view(['product_id' => 11, 'view_count' => 5]),
                $this->view(['product_id' => 12, 'view_count' => 2]),
            ],
        ]];
        $this->products = [
            $this->productDouble(10, 'Lamp'),
            $this->productDouble(null),
            $this->productDouble(12, 'Desk', true),
        ];

        $result = $this->block()->getMostViewedProducts(3);

        $this->assertSame([[
            'name' => 'Lamp',
            'sku' => 'SKU-10',
            'count' => 9,
            'product_id' => 10,
            'url' => 'url:catalog/product/edit?id=10',
        ]], $result);
        $this->assertSame([
            ['columns', [['view_count' => 'COUNT(*)']]],
            ['group', ['product_id']],
            ['order', ['view_count DESC']],
            ['limit', [3]],
        ], $this->selectCalls[0]);
    }

    public function testRecentViewsResolveViewerNames(): void
    {
        $viewedAt = gmdate('Y-m-d H:i:s', time() - 120);
        $this->collectionQueue = [[
            'items' => [
                $this->view(['id' => 1, 'product_id' => 10, 'customer_id' => 5, 'viewed_at' => $viewedAt]),
                $this->view(['id' => 2, 'product_id' => 11, 'visitor_id' => 'abcdef1234567890']),
                $this->view(['id' => 3, 'product_id' => 12]),
            ],
        ]];
        $this->products = [
            $this->productDouble(10, 'Lamp'),
            $this->productDouble(11, null),
            $this->productDouble(12, 'Desk'),
        ];
        $this->customers = [$this->customerDouble(5, 'Ann Lee')];

        $result = $this->block()->getRecentViews(15);

        $this->assertCount(3, $result);
        $this->assertSame('Ann Lee', $result[0]['viewer_name']);
        $this->assertSame('Lamp', $result[0]['product_name']);
        $this->assertSame('2 minutes ago', $result[0]['time_ago']);
        $this->assertSame('url:catalog/product/edit?id=10', $result[0]['product_url']);
        $this->assertSame('Visitor #abcdef12', $result[1]['viewer_name']);
        $this->assertSame('Unknown Product', $result[1]['product_name']);
        $this->assertSame('Unknown', $result[1]['time_ago']);
        $this->assertSame('Guest', $result[2]['viewer_name']);
        $this->assertSame([['setOrder', ['viewed_at', 'DESC']], ['setPageSize', [15]]], $this->selectCalls[0]);
    }

    public function testRecentViewsSkipEntriesThatFailToLoad(): void
    {
        $this->collectionQueue = [[
            'items' => [
                $this->view(['id' => 1, 'product_id' => 10]),
                $this->view(['id' => 2, 'product_id' => 11]),
            ],
        ]];
        $this->products = [$this->productDouble(10, 'Lamp', true), $this->productDouble(11, 'Desk')];

        $result = $this->block()->getRecentViews();

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['view_id']);
    }

    public function testViewTrendCoversThirtyDaysWithDailyRanges(): void
    {
        $queue = [];
        for ($i = 0; $i < 30; $i++) {
            $queue[] = ['size' => $i];
            $queue[] = ['columnValues' => array_fill(0, $i % 3, 'x')];
        }
        $this->collectionQueue = $queue;

        $trend = $this->block()->getViewTrendData();

        $this->assertCount(30, $trend['labels']);
        $now = $this->utcNow();
        $this->assertSame($now->format('M d'), $trend['labels'][29]);
        $this->assertSame($now->modify('-29 days')->format('M d'), $trend['labels'][0]);
        $this->assertSame(range(0, 29), $trend['view_data']);
        $this->assertSame(0, $trend['unique_data'][0]);
        $this->assertSame(2, $trend['unique_data'][2]);
        $today = $now->format('Y-m-d');
        $this->assertSame([
            ['viewed_at', ['gteq' => $today . ' 00:00:00']],
            ['viewed_at', ['lteq' => $today . ' 23:59:59']],
        ], $this->filters[58]);
        $this->assertSame([['group', ['product_id']]], $this->selectCalls[59]);
    }

    public function testHourlyDistributionHasTwentyFourBuckets(): void
    {
        $this->collectionQueue = array_map(static fn($h) => ['size' => $h * 2], range(0, 23));

        $result = $this->block()->getHourlyDistribution();

        $this->assertSame('00:00', $result['labels'][0]);
        $this->assertSame('09:00', $result['labels'][9]);
        $this->assertSame('23:00', $result['labels'][23]);
        $this->assertCount(24, $result['labels']);
        $this->assertSame(array_map(static fn($h) => $h * 2, range(0, 23)), $result['data']);
        $today = $this->utcNow()->format('Y-m-d');
        $this->assertSame([
            ['viewed_at', ['gteq' => $today . ' 07:00:00']],
            ['viewed_at', ['lteq' => $today . ' 07:59:59']],
        ], $this->filters[7]);
    }

    public function testTopCustomersSkipsMissingCustomers(): void
    {
        $this->collectionQueue = [[
            'items' => [
                $this->view(['customer_id' => 5, 'view_count' => 12]),
                $this->view(['customer_id' => 6, 'view_count' => 3]),
                $this->view(['customer_id' => 7, 'view_count' => 1]),
            ],
        ]];
        $this->customers = [
            $this->customerDouble(5),
            $this->customerDouble(null),
            $this->customerDouble(7, 'X', true),
        ];

        $result = $this->block()->getTopCustomers(5);

        $this->assertSame([[
            'name' => 'Ann Lee',
            'email' => 'ann@example.test',
            'count' => 12,
            'customer_id' => 5,
            'url' => 'url:customer/index/edit?id=5',
        ]], $result);
        $this->assertSame([['customer_id', ['notnull' => true]]], $this->filters[0]);
        $this->assertSame(['limit', [5]], $this->selectCalls[0][3]);
    }

    public function testDashboardUrlRoute(): void
    {
        $this->assertSame('url:quickview/viewtracker/index', $this->block()->getDashboardUrl());
    }

    public function testGetViewReturnsNullForEmptyMissingOrBrokenRecords(): void
    {
        $found = $this->createStub(RecentlyViewed::class);
        $found->method('load')->willReturnSelf();
        $found->method('getId')->willReturn(4);
        $missing = $this->createStub(RecentlyViewed::class);
        $missing->method('load')->willReturnSelf();
        $missing->method('getId')->willReturn(null);
        $broken = $this->createStub(RecentlyViewed::class);
        $broken->method('load')->willThrowException(new \RuntimeException('x'));

        $factory = $this->createStub(RecentlyViewedFactory::class);
        $factory->method('create')->willReturnOnConsecutiveCalls($found, $missing, $broken);
        $this->viewedFactory = $factory;
        $block = $this->block();

        $this->assertNull($block->getView(0));
        $this->assertSame($found, $block->getView(4));
        $this->assertNull($block->getView(5));
        $this->assertNull($block->getView(6));
    }

    public function testProductInfoForExistingProduct(): void
    {
        $this->products = [$this->productDouble(10, 'Lamp')];
        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->expects($this->once())
            ->method('init')
            ->with($this->anything(), 'product_listing_thumbnail_preview')
            ->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('https://example.test/thumb.jpg');
        $this->imageHelper = $imageHelper;

        $info = $this->block()->getProductInfo(10);

        $this->assertSame([
            'id' => 10,
            'name' => 'Lamp',
            'sku' => 'SKU-10',
            'price' => '$19.50',
            'image' => 'https://example.test/thumb.jpg',
            'url' => 'https://example.test/p10',
            'admin_url' => 'url:catalog/product/edit?id=10',
            'type' => 'simple',
            'status' => 1,
            'visibility' => 4,
        ], $info);
    }

    public function testProductInfoNullCases(): void
    {
        $this->products = [$this->productDouble(null), $this->productDouble(3, 'X', true)];
        $block = $this->block();

        $this->assertNull($block->getProductInfo(null));
        $this->assertNull($block->getProductInfo(99));
        $this->assertNull($block->getProductInfo(3));
    }

    public function testCustomerInfoForExistingCustomer(): void
    {
        $this->customers = [$this->customerDouble(5)];

        $this->assertSame([
            'id' => 5,
            'name' => 'Ann Lee',
            'email' => 'ann@example.test',
            'group_id' => 1,
            'created_at' => '2024-01-02 03:04:05',
            'admin_url' => 'url:customer/index/edit?id=5',
        ], $this->block()->getCustomerInfo(5));
    }

    public function testCustomerInfoNullCases(): void
    {
        $this->customers = [$this->customerDouble(null), $this->customerDouble(8, 'X', true)];
        $block = $this->block();

        $this->assertNull($block->getCustomerInfo(''));
        $this->assertNull($block->getCustomerInfo(99));
        $this->assertNull($block->getCustomerInfo(8));
    }

    public function testProductViewHistory(): void
    {
        $this->collectionQueue = [[
            'items' => [
                $this->view(['id' => 1, 'customer_id' => 5, 'visitor_id' => 'zzz']),
                $this->view(['id' => 2, 'visitor_id' => '1234567890abc']),
                $this->view(['id' => 3]),
            ],
        ]];
        $this->customers = [$this->customerDouble(5, 'Ann Lee')];

        $history = $this->block()->getProductViewHistory(10, 4);

        $this->assertSame(['Ann Lee', 'Visitor #12345678', 'Guest'], array_column($history, 'viewer_name'));
        $this->assertSame('url:quickview/viewtracker/view?id=2', $history[1]['detail_url']);
        $this->assertSame('zzz', $history[0]['visitor_id']);
        $this->assertSame([['product_id', 10]], $this->filters[0]);
        $this->assertSame([['setOrder', ['viewed_at', 'DESC']], ['setPageSize', [4]]], $this->selectCalls[0]);
    }

    public function testCustomerViewHistory(): void
    {
        $this->collectionQueue = [[
            'items' => [
                $this->view(['id' => 1, 'product_id' => 10]),
                $this->view(['id' => 2, 'product_id' => 11]),
            ],
        ]];
        $this->products = [$this->productDouble(10, 'Lamp'), $this->productDouble(11, '')];

        $history = $this->block()->getCustomerViewHistory(5, 2);

        $this->assertSame(['Lamp', 'Unknown Product'], array_column($history, 'product_name'));
        $this->assertSame('SKU-10', $history[0]['product_sku']);
        $this->assertSame('url:quickview/viewtracker/view?id=1', $history[0]['detail_url']);
        $this->assertSame([['customer_id', 5]], $this->filters[0]);
    }

    /**
     * @return \DateTimeImmutable
     */
    private function utcNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /**
     * @param string $name
     * @return void
     */
    private function useTimezone(string $name): void
    {
        $this->timezone = $this->createStub(TimezoneInterface::class);
        $this->timezone->method('getConfigTimezone')->willReturn($name);
    }

    /**
     * @param string $local
     * @param string $zone
     * @return string
     */
    private function utcOf(string $local, string $zone): string
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone($zone)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    public function testFormatViewedAtConvertsUtcToStoreTimezone(): void
    {
        $this->useTimezone('America/Chicago');
        $block = $this->block();

        $this->assertSame('Oct 04, 2026 02:04:58', $block->formatViewedAt('2026-10-04 07:04:58'));
        $this->assertSame('Jan 15, 2026 06:00', $block->formatViewedAt('2026-01-15 12:00:00', 'M d, Y H:i'));
        $this->assertSame('', $block->formatViewedAt(null));
        $this->assertSame('', $block->formatViewedAt(''));
        $this->assertSame('not-a-date', $block->formatViewedAt('not-a-date'));
    }

    public function testFormatViewedAtFallsBackToUtcForUnknownTimezone(): void
    {
        $this->useTimezone('Mars/Olympus_Mons');

        $this->assertSame('Oct 04, 2026 07:04:58', $this->block()->formatViewedAt('2026-10-04 07:04:58'));
    }

    public function testTimeAgoOlderThanAWeekUsesStoreTimezone(): void
    {
        $this->useTimezone('Asia/Kolkata');

        $this->assertSame('Mar 04, 2020 19:15', $this->block()->getTimeAgo('2020-03-04 13:45:00'));
    }

    public function testViewStatsUseStoreTimezoneBoundaries(): void
    {
        $zone = 'Asia/Kolkata';
        $this->useTimezone($zone);
        $this->collectionQueue = [
            ['size' => 10],
            ['size' => 1],
            ['size' => 2],
            ['size' => 3],
            ['rows' => []],
            ['rows' => []],
        ];

        $stats = $this->block()->getViewStats();

        $now = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $this->assertSame(0, $stats['unique_visitors']);
        $this->assertSame(
            [['viewed_at', ['gteq' => $this->utcOf($now->format('Y-m-d') . ' 00:00:00', $zone)]]],
            $this->filters[1]
        );
        $monday = $now->modify('monday this week')->format('Y-m-d');
        $this->assertSame(
            [['viewed_at', ['gteq' => $this->utcOf($monday . ' 00:00:00', $zone)]]],
            $this->filters[2]
        );
        $this->assertSame(
            [['viewed_at', ['gteq' => $this->utcOf($now->format('Y-m-01') . ' 00:00:00', $zone)]]],
            $this->filters[3]
        );
    }

    public function testHourlyDistributionUsesStoreTimezoneHours(): void
    {
        $zone = 'Asia/Kolkata';
        $this->useTimezone($zone);
        $this->collectionQueue = array_fill(0, 24, ['size' => 1]);

        $result = $this->block()->getHourlyDistribution();

        $today = (new \DateTimeImmutable('now', new \DateTimeZone($zone)))->format('Y-m-d');
        $this->assertSame(array_fill(0, 24, 1), $result['data']);
        $this->assertSame([
            ['viewed_at', ['gteq' => $this->utcOf($today . ' 07:00:00', $zone)]],
            ['viewed_at', ['lteq' => $this->utcOf($today . ' 07:59:59', $zone)]],
        ], $this->filters[7]);
        $this->assertSame([
            ['viewed_at', ['gteq' => $this->utcOf($today . ' 00:00:00', $zone)]],
            ['viewed_at', ['lteq' => $this->utcOf($today . ' 00:59:59', $zone)]],
        ], $this->filters[0]);
    }

    public function testTrendDataUsesStoreTimezoneDays(): void
    {
        $zone = 'Pacific/Auckland';
        $this->useTimezone($zone);
        $queue = [];
        for ($i = 0; $i < 30; $i++) {
            $queue[] = ['size' => 0];
            $queue[] = ['values' => []];
        }
        $this->collectionQueue = $queue;

        $trend = $this->block()->getViewTrendData();

        $now = new \DateTimeImmutable('now', new \DateTimeZone($zone));
        $this->assertSame($now->format('M d'), $trend['labels'][29]);
        $this->assertSame($now->modify('-29 days')->format('M d'), $trend['labels'][0]);
        $today = $now->format('Y-m-d');
        $this->assertSame([
            ['viewed_at', ['gteq' => $this->utcOf($today . ' 00:00:00', $zone)]],
            ['viewed_at', ['lteq' => $this->utcOf($today . ' 23:59:59', $zone)]],
        ], $this->filters[58]);
        $this->assertSame($this->filters[58], $this->filters[59]);
    }
}
