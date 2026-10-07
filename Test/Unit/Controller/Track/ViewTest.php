<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Registry;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\QuickView\Controller\Track\View;
use Panth\QuickView\Model\RecentlyViewed;
use Panth\QuickView\Model\RecentlyViewedFactory;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed as RecentlyViewedResource;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed\Collection;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ViewTest extends TestCase
{
    /**
     * @var array
     */
    private array $params = ['product_id' => '9'];

    /**
     * @var array|null
     */
    private ?array $jsonData = null;

    /**
     * @var int|null
     */
    private ?int $httpCode = null;

    /**
     * @var array
     */
    private array $filters = [];

    /**
     * @var array
     */
    private array $saved = [];

    /**
     * @var array
     */
    private array $cacheSaves = [];

    /**
     * @var array
     */
    private array $cacheLoads = [];

    /**
     * @var string|false
     */
    private $cacheHit = false;

    /**
     * @var int
     */
    private int $existingCount = 0;

    /**
     * @var string
     */
    private string $ip = '203.0.113.7';

    /**
     * @var string|null
     */
    private ?string $userAgent = 'TestAgent/1.0';

    /**
     * @var bool
     */
    private bool $loggedIn = false;

    /**
     * @var RecentlyViewed|null
     */
    private ?RecentlyViewed $existing = null;

    /**
     * @var RecentlyViewed|null
     */
    private ?RecentlyViewed $created = null;

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $saveError = null;

    /**
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger = null;

    /**
     * @var FormKeyValidator|null
     */
    private ?FormKeyValidator $formKeyValidator = null;

    /**
     * @return RecentlyViewed
     */
    private function model(): RecentlyViewed
    {
        $resource = $this->createStub(RecentlyViewedResource::class);
        $resource->method('getIdFieldName')->willReturn('view_id');
        return new RecentlyViewed(
            $this->createStub(ModelContext::class),
            $this->createStub(Registry::class),
            $resource
        );
    }

    /**
     * @return View
     */
    private function controller(): View
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn($key, $default = null) => $this->params[$key] ?? $default
        );
        $request->method('getServer')->willReturnCallback(
            fn($name = null) => $name === 'HTTP_USER_AGENT' ? $this->userAgent : null
        );

        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->jsonData = $data;
            return $json;
        });
        $json->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($json) {
            $this->httpCode = $code;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $this->created = $this->model();
        $factory = $this->createStub(RecentlyViewedFactory::class);
        $factory->method('create')->willReturn($this->created);

        $resource = $this->createStub(RecentlyViewedResource::class);
        $resource->method('save')->willReturnCallback(function ($object) use ($resource) {
            if ($this->saveError) {
                throw $this->saveError;
            }
            $this->saved[] = $object;
            return $resource;
        });

        $this->existing = $this->model();
        $this->existing->setData(['view_id' => 3, 'viewed_at' => '2020-01-01 00:00:00']);
        $collection = $this->createStub(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use ($collection) {
            $this->filters[] = [$field, $cond];
            return $collection;
        });
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getSize')->willReturnCallback(fn() => $this->existingCount);
        $collection->method('getFirstItem')->willReturnCallback(fn() => $this->existing);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $session = $this->createStub(CustomerSession::class);
        $session->method('isLoggedIn')->willReturnCallback(fn() => $this->loggedIn);
        $session->method('getCustomerId')->willReturn('21');

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('4');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($key) {
            $this->cacheLoads[] = $key;
            return $this->cacheHit;
        });
        $cache->method('save')->willReturnCallback(function ($data, $key, $tags = [], $ttl = null) {
            $this->cacheSaves[] = [$data, $key, $tags, $ttl];
            return true;
        });

        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturnCallback(fn() => $this->ip);

        return new View(
            $request,
            $jsonFactory,
            $factory,
            $resource,
            $collectionFactory,
            $session,
            $storeManager,
            $this->logger ?? $this->createStub(LoggerInterface::class),
            $cache,
            $remote,
            $this->formKeyValidator ?? $this->createStub(FormKeyValidator::class)
        );
    }

    /**
     * @param string $ip
     * @param int $storeId
     * @param int $productId
     * @return string
     */
    private function rateKey(string $ip, int $storeId, int $productId): string
    {
        return 'panth_quickview_track_' . hash('sha256', $ip . '|' . $storeId . '|' . $productId);
    }

    /**
     * @param string $field
     * @return array
     */
    private function filterFor(string $field): array
    {
        return array_values(array_filter($this->filters, static fn($f) => $f[0] === $field));
    }

    public function testMissingProductIdIsRejected(): void
    {
        $this->params = [];

        $this->controller()->execute();

        $this->assertSame(['success' => false, 'message' => 'Product ID required.'], $this->jsonData);
        $this->assertSame([], $this->cacheLoads);
        $this->assertSame([], $this->saved);
    }

    public function testRateLimitedRequestIsSkipped(): void
    {
        $this->cacheHit = '1';

        $this->controller()->execute();

        $this->assertSame(['success' => true, 'action' => 'skipped'], $this->jsonData);
        $this->assertSame([$this->rateKey('203.0.113.7', 4, 9)], $this->cacheLoads);
        $this->assertSame([], $this->saved);
        $this->assertSame([], $this->cacheSaves);
    }

    public function testGuestViewIsCreatedWithVisitorHash(): void
    {
        $this->controller()->execute();

        $this->assertSame(['success' => true, 'action' => 'created'], $this->jsonData);
        $this->assertCount(1, $this->saved);
        $this->assertSame($this->created, $this->saved[0]);
        $visitor = hash('sha256', '203.0.113.7|TestAgent/1.0');
        $this->assertSame(9, $this->created->getData('product_id'));
        $this->assertNull($this->created->getData('customer_id'));
        $this->assertSame($visitor, $this->created->getData('visitor_id'));
        $this->assertSame(4, $this->created->getData('store_id'));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $this->created->getData('viewed_at')
        );
        $this->assertSame([['visitor_id', $visitor]], $this->filterFor('visitor_id'));
        $this->assertSame([], $this->filterFor('customer_id'));
        $this->assertSame([['1', $this->rateKey('203.0.113.7', 4, 9), [], View::RATE_LIMIT_SECONDS]], $this->cacheSaves);
    }

    public function testDedupeQueryIsScopedToProductStoreAndLastFiveMinutes(): void
    {
        $before = time() - 300;
        $this->controller()->execute();
        $after = time() - 300;

        $this->assertSame([['product_id', 9]], $this->filterFor('product_id'));
        $this->assertSame([['store_id', 4]], $this->filterFor('store_id'));
        $since = strtotime($this->filterFor('viewed_at')[0][1]['gteq']);
        $this->assertGreaterThanOrEqual($before, $since);
        $this->assertLessThanOrEqual($after, $since);
    }

    public function testLoggedInCustomerIsFilteredByCustomerId(): void
    {
        $this->loggedIn = true;

        $this->controller()->execute();

        $this->assertSame([['customer_id', 21]], $this->filterFor('customer_id'));
        $this->assertSame([], $this->filterFor('visitor_id'));
        $this->assertSame(21, $this->created->getData('customer_id'));
        $this->assertNotEmpty($this->created->getData('visitor_id'));
    }

    public function testRecentViewIsUpdatedInsteadOfDuplicated(): void
    {
        $this->existingCount = 1;

        $this->controller()->execute();

        $this->assertSame(['success' => true, 'action' => 'updated'], $this->jsonData);
        $this->assertSame([$this->existing], $this->saved);
        $this->assertNotSame('2020-01-01 00:00:00', $this->existing->getData('viewed_at'));
        $this->assertCount(1, $this->cacheSaves);
        $this->assertNull($this->created->getData('product_id'));
    }

    public function testEmptyRemoteAddressFallsBackToZeroIp(): void
    {
        $this->ip = '';
        $this->userAgent = null;

        $this->controller()->execute();

        $this->assertSame($this->rateKey('0.0.0.0', 4, 9), $this->cacheSaves[0][1]);
        $this->assertSame(hash('sha256', '0.0.0.0|'), $this->created->getData('visitor_id'));
    }

    public function testSaveFailureIsLoggedAndReported(): void
    {
        $this->saveError = new \RuntimeException('deadlock');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with('QuickView Track error: deadlock');
        $this->logger = $logger;

        $this->controller()->execute();

        $this->assertSame(['success' => false, 'message' => 'Tracking failed.'], $this->jsonData);
        $this->assertSame([], $this->cacheSaves);
    }

    public function testCsrfExceptionCarries403JsonResult(): void
    {
        $exception = $this->controller()->createCsrfValidationException($this->createStub(RequestInterface::class));

        $this->assertInstanceOf(InvalidRequestException::class, $exception);
        $this->assertSame(403, $this->httpCode);
        $this->assertSame(['success' => false, 'message' => 'Invalid form key.'], $this->jsonData);
    }

    public function testCsrfValidationSkipsNonHttpAndNonPostRequests(): void
    {
        $validator = $this->createMock(FormKeyValidator::class);
        $validator->expects($this->never())->method('validate');
        $this->formKeyValidator = $validator;
        $controller = $this->controller();

        $get = $this->createStub(HttpRequest::class);
        $get->method('isPost')->willReturn(false);

        $this->assertTrue($controller->validateForCsrf($this->createStub(RequestInterface::class)));
        $this->assertTrue($controller->validateForCsrf($get));
    }

    public function testCsrfValidationUsesFormKeyForPost(): void
    {
        $post = $this->createStub(HttpRequest::class);
        $post->method('isPost')->willReturn(true);
        $validator = $this->createStub(FormKeyValidator::class);
        $validator->method('validate')->willReturnOnConsecutiveCalls(true, false);
        $this->formKeyValidator = $validator;
        $controller = $this->controller();

        $this->assertTrue($controller->validateForCsrf($post));
        $this->assertFalse($controller->validateForCsrf($post));
    }
}
