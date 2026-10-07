<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Block;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template\Context;
use Panth\QuickView\Block\ProductViewTracker;
use PHPUnit\Framework\TestCase;

class ProductViewTrackerTest extends TestCase
{
    /**
     * @param Registry|null $registry
     * @param ImageHelper|null $imageHelper
     * @param PriceHelper|null $priceHelper
     * @return ProductViewTracker
     */
    private function block(
        ?Registry $registry = null,
        ?ImageHelper $imageHelper = null,
        ?PriceHelper $priceHelper = null
    ): ProductViewTracker {
        return new ProductViewTracker(
            $this->createStub(Context::class),
            $registry ?? $this->createStub(Registry::class),
            $imageHelper ?? $this->createStub(ImageHelper::class),
            $priceHelper ?? $this->createStub(PriceHelper::class)
        );
    }

    public function testCurrentProductComesFromRegistry(): void
    {
        $product = $this->createStub(Product::class);
        $registry = $this->createStub(Registry::class);
        $registry->method('registry')->willReturnMap([['current_product', $product]]);

        $this->assertSame($product, $this->block($registry)->getCurrentProduct());
    }

    public function testCurrentProductIsNullWhenNotRegistered(): void
    {
        $this->assertNull($this->block()->getCurrentProduct());
    }

    public function testFormattedPriceUsesCurrencyWithoutContainer(): void
    {
        $priceHelper = $this->createMock(PriceHelper::class);
        $priceHelper->expects($this->once())
            ->method('currency')
            ->with(12.5, true, false)
            ->willReturn('$12.50');

        $this->assertSame('$12.50', $this->block(null, null, $priceHelper)->getFormattedPrice(12.5));
    }

    public function testFormattedPriceCastsNumericHelperResultToString(): void
    {
        $priceHelper = $this->createStub(PriceHelper::class);
        $priceHelper->method('currency')->willReturn(9.99);

        $this->assertSame('9.99', $this->block(null, null, $priceHelper)->getFormattedPrice(9.99));
    }

    public function testProductImageUrlUsesSmallImageRole(): void
    {
        $product = $this->createStub(Product::class);
        $imageHelper = $this->createMock(ImageHelper::class);
        $imageHelper->expects($this->once())
            ->method('init')
            ->with($product, 'product_page_image_small')
            ->willReturnSelf();
        $imageHelper->method('getUrl')->willReturn('https://example.test/img.jpg');

        $this->assertSame(
            'https://example.test/img.jpg',
            $this->block(null, $imageHelper)->getProductImageUrl($product)
        );
    }
}
