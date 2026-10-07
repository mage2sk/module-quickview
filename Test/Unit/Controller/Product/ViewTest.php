<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Controller\Product;

use Magento\Bundle\Model\Product\Price as BundlePrice;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Checkout\Helper\Cart as CartHelper;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Magento\GroupedProduct\Model\Product\Type\Grouped;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Panth\QuickView\Controller\Product\View;
use Panth\QuickView\Helper\Data as QuickViewHelper;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[AllowMockObjectsWithoutExpectations]
class ViewTest extends TestCase
{
    private const PRODUCT_METHODS = [
        'getStatus', 'isVisibleInSiteVisibility', 'getWebsiteIds', 'getTypeId', 'isAvailable',
        'getFinalPrice', 'getPrice', 'getTypeInstance', 'getPriceModel', 'getId', 'getName',
        'getSku', 'getProductUrl', 'getMediaGalleryImages',
    ];

    /**
     * @var array|null
     */
    private ?array $jsonData = null;

    /**
     * @var array
     */
    private array $params = ['id' => '5'];

    /**
     * @var bool
     */
    private bool $enabled = true;

    /**
     * @var array
     */
    private array $repositoryCalls = [];

    /**
     * @var array
     */
    private array $imageInits = [];

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $repositoryError = null;

    /**
     * @var StockRegistryInterface|null
     */
    private ?StockRegistryInterface $stockRegistry = null;

    /**
     * @var LoggerInterface|null
     */
    private ?LoggerInterface $logger = null;

    /**
     * Build a product double with sensible viewable defaults.
     *
     * @param array $overrides
     * @return Product&MockObject
     */
    private function product(array $overrides = []): Product
    {
        $values = $overrides + [
            'getStatus' => 1,
            'isVisibleInSiteVisibility' => true,
            'getWebsiteIds' => ['1'],
            'getTypeId' => 'simple',
            'isAvailable' => true,
            'getFinalPrice' => 20.0,
            'getPrice' => 20.0,
            'getId' => '5',
            'getName' => 'Blue Shirt',
            'getSku' => 'BS-1',
            'getProductUrl' => 'https://example.test/blue-shirt.html',
            'getMediaGalleryImages' => null,
            'getTypeInstance' => null,
            'getPriceModel' => null,
        ];
        $product = $this->createPartialMock(Product::class, self::PRODUCT_METHODS);
        foreach ($values as $method => $value) {
            $product->method($method)->willReturn($value);
        }
        return $product;
    }

    /**
     * Build a child product used for composite min price.
     *
     * @param float $price
     * @param bool $disabled
     * @return Product
     */
    private function child(float $price, bool $disabled = false): Product
    {
        $child = $this->createStub(Product::class);
        $child->method('getFinalPrice')->willReturn($price);
        $child->method('isDisabled')->willReturn($disabled);
        return $child;
    }

    /**
     * Build the controller around the given product.
     *
     * @param Product|null $product
     * @return View
     */
    private function controller(?Product $product): View
    {
        $json = $this->createStub(Json::class);
        $json->method('setData')->willReturnCallback(function ($data) use ($json) {
            $this->jsonData = $data;
            return $json;
        });
        $jsonFactory = $this->createStub(JsonFactory::class);
        $jsonFactory->method('create')->willReturn($json);

        $repository = $this->createStub(ProductRepositoryInterface::class);
        $repository->method('getById')->willReturnCallback(
            function ($id, $edit = false, $storeId = null) use ($product) {
                $this->repositoryCalls[] = [$id, $edit, $storeId];
                if ($this->repositoryError) {
                    throw $this->repositoryError;
                }
                return $product;
            }
        );

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn('2');
        $store->method('getWebsiteId')->willReturn('1');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $priceHelper = $this->createStub(PriceHelper::class);
        $priceHelper->method('currency')->willReturnCallback(
            static fn($value) => '$' . number_format((float)$value, 2)
        );

        $helper = $this->createStub(QuickViewHelper::class);
        $helper->method('isEnabled')->willReturnCallback(fn() => $this->enabled);

        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            fn($key, $default = null) => $this->params[$key] ?? $default
        );

        $imageHelper = $this->createStub(ImageHelper::class);
        $state = new \stdClass();
        $state->role = '';
        $state->file = '';
        $imageHelper->method('init')->willReturnCallback(function ($p, $role) use ($imageHelper, $state) {
            $this->imageInits[] = $role;
            $state->role = $role;
            $state->file = 'placeholder';
            return $imageHelper;
        });
        $imageHelper->method('setImageFile')->willReturnCallback(function ($file) use ($imageHelper, $state) {
            $state->file = $file;
            return $imageHelper;
        });
        $imageHelper->method('resize')->willReturnSelf();
        $imageHelper->method('getUrl')->willReturnCallback(
            static fn() => 'img:' . $state->role . ':' . $state->file
        );

