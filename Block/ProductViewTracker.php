<?php
declare(strict_types=1);

namespace Panth\QuickView\Block;

use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class ProductViewTracker extends Template
{
    private Registry $registry;

    private ImageHelper $imageHelper;

    private PriceHelper $priceHelper;

    public function __construct(
        Context $context,
        Registry $registry,
        ImageHelper $imageHelper,
        PriceHelper $priceHelper,
        array $data = []
    ) {
        $this->registry = $registry;
        $this->imageHelper = $imageHelper;
        $this->priceHelper = $priceHelper;
        parent::__construct($context, $data);
    }

    public function getCurrentProduct()
    {
        return $this->registry->registry('current_product');
    }

    public function getFormattedPrice(float $price): string
    {
        return (string)$this->priceHelper->currency($price, true, false);
    }

    public function getProductImageUrl($product): string
    {
        return (string)$this->imageHelper
            ->init($product, 'product_page_image_small')
            ->getUrl();
    }
}
