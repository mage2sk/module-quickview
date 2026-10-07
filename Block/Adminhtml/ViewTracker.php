<?php
declare(strict_types=1);

namespace Panth\QuickView\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\ProductFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\QuickView\Model\RecentlyViewedFactory;
use Panth\QuickView\Model\ResourceModel\RecentlyViewed\CollectionFactory as ViewedCollectionFactory;

class ViewTracker extends Template
{
    private ViewedCollectionFactory $viewedCollectionFactory;

    private RecentlyViewedFactory $viewedFactory;

    private ProductFactory $productFactory;

    private CustomerFactory $customerFactory;

    private TimezoneInterface $timezone;

    private ImageHelper $imageHelper;

    private PriceHelper $priceHelper;

    public function __construct(
        Context $context,
        ViewedCollectionFactory $viewedCollectionFactory,
        RecentlyViewedFactory $viewedFactory,
        ProductFactory $productFactory,
        CustomerFactory $customerFactory,
        TimezoneInterface $timezone,
        ImageHelper $imageHelper,
        PriceHelper $priceHelper,
        array $data = []
    ) {
        $this->viewedCollectionFactory = $viewedCollectionFactory;
        $this->viewedFactory = $viewedFactory;
        $this->productFactory = $productFactory;
        $this->customerFactory = $customerFactory;
        $this->timezone = $timezone;
        $this->imageHelper = $imageHelper;
        $this->priceHelper = $priceHelper;
        parent::__construct($context, $data);
    }

    public function getViewStats(): array
    {
        $collection = $this->viewedCollectionFactory->create();

        $total = $collection->getSize();

        $now = $this->getLocalNow();

        $today = $now->format('Y-m-d');
        $todayViews = $this->viewedCollectionFactory->create()
            ->addFieldToFilter('viewed_at', ['gteq' => $this->localToUtc($today . ' 00:00:00')])
            ->getSize();

        $weekStart = $now->modify('monday this week')->format('Y-m-d');
        $weekViews = $this->viewedCollectionFactory->create()
            ->addFieldToFilter('viewed_at', ['gteq' => $this->localToUtc($weekStart . ' 00:00:00')])
            ->getSize();

        $monthStart = $now->format('Y-m-01');
        $monthViews = $this->viewedCollectionFactory->create()
            ->addFieldToFilter('viewed_at', ['gteq' => $this->localToUtc($monthStart . ' 00:00:00')])
            ->getSize();

        $uniqueCustomers = $this->viewedCollectionFactory->create()
            ->addFieldToFilter('customer_id', ['notnull' => true])
            ->getSelect()
            ->group('customer_id');
        $uniqueCustomersCount = count($uniqueCustomers->query()->fetchAll());

        $uniqueVisitors = $this->viewedCollectionFactory->create()
            ->addFieldToFilter('visitor_id', ['notnull' => true])
            ->addFieldToFilter('customer_id', ['null' => true])
            ->getSelect()
            ->group('visitor_id');
        $uniqueVisitorsCount = count($uniqueVisitors->query()->fetchAll());

        $totalUnique = $uniqueCustomersCount + $uniqueVisitorsCount;

        return [
            'total' => $total,
            'today' => $todayViews,
            'week' => $weekViews,
            'month' => $monthViews,
            'unique_visitors' => $totalUnique
        ];
    }

