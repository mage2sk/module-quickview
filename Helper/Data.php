<?php
declare(strict_types=1);

namespace Panth\QuickView\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Store\Model\ScopeInterface;

class Data extends AbstractHelper
{
    private const XML_PATH_GENERAL = 'panth_quickview/general/';
    private const XML_PATH_DISPLAY = 'panth_quickview/display/';

    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_GENERAL . 'enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function showImageGallery(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_DISPLAY . 'show_image_gallery',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function showShortDescription(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_DISPLAY . 'show_short_description',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function showSku(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_DISPLAY . 'show_sku',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function showStockStatus(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_DISPLAY . 'show_stock_status',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function showAddToCart(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            self::XML_PATH_DISPLAY . 'show_add_to_cart',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
