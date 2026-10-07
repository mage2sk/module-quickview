<?php
declare(strict_types=1);

namespace Panth\QuickView\Controller\Adminhtml\ViewTracker;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;

class Index extends Action
{
    const ADMIN_RESOURCE = 'Panth_QuickView::view_tracker';

    protected $resultPageFactory;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
    }

    public function execute()
    {
        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Panth_QuickView::view_tracker');
        $resultPage->getConfig()->getTitle()->prepend(__('Product View Tracker'));
        return $resultPage;
    }
}