    public function getMostViewedProducts(int $limit = 10): array
    {
        $collection = $this->viewedCollectionFactory->create();
        $collection->getSelect()
            ->columns(['view_count' => 'COUNT(*)'])
            ->group('product_id')
            ->order('view_count DESC')
            ->limit($limit);

        $products = [];
        foreach ($collection as $view) {
            try {
                $product = $this->productFactory->create()->load($view->getProductId());
                if ($product->getId()) {
                    $products[] = [
                        'name' => $product->getName(),
                        'sku' => $product->getSku(),
                        'count' => $view->getData('view_count'),
                        'product_id' => $view->getProductId(),
                        'url' => $this->getUrl('catalog/product/edit', ['id' => $view->getProductId()])
                    ];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $products;
    }

    public function getRecentViews(int $limit = 20): array
    {
        $collection = $this->viewedCollectionFactory->create()
            ->setOrder('viewed_at', 'DESC')
            ->setPageSize($limit);

        $recentViews = [];
        foreach ($collection as $view) {
            try {
                $product = $this->productFactory->create()->load($view->getProductId());
                $viewerName = 'Guest';

                if ($view->getCustomerId()) {
                    $customer = $this->customerFactory->create()->load($view->getCustomerId());
                    $viewerName = $customer->getName();
                } elseif ($view->getVisitorId()) {
                    $viewerName = 'Visitor #' . substr((string)$view->getVisitorId(), 0, 8);
                }

                $recentViews[] = [
                    'view_id' => $view->getId(),
                    'product_name' => $product->getName() ?: 'Unknown Product',
                    'product_sku' => $product->getSku(),
                    'viewer_name' => $viewerName,
                    'viewed_at' => $view->getViewedAt(),
                    'time_ago' => $this->getTimeAgo($view->getViewedAt()),
                    'product_url' => $this->getUrl('catalog/product/edit', ['id' => $view->getProductId()])
                ];
            } catch (\Exception $e) {
                continue;
            }
        }

        return $recentViews;
    }

    public function getViewTrendData(): array
    {
        $days = [];
        $viewData = [];
        $uniqueData = [];

        $now = $this->getLocalNow();

        for ($i = 29; $i >= 0; $i--) {
            $day = $now->modify("-{$i} days");
            $date = $day->format('Y-m-d');
            $days[] = $day->format('M d');
            $from = $this->localToUtc($date . ' 00:00:00');
            $to = $this->localToUtc($date . ' 23:59:59');

            $viewCount = $this->viewedCollectionFactory->create()
                ->addFieldToFilter('viewed_at', ['gteq' => $from])
                ->addFieldToFilter('viewed_at', ['lteq' => $to])
                ->getSize();
            $viewData[] = $viewCount;

            $uniqueProducts = $this->viewedCollectionFactory->create()
                ->addFieldToFilter('viewed_at', ['gteq' => $from])
                ->addFieldToFilter('viewed_at', ['lteq' => $to]);
            $uniqueProducts->getSelect()->group('product_id');
            $uniqueCount = count($uniqueProducts->getColumnValues('product_id'));
            $uniqueData[] = $uniqueCount;
        }

        return [
            'labels' => $days,
            'view_data' => $viewData,
            'unique_data' => $uniqueData
        ];
    }

    public function getHourlyDistribution(): array
    {
        $hours = [];
        $viewCounts = [];
        $today = $this->getLocalNow()->format('Y-m-d');

        for ($h = 0; $h < 24; $h++) {
            $hour = str_pad((string)$h, 2, '0', STR_PAD_LEFT);
            $hours[] = $hour . ':00';

            $count = $this->viewedCollectionFactory->create()
                ->addFieldToFilter('viewed_at', ['gteq' => $this->localToUtc($today . ' ' . $hour . ':00:00')])
                ->addFieldToFilter('viewed_at', ['lteq' => $this->localToUtc($today . ' ' . $hour . ':59:59')])
                ->getSize();
            $viewCounts[] = $count;
        }

        return [
            'labels' => $hours,
            'data' => $viewCounts
        ];
    }

    public function getTopCustomers(int $limit = 10): array
    {
        $collection = $this->viewedCollectionFactory->create();
        $collection->addFieldToFilter('customer_id', ['notnull' => true]);
        $collection->getSelect()
            ->columns(['view_count' => 'COUNT(*)'])
            ->group('customer_id')
            ->order('view_count DESC')
            ->limit($limit);

        $customers = [];
        foreach ($collection as $view) {
            try {
                $customer = $this->customerFactory->create()->load($view->getCustomerId());
                if ($customer->getId()) {
                    $customers[] = [
                        'name' => $customer->getName(),
                        'email' => $customer->getEmail(),
                        'count' => $view->getData('view_count'),
                        'customer_id' => $view->getCustomerId(),
                        'url' => $this->getUrl('customer/index/edit', ['id' => $view->getCustomerId()])
                    ];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return $customers;
    }

    public function getTimeAgo(?string $datetime): string
    {
        if (!$datetime) {
            return 'Unknown';
        }

        try {
            $time = (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Exception $e) {
            return 'Unknown';
        }
        $diff = time() - $time;

        if ($diff < 60) {
            return 'Just now';
        } elseif ($diff < 3600) {
            $mins = (int)floor($diff / 60);
            return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = (int)floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = (int)floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } else {
            return $this->formatViewedAt($datetime, 'M d, Y H:i');
        }
    }

    public function formatViewedAt(?string $datetime, string $format = 'M d, Y H:i:s'): string
    {
        if (!$datetime) {
            return '';
        }

        try {
            $utc = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            return $datetime;
        }

        return $utc->setTimezone($this->getStoreTimezone())->format($format);
    }

    private function getStoreTimezone(): \DateTimeZone
    {
        try {
            $name = (string) $this->timezone->getConfigTimezone();
            return new \DateTimeZone($name !== '' ? $name : 'UTC');
        } catch (\Exception $e) {
            return new \DateTimeZone('UTC');
        }
    }

    private function getLocalNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->getStoreTimezone());
    }

    private function localToUtc(string $localDateTime): string
    {
        $local = new \DateTimeImmutable($localDateTime, $this->getStoreTimezone());

        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function getDashboardUrl(): string
    {
        return $this->getUrl('quickview/viewtracker/index');
    }

    public function getView($id)
    {
        if (!$id) {
            return null;
        }

        try {
            $viewRecord = $this->viewedFactory->create()->load($id);
            return $viewRecord->getId() ? $viewRecord : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getProductInfo($productId): ?array
    {
        if (!$productId) {
            return null;
        }

        try {
            $product = $this->productFactory->create()->load($productId);
            if (!$product->getId()) {
                return null;
            }

            $productImage = $this->imageHelper->init($product, 'product_listing_thumbnail_preview')->getUrl();

            $finalPrice = $product->getFinalPrice();
            $formattedPrice = $this->priceHelper->currency($finalPrice, true, false);

            return [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'sku' => $product->getSku(),
                'price' => $formattedPrice,
                'image' => $productImage,
                'url' => $product->getProductUrl(),
                'admin_url' => $this->getUrl('catalog/product/edit', ['id' => $product->getId()]),
                'type' => $product->getTypeId(),
                'status' => $product->getStatus(),
                'visibility' => $product->getVisibility()
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getCustomerInfo($customerId): ?array
    {
        if (!$customerId) {
            return null;
        }

        try {
            $customer = $this->customerFactory->create()->load($customerId);
            if (!$customer->getId()) {
                return null;
            }

            return [
                'id' => $customer->getId(),
                'name' => $customer->getName(),
                'email' => $customer->getEmail(),
                'group_id' => $customer->getGroupId(),
                'created_at' => $customer->getCreatedAt(),
                'admin_url' => $this->getUrl('customer/index/edit', ['id' => $customer->getId()])
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getProductViewHistory(int $productId, int $limit = 10): array
    {
        $collection = $this->viewedCollectionFactory->create()
            ->addFieldToFilter('product_id', $productId)
            ->setOrder('viewed_at', 'DESC')
            ->setPageSize($limit);

        $history = [];
        foreach ($collection as $view) {
            $viewerName = 'Guest';
            if ($view->getCustomerId()) {
                $customer = $this->customerFactory->create()->load($view->getCustomerId());
                $viewerName = $customer->getName();
            } elseif ($view->getVisitorId()) {
                $viewerName = 'Visitor #' . substr((string)$view->getVisitorId(), 0, 8);
            }

            $history[] = [
                'id' => $view->getId(),
                'viewer_name' => $viewerName,
                'customer_id' => $view->getCustomerId(),
                'visitor_id' => $view->getVisitorId(),
                'viewed_at' => $view->getViewedAt(),
                'time_ago' => $this->getTimeAgo($view->getViewedAt()),
                'detail_url' => $this->getUrl('quickview/viewtracker/view', ['id' => $view->getId()])
            ];
        }

        return $history;
    }

    public function getCustomerViewHistory(int $customerId, int $limit = 10): array
    {
        $collection = $this->viewedCollectionFactory->create()
            ->addFieldToFilter('customer_id', $customerId)
            ->setOrder('viewed_at', 'DESC')
            ->setPageSize($limit);

        $history = [];
        foreach ($collection as $view) {
            $product = $this->productFactory->create()->load($view->getProductId());

            $history[] = [
                'id' => $view->getId(),
                'product_name' => $product->getName() ?: 'Unknown Product',
                'product_sku' => $product->getSku(),
                'viewed_at' => $view->getViewedAt(),
                'time_ago' => $this->getTimeAgo($view->getViewedAt()),
                'detail_url' => $this->getUrl('quickview/viewtracker/view', ['id' => $view->getId()])
            ];
        }

        return $history;
    }
}
