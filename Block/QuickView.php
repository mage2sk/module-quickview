<?php
declare(strict_types=1);

namespace Panth\QuickView\Block;

use Magento\Framework\View\Element\Template;
use Panth\QuickView\Helper\Data as QuickViewHelper;

class QuickView extends Template
{
    private QuickViewHelper $quickViewHelper;

    public function __construct(
        Template\Context $context,
        QuickViewHelper $quickViewHelper,
        array $data = []
    ) {
        $this->quickViewHelper = $quickViewHelper;
        parent::__construct($context, $data);
    }

    public function getHelper(): QuickViewHelper
    {
        return $this->quickViewHelper;
    }

    public function isEnabled(): bool
    {
        return $this->quickViewHelper->isEnabled();
    }

    public function getQuickViewUrl(string $productId = ''): string
    {
        return $this->getUrl('quickview/product/view', $productId !== '' ? ['id' => $productId] : []);
    }
}
