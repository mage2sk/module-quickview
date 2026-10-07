<?php
declare(strict_types=1);

namespace Panth\QuickView\Controller\Adminhtml\ViewTracker;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Panth\QuickView\Model\RecentlyViewedFactory;

class View extends Action
{
    const ADMIN_RESOURCE = 'Panth_QuickView::view_tracker';

    protected $resultPageFactory;

    protected $viewedFactory;

    public function __construct(
        Context $context,
        PageFactory $resultPageFactory,
        RecentlyViewedFactory $viewedFactory
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->viewedFactory = $viewedFactory;
    }

    public function execute()
    {
        $id = $this->getRequest()->getParam('id');

        if (!$id) {
            $this->messageManager->addErrorMessage(__('Invalid view record ID.'));
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('*/*/index');
        }

        $viewRecord = $this->viewedFactory->create()->load($id);

        if (!$viewRecord->getId()) {
            $this->messageManager->addErrorMessage(__('View record not found.'));
            $resultRedirect = $this->resultRedirectFactory->create();
            return $resultRedirect->setPath('*/*/index');
        }

        $resultPage = $this->resultPageFactory->create();
        $resultPage->setActiveMenu('Panth_QuickView::view_tracker');
        $resultPage->getConfig()->getTitle()->prepend(__('View Details #%1', $id));

        return $resultPage;
    }
}
