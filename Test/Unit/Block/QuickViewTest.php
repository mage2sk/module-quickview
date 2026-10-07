<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Block;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Template\Context;
use Panth\QuickView\Block\QuickView;
use Panth\QuickView\Helper\Data;
use PHPUnit\Framework\TestCase;

class QuickViewTest extends TestCase
{
    /**
     * @var array
     */
    private array $urlCalls = [];

    /**
     * @var Data|null
     */
    private ?Data $helper = null;

    /**
     * Build the block with a helper reporting the given enabled flag.
     *
     * @param bool $enabled
     * @return QuickView
     */
    private function block(bool $enabled): QuickView
    {
        $this->helper = $this->createStub(Data::class);
        $this->helper->method('isEnabled')->willReturn($enabled);

        $url = $this->createStub(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(function ($route = null, $params = null) {
            $this->urlCalls[] = [$route, $params];
            return 'https://example.test/' . $route;
        });
        $context = $this->createStub(Context::class);
        $context->method('getUrlBuilder')->willReturn($url);

        return new QuickView($context, $this->helper);
    }

    public function testIsEnabledDelegatesToHelper(): void
    {
        $this->assertTrue($this->block(true)->isEnabled());
        $this->assertFalse($this->block(false)->isEnabled());
    }

    public function testGetHelperReturnsInjectedHelper(): void
    {
        $block = $this->block(true);

        $this->assertSame($this->helper, $block->getHelper());
    }

    public function testQuickViewUrlWithoutProductIdHasNoParams(): void
    {
        $url = $this->block(true)->getQuickViewUrl();

        $this->assertSame('https://example.test/quickview/product/view', $url);
        $this->assertSame('quickview/product/view', $this->urlCalls[0][0]);
        $this->assertEmpty($this->urlCalls[0][1]);
    }

    public function testQuickViewUrlPassesTheProductId(): void
    {
        $this->block(true)->getQuickViewUrl('42');

        $this->assertSame('quickview/product/view', $this->urlCalls[0][0]);
        $this->assertSame(['id' => '42'], $this->urlCalls[0][1]);
    }
}