        $formKey = $this->createStub(FormKey::class);
        $formKey->method('getFormKey')->willReturn('fk123');

        return new View(
            $jsonFactory,
            $repository,
            $storeManager,
            $priceHelper,
            $this->stockRegistry ?? $this->createStub(StockRegistryInterface::class),
            $helper,
            $request,
            $imageHelper,
            $this->createStub(PageFactory::class),
            $this->createStub(CartHelper::class),
            $formKey,
            $this->createStub(Registry::class),
            $this->logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    /**
     * @param bool $inStock
     * @param float $qty
     * @return StockRegistryInterface
     */
    private function stock(bool $inStock, float $qty = 0.0): StockRegistryInterface
    {
        $item = $this->createStub(StockItemInterface::class);
        $item->method('getIsInStock')->willReturn($inStock);
        $item->method('getQty')->willReturn($qty);
        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItem')->willReturn($item);
        return $registry;
    }

    /**
     * @param array $images
     * @return Collection
     */
    private function gallery(array $images): Collection
    {
        $gallery = $this->createStub(Collection::class);
        $gallery->method('getSize')->willReturn(count($images));
        $gallery->method('getIterator')->willReturn(new \ArrayIterator($images));
        return $gallery;
    }

    public function testDisabledModuleReturnsError(): void
    {
        $this->enabled = false;

        $this->controller($this->product())->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('Quick View is disabled.', (string)$this->jsonData['message']);
        $this->assertSame([], $this->repositoryCalls);
    }

    public function testMissingOrInvalidIdReturnsError(): void
    {
        foreach ([[], ['id' => '0'], ['id' => '-3'], ['id' => 'abc']] as $params) {
            $this->params = $params;
            $this->controller($this->product())->execute();

            $this->assertFalse($this->jsonData['success']);
            $this->assertSame('Product ID is required.', (string)$this->jsonData['message']);
        }
        $this->assertSame([], $this->repositoryCalls);
    }

    public function testProductIsLoadedForCurrentStore(): void
    {
        $this->stockRegistry = $this->stock(true);
        $this->controller($this->product())->execute();

        $this->assertSame([[5, false, 2]], $this->repositoryCalls);
    }

    public function testMissingProductReturnsNotFound(): void
    {
        $this->repositoryError = new NoSuchEntityException(__('nope'));

        $this->controller(null)->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('Product not found.', (string)$this->jsonData['message']);
    }

    public function testUnexpectedErrorIsLoggedAndHidden(): void
    {
        $this->repositoryError = new \RuntimeException('db down');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'QuickView controller error: db down',
                $this->callback(static fn($ctx) => $ctx['product_id'] === 5 && isset($ctx['trace']))
            );
        $this->logger = $logger;

        $this->controller(null)->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('Unable to load product data. Please try again.', (string)$this->jsonData['message']);
    }

    public function testDisabledProductIsNotViewable(): void
    {
        $this->controller($this->product(['getStatus' => 2]))->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('Product not found.', (string)$this->jsonData['message']);
    }

    public function testInvisibleProductIsNotViewable(): void
    {
        $this->controller($this->product(['isVisibleInSiteVisibility' => false]))->execute();

        $this->assertFalse($this->jsonData['success']);
        $this->assertSame('Product not found.', (string)$this->jsonData['message']);
    }

    public function testProductFromAnotherWebsiteIsNotViewable(): void
    {
        $this->controller($this->product(['getWebsiteIds' => ['3', '4']]))->execute();

        $this->assertFalse($this->jsonData['success']);
    }

    public function testProductWithoutWebsitesIsNotViewable(): void
    {
        $this->controller($this->product(['getWebsiteIds' => null]))->execute();

        $this->assertFalse($this->jsonData['success']);
    }

