<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class TemplateMarkupTest extends TestCase
{
    private function template(string $path): string
    {
        $file = dirname(__DIR__, 3) . '/view/frontend/templates/' . $path;
        $this->assertFileExists($file);
        return (string) file_get_contents($file);
    }

    public function testHyvaModalFactoryIsDefinedBeforeComponentMarkup(): void
    {
        $html = $this->template('quick-view-modal.phtml');
        $factory = strpos($html, 'function quickViewModal()');
        $component = strpos($html, 'x-data="quickViewModal()"');
        $this->assertNotFalse($factory);
        $this->assertNotFalse($component);
        $this->assertLessThan($component, $factory);
    }

    public function testHyvaModalFactoryDeclaresEveryBoundProperty(): void
    {
        $html = $this->template('quick-view-modal.phtml');
        foreach (['show:', 'loading:', 'error:', 'product:', 'mainImage:', 'qty:', 'addingToCart:', 'cartSuccess:'] as $prop) {
            $this->assertStringContainsString($prop, $html);
        }
    }

    public function testLumaListingButtonHasFullTapTarget(): void
    {
        $html = $this->template('luma/quick-view-modal.phtml');
        $this->assertStringContainsString('.product-item-actions .actions-secondary > .qv-action-btn.action', $html);
        $this->assertMatchesRegularExpression('/\.qv-action-btn[^{]*\{[^}]*width:44px;min-width:44px;height:44px;/', $html);
        $this->assertStringNotContainsString('width:32px;height:32px;border:1px solid #e5e5e5', $html);
    }
}
