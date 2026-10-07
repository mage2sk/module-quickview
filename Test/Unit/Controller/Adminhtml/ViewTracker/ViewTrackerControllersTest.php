<?php
declare(strict_types=1);

namespace Panth\QuickView\Test\Unit\Controller\Adminhtml\ViewTracker;

use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Page;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Model\Context as ModelContext;
use Magento\Framework\Registry;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\PageFactory;
use Panth\QuickView\Controller\Adminhtml\ViewTracker\Index;
use Panth\QuickView\Controller\Adminhtml\ViewTracker\View;
use Panth\QuickView\Model\RecentlyViewed;
use Panth\QuickView\Model\RecentlyViewedFactory;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed as RecentlyViewedResource;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ViewTrackerControllersTest extends TestCase
{
    /**
     * @var array
     */
    private array $params = [];

    /**
     * @var array
     */
    private array $errors = [];

    /**
     * @var string|null
     */
    private ?string $redirectPath = null;

    /**
     * @var array
     */
    private array $titles = [];

    /**
     * @var array
     */
    private array $activeMenus = [];

    /**
     * @var array
     */
    private array $loadedIds = [];

    /**
     * @var Page|null
     */
    private ?Page $page = null;

    /**
     * @var Redirect|null
     */
    private ?Redirect $redirect = null;

    /**
     * @return Context
     */
    private function context(): Context
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturnCallback(
            fn($key, $default = null) => $this->params[$key] ?? $default
        );

        $this->redirect = $this->createStub(Redirect::class);
        $this->redirect->method('setPath')->willReturnCallback(function ($path) {
            $this->redirectPath = $path;
            return $this->redirect;
        });
        $redirectFactory = $this->createStub(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);

        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->errors[] = (string)$message;
            return $messages;
        });

        $context = $this->createStub(Context::class);
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messages);
        return $context;
    }

    /**
     * @return PageFactory
     */
    private function pageFactory(): PageFactory
    {
        $title = $this->createStub(Title::class);
        $title->method('prepend')->willReturnCallback(function ($text) {
            $this->titles[] = (string)$text;
        });
        $config = $this->createStub(PageConfig::class);
        $config->method('getTitle')->willReturn($title);

        $this->page = $this->createStub(Page::class);
        $this->page->method('getConfig')->willReturn($config);
        $this->page->method('setActiveMenu')->willReturnCallback(function ($menu) {
            $this->activeMenus[] = $menu;
            return $this->page;
        });

        $factory = $this->createStub(PageFactory::class);
        $factory->method('create')->willReturn($this->page);
        return $factory;
    }

    /**
     * @param int|null $existingId
     * @return RecentlyViewedFactory
     */
    private function viewedFactory(?int $existingId): RecentlyViewedFactory
    {
        $resource = $this->createStub(RecentlyViewedResource::class);
        $resource->method('getIdFieldName')->willReturn('view_id');
        $model = $this->getMockBuilder(RecentlyViewed::class)
            ->setConstructorArgs([
                $this->createStub(ModelContext::class),
                $this->createStub(Registry::class),
                $resource,
            ])
            ->onlyMethods(['load'])
            ->getMock();
        $model->method('load')->willReturnCallback(function ($id) use ($model, $existingId) {
            $this->loadedIds[] = $id;
            if ($existingId !== null) {
                $model->setId($existingId);
            }
            return $model;
        });
        $factory = $this->createStub(RecentlyViewedFactory::class);
        $factory->method('create')->willReturn($model);
        return $factory;
    }

    public function testIndexRendersTrackerPage(): void
    {
        $result = (new Index($this->context(), $this->pageFactory()))->execute();

        $this->assertSame($this->page, $result);
        $this->assertSame(['Panth_QuickView::view_tracker'], $this->activeMenus);
        $this->assertSame(['Product View Tracker'], $this->titles);
    }

    public function testViewWithoutIdRedirectsWithError(): void
    {
        $controller = new View($this->context(), $this->pageFactory(), $this->viewedFactory(5));

        $result = $controller->execute();

        $this->assertSame($this->redirect, $result);
        $this->assertSame('*/*/index', $this->redirectPath);
        $this->assertSame(['Invalid view record ID.'], $this->errors);
        $this->assertSame([], $this->loadedIds);
    }

    public function testViewOfUnknownRecordRedirectsWithError(): void
    {
        $this->params = ['id' => '77'];
        $controller = new View($this->context(), $this->pageFactory(), $this->viewedFactory(null));

        $result = $controller->execute();

        $this->assertSame($this->redirect, $result);
        $this->assertSame('*/*/index', $this->redirectPath);
        $this->assertSame(['View record not found.'], $this->errors);
        $this->assertSame(['77'], $this->loadedIds);
    }

    public function testViewOfExistingRecordRendersDetailPage(): void
    {
        $this->params = ['id' => '5'];
        $controller = new View($this->context(), $this->pageFactory(), $this->viewedFactory(5));

        $result = $controller->execute();

        $this->assertSame($this->page, $result);
        $this->assertNull($this->redirectPath);
        $this->assertSame([], $this->errors);
        $this->assertSame(['Panth_QuickView::view_tracker'], $this->activeMenus);
        $this->assertSame(['View Details #5'], $this->titles);
    }

    public function testControllersShareTheTrackerAclResource(): void
    {
        $this->assertSame('Panth_QuickView::view_tracker', Index::ADMIN_RESOURCE);
        $this->assertSame('Panth_QuickView::view_tracker', View::ADMIN_RESOURCE);
    }
}