    public function testSimpleProductPayload(): void
    {
        $this->stockRegistry = $this->stock(true);
        $product = $this->product();
        $product->setData('short_description', '<p>Soft &amp; warm</p>');

        $this->controller($product)->execute();

        $this->assertTrue($this->jsonData['success']);
        $data = $this->jsonData['product'];
        $this->assertSame(5, $data['id']);
        $this->assertSame('Blue Shirt', $data['name']);
        $this->assertSame('BS-1', $data['sku']);
        $this->assertSame('simple', $data['type_id']);
        $this->assertTrue($data['in_stock']);
        $this->assertSame(0.0, $data['stock_qty']);
        $this->assertSame('https://example.test/blue-shirt.html', $data['url']);
        $this->assertSame('fk123', $data['form_key']);
        $this->assertSame('Soft & warm', $data['short_description']);
        $this->assertSame('', $this->jsonData['options_html']);
        $this->assertStringContainsString('$20.00', $data['price_html']);
        $this->assertStringNotContainsString('line-through', $data['price_html']);
        $this->assertStringNotContainsString('As low as', $data['price_html']);
    }

    public function testSimpleProductReportsStockQty(): void
    {
        $this->stockRegistry = $this->stock(true, 12.0);
        $this->controller($this->product())->execute();

        $this->assertSame(12.0, $this->jsonData['product']['stock_qty']);
    }

    public function testSpecialPriceShowsStruckRegularPriceFirst(): void
    {
        $this->stockRegistry = $this->stock(true);
        $this->controller($this->product(['getFinalPrice' => 15.0, 'getPrice' => 25.0]))->execute();

        $html = $this->jsonData['product']['price_html'];
        $this->assertStringContainsString('line-through', $html);
        $this->assertLessThan(strpos($html, '$15.00'), strpos($html, '$25.00'));
    }

    public function testZeroFinalPriceDoesNotShowStrikeThrough(): void
    {
        $this->stockRegistry = $this->stock(true);
        $this->controller($this->product(['getFinalPrice' => 0.0, 'getPrice' => 25.0]))->execute();

        $html = $this->jsonData['product']['price_html'];
        $this->assertStringNotContainsString('line-through', $html);
        $this->assertStringContainsString('$0.00', $html);
    }

    public function testOutOfStockSimpleProductUsesStockRegistry(): void
    {
        $this->stockRegistry = $this->stock(false);
        $this->controller($this->product(['isAvailable' => true]))->execute();

        $this->assertFalse($this->jsonData['product']['in_stock']);
    }

    public function testVirtualProductAlsoUsesStockRegistry(): void
    {
        $this->stockRegistry = $this->stock(false);
        $this->controller($this->product(['getTypeId' => 'virtual']))->execute();

        $this->assertFalse($this->jsonData['product']['in_stock']);
    }

    public function testStockLookupFailureAssumesInStock(): void
    {
        $registry = $this->createStub(StockRegistryInterface::class);
        $registry->method('getStockItem')->willThrowException(new \RuntimeException('no stock'));
        $this->stockRegistry = $registry;

        $this->controller($this->product())->execute();

        $this->assertTrue($this->jsonData['success']);
        $this->assertTrue($this->jsonData['product']['in_stock']);
    }

    public function testConfigurableUsesAvailabilityAndCheapestEnabledChild(): void
    {
        $type = $this->createStub(Configurable::class);
        $type->method('getUsedProducts')->willReturn([
            $this->child(30.0),
            $this->child(5.0, true),
            $this->child(12.0),
        ]);
        $product = $this->product([
            'getTypeId' => 'configurable',
            'getTypeInstance' => $type,
            'isAvailable' => false,
            'getFinalPrice' => 0.0,
            'getPrice' => 99.0,
        ]);

        $this->controller($product)->execute();

        $data = $this->jsonData['product'];
        $this->assertFalse($data['in_stock']);
        $this->assertStringContainsString('As low as', $data['price_html']);
        $this->assertStringContainsString('$12.00', $data['price_html']);
        $this->assertStringNotContainsString('$5.00', $data['price_html']);
        $this->assertStringNotContainsString('line-through', $data['price_html']);
    }

    public function testConfigurableWithoutChildrenFallsBackToFinalPrice(): void
    {
        $type = $this->createStub(Configurable::class);
        $type->method('getUsedProducts')->willReturn([]);
        $product = $this->product([
            'getTypeId' => 'configurable',
            'getTypeInstance' => $type,
            'getFinalPrice' => 44.0,
        ]);

        $this->controller($product)->execute();

        $this->assertStringContainsString('$44.00', $this->jsonData['product']['price_html']);
    }

    public function testGroupedUsesAssociatedProducts(): void
    {
        $type = $this->createStub(Grouped::class);
        $type->method('getAssociatedProducts')->willReturn([$this->child(8.0), $this->child(3.5)]);
        $product = $this->product(['getTypeId' => 'grouped', 'getTypeInstance' => $type]);

        $this->controller($product)->execute();

        $html = $this->jsonData['product']['price_html'];
        $this->assertStringContainsString('As low as', $html);
        $this->assertStringContainsString('$3.50', $html);
    }

