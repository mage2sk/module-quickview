<?php
declare(strict_types=1);

namespace Panth\QuickView\Controller\Track;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey\Validator as FormKeyValidator;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Store\Model\StoreManagerInterface;
use Panth\QuickView\Model\RecentlyViewedFactory;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed as RecentlyViewedResource;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed\CollectionFactory;
use Psr\Log\LoggerInterface;

class View implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public const RATE_LIMIT_SECONDS = 300;

    private const RATE_LIMIT_CACHE_PREFIX = 'panth_quickview_track_';

    private RequestInterface $request;
    private JsonFactory $jsonFactory;
    private RecentlyViewedFactory $recentlyViewedFactory;
    private RecentlyViewedResource $recentlyViewedResource;
    private CollectionFactory $collectionFactory;
    private CustomerSession $customerSession;
    private StoreManagerInterface $storeManager;
    private LoggerInterface $logger;
    private CacheInterface $cache;
    private RemoteAddress $remoteAddress;
    private FormKeyValidator $formKeyValidator;

    public function __construct(
        RequestInterface $request,
        JsonFactory $jsonFactory,
        RecentlyViewedFactory $recentlyViewedFactory,
        RecentlyViewedResource $recentlyViewedResource,
        CollectionFactory $collectionFactory,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        CacheInterface $cache,
        RemoteAddress $remoteAddress,
        FormKeyValidator $formKeyValidator
    ) {
        $this->request = $request;
        $this->jsonFactory = $jsonFactory;
        $this->recentlyViewedFactory = $recentlyViewedFactory;
        $this->recentlyViewedResource = $recentlyViewedResource;
        $this->collectionFactory = $collectionFactory;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->remoteAddress = $remoteAddress;
        $this->formKeyValidator = $formKeyValidator;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        $result = $this->jsonFactory->create();
        $result->setHttpResponseCode(403);
        $result->setData(['success' => false, 'message' => 'Invalid form key.']);

        return new InvalidRequestException($result);
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        if (!$request instanceof HttpRequest || !$request->isPost()) {
            return true;
        }

        return $this->formKeyValidator->validate($request);
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            $productId = (int) $this->request->getParam('product_id', 0);
            if ($productId <= 0) {
                return $result->setData(['success' => false, 'message' => 'Product ID required.']);
            }

            $storeId = (int)$this->storeManager->getStore()->getId();
            $ip = $this->getClientIp();
            $rateKey = self::RATE_LIMIT_CACHE_PREFIX . hash('sha256', $ip . '|' . $storeId . '|' . $productId);
            if ($this->cache->load($rateKey) !== false) {
                return $result->setData(['success' => true, 'action' => 'skipped']);
            }
            $customerId = $this->customerSession->isLoggedIn() ? (int)$this->customerSession->getCustomerId() : null;
            $visitorId = $this->getVisitorId($ip);

            $collection = $this->collectionFactory->create();
            $collection->addFieldToFilter('product_id', $productId);
            $collection->addFieldToFilter('store_id', $storeId);

            if ($customerId) {
                $collection->addFieldToFilter('customer_id', $customerId);
            } else {
                $collection->addFieldToFilter('visitor_id', $visitorId);
            }

            $collection->addFieldToFilter('viewed_at', ['gteq' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);
            $collection->setPageSize(1);

            if ($collection->getSize() > 0) {
                $existing = $collection->getFirstItem();
                $existing->setData('viewed_at', date('Y-m-d H:i:s'));
                $this->recentlyViewedResource->save($existing);
                $this->cache->save('1', $rateKey, [], self::RATE_LIMIT_SECONDS);
                return $result->setData(['success' => true, 'action' => 'updated']);
            }

            $view = $this->recentlyViewedFactory->create();
            $view->setData([
                'product_id' => $productId,
                'customer_id' => $customerId,
                'visitor_id' => $visitorId,
                'store_id' => $storeId,
                'viewed_at' => date('Y-m-d H:i:s'),
            ]);
            $this->recentlyViewedResource->save($view);
            $this->cache->save('1', $rateKey, [], self::RATE_LIMIT_SECONDS);

            return $result->setData(['success' => true, 'action' => 'created']);
        } catch (\Exception $e) {
            $this->logger->error('QuickView Track error: ' . $e->getMessage());
            return $result->setData(['success' => false, 'message' => 'Tracking failed.']);
        }
    }

    private function getClientIp(): string
    {
        $ip = (string) $this->remoteAddress->getRemoteAddress();

        return $ip !== '' ? $ip : '0.0.0.0';
    }

    private function getVisitorId(string $ip): string
    {
        $ua = (string) ($this->request->getServer('HTTP_USER_AGENT') ?? '');
        return hash('sha256', $ip . '|' . $ua);
    }
}
