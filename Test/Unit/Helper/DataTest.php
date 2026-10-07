<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Magento\Store\Model\ScopeInterface;
use Panth\QuickView\Helper\Data;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DataTest extends TestCase
{
    /**
     * @var array
     */
    private array $calls = [];

    /**
     * Build the helper over a fixed config map.
     *
     * @param array $values
     * @return Data
     */
    private function helper(array $values): Data
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function ($path, $scope = null, $storeId = null) use ($values) {
                $this->calls[] = [$path, $scope, $storeId];
                return $values[$path] ?? null;
            }
        );
        $context = $this->createStub(Context::class);
        $context->method('getScopeConfig')->willReturn($scopeConfig);
        return new Data($context);
    }

    /**
     * @return array
     */
    public static function flagProvider(): array
    {
        return [
            'enabled' => ['isEnabled', 'panth_quickview/general/enabled'],
            'gallery' => ['showImageGallery', 'panth_quickview/display/show_image_gallery'],
            'short description' => ['showShortDescription', 'panth_quickview/display/show_short_description'],
            'sku' => ['showSku', 'panth_quickview/display/show_sku'],
            'stock' => ['showStockStatus', 'panth_quickview/display/show_stock_status'],
            'add to cart' => ['showAddToCart', 'panth_quickview/display/show_add_to_cart'],
        ];
    }

    /**
     * @param string $method
     * @param string $path
     * @return void
     */
    #[DataProvider('flagProvider')]
    public function testFlagIsTrueWhenConfigIsSet(string $method, string $path): void
    {
        $helper = $this->helper([$path => '1']);

        $this->assertTrue($helper->$method(3));
        $this->assertSame([[$path, ScopeInterface::SCOPE_STORE, 3]], $this->calls);
    }

    /**
     * @param string $method
     * @param string $path
     * @return void
     */
    #[DataProvider('flagProvider')]
    public function testFlagIsFalseWhenConfigIsMissingOrZero(string $method, string $path): void
    {
        $this->assertFalse($this->helper([])->$method());
        $this->assertFalse($this->helper([$path => '0'])->$method());
    }

    public function testStoreIdDefaultsToNull(): void
    {
        $this->helper([])->isEnabled();

        $this->assertCount(1, $this->calls);
        $this->assertNull($this->calls[0][2]);
    }
}
