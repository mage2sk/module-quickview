<?php
declare(strict_types=1);

namespace Panth\QuickView\Controller\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Catalog\Model\Product\Attribute\Source\Status as ProductStatus;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Checkout\Helper\Cart as CartHelper;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Registry;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;
use Panth\QuickView\Helper\Data as QuickViewHelper;
use Psr\Log\LoggerInterface;

class View implements HttpGetActionInterface
{
    private const IMAGE_WIDTH  = 700;
    private const IMAGE_HEIGHT = 700;
    private const THUMB_WIDTH  = 100;
    private const THUMB_HEIGHT = 100;

    private const SHORT_DESC_MAX_LENGTH = 300;

    private JsonFactory $resultJsonFactory;
    private ProductRepositoryInterface $productRepository;
    private StoreManagerInterface $storeManager;
    private PriceHelper $priceHelper;
    private StockRegistryInterface $stockRegistry;
    private QuickViewHelper $helper;
    private RequestInterface $request;
    private ImageHelper $imageHelper;
    private PageFactory $resultPageFactory;
    private CartHelper $cartHelper;
    private FormKey $formKey;
    private Registry $registry;
    private LoggerInterface $logger;

    public function __construct(
        JsonFactory $resultJsonFactory,
        ProductRepositoryInterface $productRepository,
        StoreManagerInterface $storeManager,
        PriceHelper $priceHelper,
        StockRegistryInterface $stockRegistry,
        QuickViewHelper $helper,
        RequestInterface $request,
        ImageHelper $imageHelper,
        PageFactory $resultPageFactory,
        CartHelper $cartHelper,
        FormKey $formKey,
        Registry $registry,
        LoggerInterface $logger
    ) {
        $this->resultJsonFactory  = $resultJsonFactory;
        $this->productRepository  = $productRepository;
        $this->storeManager       = $storeManager;
        $this->priceHelper        = $priceHelper;
        $this->stockRegistry      = $stockRegistry;
        $this->helper             = $helper;
        $this->request            = $request;
        $this->imageHelper        = $imageHelper;
        $this->resultPageFactory  = $resultPageFactory;
        $this->cartHelper         = $cartHelper;
        $this->formKey            = $formKey;
        $this->registry           = $registry;
        $this->logger             = $logger;
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();

        if (!$this->helper->isEnabled()) {
            return $result->setData([
                'success' => false,
                'message' => __('Quick View is disabled.')
            ]);
        }

        $productId = (int) $this->request->getParam('id');

        if ($productId <= 0) {
            return $result->setData([
                'success' => false,
                'message' => __('Product ID is required.')
            ]);
        }

        try {
            $store = $this->storeManager->getStore();
            $product = $this->productRepository->getById($productId, false, (int) $store->getId());

            if (!$this->isProductViewable($product, (int) $store->getWebsiteId())) {
                return $result->setData([
                    'success' => false,
                    'message' => __('Product not found.')
                ]);
            }

            $typeId = $product->getTypeId();
            $inStock = true;
            $stockQty = 0.0;
            try {
                if ($typeId === 'simple' || $typeId === 'virtual') {
                    $stockItem = $this->stockRegistry->getStockItem($productId);
                    $inStock = (bool)$stockItem->getIsInStock();
                    $stockQty = (float)$stockItem->getQty();
                } else {
                    $inStock = $product->isAvailable();
                }
            } catch (\Exception $e) {
                $inStock = true;
            }

            $finalPrice = $product->getFinalPrice();
            $regularPrice = $product->getPrice();

            $pricePrefix = '';
            $isCompositeType = in_array($typeId, ['configurable', 'grouped', 'bundle'], true);
            if ($isCompositeType) {
                $pricePrefix = '<span style="font-size:13px;color:var(--color-fg-secondary,#525252);font-weight:400;">'
                    . htmlspecialchars((string) __('As low as'), ENT_QUOTES) . ' </span>';

                $minPrice = $this->getCompositeMinPrice($product, $typeId);
                $finalPrice = $minPrice !== null ? $minPrice : $finalPrice;
            }

            $priceHtml = $pricePrefix . '<span class="price" style="font-size:24px;font-weight:700;color:var(--color-fg,#171717);">'
                . $this->priceHelper->currency($finalPrice, true, false)
                . '</span>';

            if ($regularPrice > $finalPrice && $finalPrice > 0 && !$isCompositeType) {
                $priceHtml = '<span style="text-decoration:line-through;color:var(--color-fg-secondary,#525252);font-size:14px;margin-right:8px;">'
                    . $this->priceHelper->currency($regularPrice, true, false)
                    . '</span>' . $priceHtml;
            }

            $data = [
                'success' => true,
                'product' => [
                    'id'                => (int)$product->getId(),
                    'name'              => $product->getName(),
                    'sku'               => $product->getSku(),
                    'type_id'           => $typeId,
                    'price_html'        => $priceHtml,
                    'short_description' => $this->getTruncatedShortDescription($product),
                    'images'            => $this->getProductImages($product),
                    'in_stock'          => $inStock,
                    'stock_qty'         => $stockQty,
                    'url'               => $product->getProductUrl(),
                    'form_key'          => $this->formKey->getFormKey(),
                ],
                'options_html' => '',
            ];

            return $result->setData($data);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return $result->setData([
                'success' => false,
                'message' => __('Product not found.')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('QuickView controller error: ' . $e->getMessage(), [
                'product_id' => $productId,
                'trace'      => $e->getTraceAsString(),
            ]);
            return $result->setData([
                'success' => false,
                'message' => __('Unable to load product data. Please try again.')
            ]);
        }
    }

    private function isProductViewable($product, int $websiteId): bool
    {
        if ((int) $product->getStatus() !== ProductStatus::STATUS_ENABLED) {
            return false;
        }

        if (!$product->isVisibleInSiteVisibility()) {
            return false;
        }

        $websiteIds = array_map('intval', (array) $product->getWebsiteIds());

        return in_array($websiteId, $websiteIds, true);
    }

    private function getCompositeMinPrice($product, string $typeId): ?float
    {
        $children = [];
        if ($typeId === 'configurable') {
            $children = $product->getTypeInstance()->getUsedProducts($product);
        } elseif ($typeId === 'grouped') {
            $children = $product->getTypeInstance()->getAssociatedProducts($product);
        } elseif ($typeId === 'bundle') {
            $bundleMin = (float) $product->getPriceModel()->getTotalPrices($product, 'min');
            return $bundleMin > 0 ? $bundleMin : null;
        }

        $minPrice = null;
        foreach ($children as $child) {
            if ($child->isDisabled()) {
                continue;
            }
            $childPrice = (float) $child->getFinalPrice();
            if ($minPrice === null || $childPrice < $minPrice) {
                $minPrice = $childPrice;
            }
        }

        return $minPrice;
    }

    private function getProductImages($product): array
    {
        $images = [];

        $mediaGallery = $product->getMediaGalleryImages();

        if ($mediaGallery && $mediaGallery->getSize()) {
            foreach ($mediaGallery as $image) {
                $mainUrl = $this->imageHelper
                    ->init($product, 'product_page_image_large')
                    ->setImageFile($image->getFile())
                    ->resize(self::IMAGE_WIDTH, self::IMAGE_HEIGHT)
                    ->getUrl();

                $thumbUrl = $this->imageHelper
                    ->init($product, 'product_page_image_small')
                    ->setImageFile($image->getFile())
                    ->resize(self::THUMB_WIDTH, self::THUMB_HEIGHT)
                    ->getUrl();

                $images[] = [
                    'url'       => $mainUrl,
                    'thumb_url' => $thumbUrl,
                    'label'     => $image->getLabel() ?: $product->getName(),
                    'position'  => (int) $image->getPosition(),
                ];
            }
        }

        if (empty($images)) {
            $placeholderUrl = $this->imageHelper
                ->init($product, 'product_page_image_large')
                ->resize(self::IMAGE_WIDTH, self::IMAGE_HEIGHT)
                ->getUrl();

            $images[] = [
                'url'       => $placeholderUrl,
                'thumb_url' => $placeholderUrl,
                'label'     => $product->getName(),
                'position'  => 0,
            ];
        }

        return $images;
    }

    private function getTruncatedShortDescription($product): string
    {
        $shortDesc = $product->getShortDescription()
            ?: $product->getData('short_description')
            ?: $product->getDescription()
            ?: $product->getData('description')
            ?: '';

        if (!$shortDesc) {
            return '';
        }

        $plain = html_entity_decode(strip_tags((string) $shortDesc), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(preg_replace('/\s+/', ' ', $plain));

        if (mb_strlen($plain) > self::SHORT_DESC_MAX_LENGTH) {
            $plain = mb_substr($plain, 0, self::SHORT_DESC_MAX_LENGTH);

            $lastSpace = mb_strrpos($plain, ' ');
            if ($lastSpace !== false) {
                $plain = mb_substr($plain, 0, $lastSpace);
                $tailSpace = mb_strrpos($plain, ' ');
                if ($tailSpace !== false && mb_strlen($plain) - $tailSpace <= 3) {
                    $plain = mb_substr($plain, 0, $tailSpace);
                }
            }
            $plain = rtrim($plain, " .,;:!?-") . '...';
        }

        return $plain;
    }
}