    public function testBundleUsesMinimumTotalPrice(): void
    {
        $priceModel = $this->createStub(BundlePrice::class);
        $priceModel->method('getTotalPrices')->willReturn(17.25);
        $product = $this->product(['getTypeId' => 'bundle', 'getPriceModel' => $priceModel]);

        $this->controller($product)->execute();

        $this->assertStringContainsString('$17.25', $this->jsonData['product']['price_html']);
    }

    public function testBundleWithZeroMinimumFallsBackToFinalPrice(): void
    {
        $priceModel = $this->createStub(BundlePrice::class);
        $priceModel->method('getTotalPrices')->willReturn(0);
        $product = $this->product([
            'getTypeId' => 'bundle',
            'getPriceModel' => $priceModel,
            'getFinalPrice' => 61.0,
        ]);

        $this->controller($product)->execute();

        $this->assertStringContainsString('$61.00', $this->jsonData['product']['price_html']);
    }

    public function testGalleryImagesProduceMainAndThumbUrls(): void
    {
        $this->stockRegistry = $this->stock(true);
        $gallery = $this->gallery([
            new DataObject(['file' => '/a/b.jpg', 'label' => 'Front', 'position' => '2']),
            new DataObject(['file' => '/c/d.jpg', 'label' => '', 'position' => null]),
        ]);

        $this->controller($this->product(['getMediaGalleryImages' => $gallery]))->execute();

        $images = $this->jsonData['product']['images'];
        $this->assertCount(2, $images);
        $this->assertSame('img:product_page_image_large:/a/b.jpg', $images[0]['url']);
        $this->assertSame('img:product_page_image_small:/a/b.jpg', $images[0]['thumb_url']);
        $this->assertSame('Front', $images[0]['label']);
        $this->assertSame(2, $images[0]['position']);
        $this->assertSame('Blue Shirt', $images[1]['label']);
        $this->assertSame(0, $images[1]['position']);
    }

    public function testEmptyGalleryFallsBackToPlaceholder(): void
    {
        $this->stockRegistry = $this->stock(true);

        $this->controller($this->product(['getMediaGalleryImages' => $this->gallery([])]))->execute();

        $images = $this->jsonData['product']['images'];
        $this->assertCount(1, $images);
        $this->assertSame('img:product_page_image_large:placeholder', $images[0]['url']);
        $this->assertSame($images[0]['url'], $images[0]['thumb_url']);
        $this->assertSame('Blue Shirt', $images[0]['label']);
        $this->assertSame(0, $images[0]['position']);
    }

    public function testDescriptionIsUsedWhenShortDescriptionIsEmpty(): void
    {
        $this->stockRegistry = $this->stock(true);
        $product = $this->product();
        $product->setData('short_description', '');
        $product->setData('description', "<div>Line one\n\n   line   two</div>");

        $this->controller($product)->execute();

        $this->assertSame('Line one line two', $this->jsonData['product']['short_description']);
    }

    public function testNoDescriptionGivesEmptyString(): void
    {
        $this->stockRegistry = $this->stock(true);

        $this->controller($this->product())->execute();

        $this->assertSame('', $this->jsonData['product']['short_description']);
    }

    public function testLongDescriptionIsCutAtWordBoundary(): void
    {
        $this->stockRegistry = $this->stock(true);
        $product = $this->product();
        $product->setData('short_description', str_repeat('abcd ', 70));

        $this->controller($product)->execute();

        $text = $this->jsonData['product']['short_description'];
        $this->assertSame(str_repeat('abcd ', 59) . 'abcd...', $text);
        $this->assertLessThanOrEqual(303, strlen($text));
    }

    public function testShortDanglingWordIsDroppedWhenTruncating(): void
    {
        $this->stockRegistry = $this->stock(true);
        $product = $this->product();
        $product->setData('short_description', str_repeat('abcd ', 59) . 'a ' . str_repeat('x', 40));

        $this->controller($product)->execute();

        $this->assertSame(str_repeat('abcd ', 58) . 'abcd...', $this->jsonData['product']['short_description']);
    }

    public function testTrailingPunctuationIsTrimmedBeforeEllipsis(): void
    {
        $this->stockRegistry = $this->stock(true);
        $product = $this->product();
        $product->setData('short_description', str_repeat('abcd, ', 60));

        $this->controller($product)->execute();

        $text = $this->jsonData['product']['short_description'];
        $this->assertStringEndsWith('abcd...', $text);
        $this->assertStringEndsNotWith(',...', $text);
    }
}
