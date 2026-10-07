# Magento 2 Quick View

Panth Quick View adds a "Quick View" button to product cards on category and search result pages. Clicking it opens a modal that loads the product's images, price, SKU, stock status and short description over AJAX from a JSON controller, so shoppers can look at a product and add simple or virtual items to the cart without leaving the listing. The module also records product page views in its own table and shows them in an admin "View Tracker" report with charts, most viewed products, top customers and a per-view detail page.

It ships two storefront implementations: an Alpine.js modal for Hyva themes and a vanilla JavaScript modal for Luma. The correct template is selected through the `default_hyva` layout handle, so no theme-specific configuration is needed.

Product page: [kishansavaliya.com/magento-2-quickview.html](https://kishansavaliya.com/magento-2-quickview.html)

## Features

- "Quick View" button on product cards on category, search result and advanced search result pages (Hyva: layout child block; Luma: injected next to the wishlist and compare icons).
- Modal loads product data as JSON from `quickview/product/view` without a page reload.
- Modal shows the product name, SKU, price, "In Stock" / "Out of Stock" badge, a plain-text short description (up to 300 characters) and an image gallery with thumbnails.
- Regular price is shown struck through when the final price is lower; configurable products show an "As low as" price taken from the cheapest child.
- Quantity selector and "Add to Cart" button for in-stock simple and virtual products; the cart is added to via the standard `checkout/cart/add` action and the mini cart is refreshed. When the store rejects the item (for example a quantity above the allowed maximum) the store's error message is shown in the modal instead of the "Added!" state.
- "Select Options" link to the product page for all other product types, and a "View Full Details" link for every product.
- Modal closes on the close button, on clicking the dimmed area around it, or with the Escape key. It is marked up as a dialog named after the product; keyboard focus moves to the close button when it opens, Tab stays inside the modal and focus returns to the Quick View button when it closes.
- Product page view tracking: each product detail page view is posted to `quickview/track/view` and stored in `panth_recently_viewed` for logged-in customers and guests.
- Admin "View Tracker" dashboard: total, today, this week, this month and unique visitor counts, a 30-day trend chart, today's hourly distribution chart, top customers, most viewed products and a recent views feed.
- Admin view detail page: product information, view metadata, viewer information, the product's view history and, for logged-in viewers, the customer's recent views.
- Dedicated ACL resources for the report and the configuration section.

## Compatibility

| Platform | Versions |
|---|---|
| Magento Open Source | 2.4.4 to 2.4.8 (as published on the product page) |
| Adobe Commerce | 2.4.4 to 2.4.8 (as published on the product page) |
| PHP | `^8.1` (from `composer.json`) |
| Themes | Hyva (Alpine.js template) and Luma (vanilla JavaScript template) |

Composer constraints on Magento packages: `magento/framework ^103.0`, `magento/module-catalog ^104.0`, `magento/module-customer ^103.0`, `magento/module-store ^101.0`, `magento/module-backend ^102.0`, `magento/module-config ^101.0`, `magento/module-catalog-inventory ^100.0`, `magento/module-checkout ^100.4`, `magento/module-review ^100.0`.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.4 to 2.4.8.
- PHP 8.1 or later (`"php": "^8.1"`).
- `mage2kishan/module-core` `^1.0` (module `Panth_Core`), a required Composer dependency. It provides the "Panth Extensions" admin menu that this module's menu items attach to.
- `Magento_Checkout` must be enabled: the quick view controller injects `Magento\Checkout\Helper\Cart` and the modal posts to `checkout/cart/add`.
- Suggested: `hyva-themes/magento2-default-theme` for the Alpine.js storefront template. The Hyva button template uses `Hyva\Theme\ViewModel\LucideIcons`.
- The admin View Tracker charts use the Chart.js library that ships with Magento (RequireJS alias `chartJs`, `lib/web/chartjs/Chart.min.js`). No external stylesheets or scripts are loaded.

## Installation

```bash
composer require mage2kishan/module-quickview
bin/magento module:enable Panth_Core Panth_QuickView
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

`setup:di:compile` is only needed in production mode. `setup:static-content:deploy` is required because the module ships an admin stylesheet under `view/adminhtml/web/css/`.

Check the result with:

```bash
bin/magento module:status Panth_QuickView
```

## Configuration

Admin path: Stores > Configuration > Panth Extensions > Quick View. The section can be set at default, website and store view scope. Access requires the `Panth_QuickView::config` ACL resource.

### General Settings

| Setting | Default | What it does |
|---|---|---|
| Enable Quick View | Yes | Master switch. When set to No, the Quick View buttons, the modal and its JavaScript are not rendered on the storefront (Hyva and Luma) and `quickview/product/view` answers `{"success": false, "message": "Quick View is disabled."}`. |

### Display Settings

| Setting | Default | What it does |
|---|---|---|
| Show Product Image Gallery | Yes | When No, the modal shows only the main image without the thumbnail strip. |
| Show Short Description | Yes | When No, the short description is not shown in the modal. |
| Show SKU | Yes | When No, the SKU line is not shown in the modal. |
| Show Stock Status | Yes | When No, the "In Stock" / "Out of Stock" badge is not shown. |
| Show Add to Cart | Yes | When No, the quantity field and "Add to Cart" button are not shown for simple and virtual products. |

Both the Hyva and the Luma modal templates read these values. The modal block is part of every storefront page, so refresh the full page cache after changing them. The values are also available through the matching `Helper\Data` methods for custom templates.

Config paths:

- `panth_quickview/general/enabled`
- `panth_quickview/display/show_image_gallery`
- `panth_quickview/display/show_short_description`
- `panth_quickview/display/show_sku`
- `panth_quickview/display/show_stock_status`
- `panth_quickview/display/show_add_to_cart`

Default behaviour after installation: Quick View is enabled and all six values are Yes. Product page view tracking is not controlled by any setting and runs whenever the module is enabled in `app/etc/config.php`.

## Usage

### Where the button appears

- Hyva: `view/frontend/layout/catalog_list_item.xml` adds a `quickview` child block (`Panth_QuickView::product/list/buttons.phtml`) to `product_list_item`, positioned after the wishlist button. The button is a round icon button using the Lucide "eye" icon and dispatches the `open-quick-view` window event with the product ID. The module also ships `view/frontend/templates/Magento_Catalog/product/list/item.phtml`, a copy of the Hyva product list item template that renders the `quickview` child; if your theme overrides `Magento_Catalog::product/list/item.phtml`, make sure it outputs `$block->getChildBlock('quickview')` for the button to show.
- Luma: the script in `luma/quick-view-modal.phtml` scans every `.product-item` 0.5 and 2 seconds after `DOMContentLoaded`, reads the product ID from the add-to-cart form or the wishlist `data-post` attribute, and appends a "Quick View" eye-icon button to the card's `.actions-secondary` area.

The modal block itself (`quickview.modal`) is added to `after.body.start` on every storefront page by `default.xml`, with the Luma template; `default_hyva.xml` switches it to the Alpine.js template on Hyva themes.

### What the modal shows

The modal sends `GET /quickview/product/view?id=<product id>` with an `X-Requested-With: XMLHttpRequest` header and receives JSON with the product's `name`, `sku`, `type_id`, `price_html`, `short_description`, `images`, `in_stock`, `url` and a `form_key`. The product is loaded for the current store view and is only returned when it is enabled, visible in the catalog or search, and assigned to the current website; any other ID answers `{"success": false, "message": "Product not found."}`. The response is sent with no-cache headers.

- Images: every media gallery image, resized to 700x700 for the main image and 100x100 for thumbnails; the placeholder image is used when the product has no gallery. Thumbnails are hidden on screens narrower than 768px.
- Price: final price formatted in the store currency. The regular price is shown struck through when it is higher than the final price. Configurable and grouped products show the lowest final price of their enabled child products, and bundle products show the bundle minimum price, each with an "As low as" prefix.
- Stock: simple and virtual products use the stock registry; other types use `$product->isAvailable()`.
- Short description: HTML is stripped, HTML entities decoded, whitespace collapsed and the text cut at 300 characters. If the product has no short description the description is used. Both modals insert it as plain text.
- Add to cart: shown only for in-stock simple and virtual products, with a quantity field (1 to 99). It posts `product`, `qty` and `form_key` to the store's `checkout/cart/add` URL. On Hyva the `reload-customer-section-data` event is dispatched; on Luma the `cart` and `messages` customer data sections are reloaded through `Magento_Customer/js/customer-data`, and a failed request shows "Error - Try Again" on the button. The modal closes about 1.2 seconds after a successful add.
- Configurable, bundle, grouped, downloadable and other types: the modal shows "This product has options. Please visit the product page to select." and a "Select Options" link to the product page. Product options are not rendered inside the modal.
- Out-of-stock products show the "Out of Stock" badge with no add to cart form or options link.

### Product view tracking

`catalog_product_view.xml` adds `Panth_QuickView::product-view-tracker.phtml` to `before.body.end`. About 500 ms after the page `load` event it posts `product_id` and the storefront form key (read with `hyva.getFormKey()` on Hyva, or from the `form_key` cookie) as a form-encoded POST to `/quickview/track/view`. The endpoint answers GET requests with 404 and POST requests without a valid form key with HTTP 403. Each IP address is counted at most once per product and store view every five minutes (`Controller\Track\View::RATE_LIMIT_SECONDS`, kept in the Magento cache); further requests in that window answer `{"success":true,"action":"skipped"}` without writing. The controller stores the product ID, store ID, the customer ID for logged-in customers or a visitor ID (SHA-256 hash of the IP address and user agent) for guests, and the time. If the same viewer already has a row for that product and store from the last five minutes, its `viewed_at` is updated instead of inserting a new row. Quick view modal opens are not recorded; only product detail page views are.

### Admin

The admin menu group "Quick View" is added under the `Panth_Core::panth_extensions` menu with two items: "Configuration" (opens the configuration section) and "View Tracker" (`quickview/viewtracker/index`, ACL `Panth_QuickView::view_tracker`).

Views are stored in UTC; the View Tracker pages convert day, week, month and hour boundaries and every shown date to the store timezone (General > Locale Options > Timezone). The View Tracker page ("Product View Tracker") shows stat cards for Total Views, Today's Views, This Week, This Month and Unique Visitors (all five in one row from 1280 px wide, three per row below that, two on narrow screens); a "30-Day View Trends" line chart (views and distinct products per day); a "Today's Hourly Distribution" bar chart; "Top Customers" (logged-in customers by view count, linked to the customer edit page); "Most Viewed Products" (linked to the product edit page); and "Recent Views" (latest 20 rows with viewer name and relative time). Each recent view links to `quickview/viewtracker/view/id/<view_id>`, which shows Product Information, View Details, Viewer Information, Product View History (last 10 views of that product) and, when the viewer was a customer, Customer's Recent Views.

Dates in the report are computed with PHP `date()` in the server's default timezone.

### Templates that can be overridden

Copy any of these into your theme under `Panth_QuickView/templates/` to customise them:

- `quick-view-modal.phtml` (Hyva modal, Alpine.js)
- `luma/quick-view-modal.phtml` (Luma modal and button injection script)
- `product/list/buttons.phtml` (Hyva product card button)
- `product-view-tracker.phtml` (product page tracking script)
- `Magento_Catalog/product/list/item.phtml` (Hyva product list item with the `quickview` child)

Admin templates: `viewtracker.phtml` and `viewtracker/view.phtml` under `view/adminhtml/templates/`, styled by `view/adminhtml/web/css/admin-styles.css`.

## Developer Notes

- Module name: `Panth_QuickView`
- Composer package: `mage2kishan/module-quickview` (version 1.0.11)
- PHP namespace: `Panth\QuickView`
- Module sequence: `Panth_Core`, `Magento_Catalog`, `Magento_Customer`, `Magento_Store`
- Frontend route: `quickview` (`etc/frontend/routes.xml`); admin route: `quickview` (`etc/adminhtml/routes.xml`)

Key classes:

- `Controller\Product\View` (`quickview/product/view`, GET, returns JSON): builds the modal payload. Public method: `execute()`.
- `Controller\Track\View` (`quickview/track/view`, POST JSON, form key required, HTTP 403 without a valid one): records product page views. Public methods: `execute()`, `validateForCsrf()`, `createCsrfValidationException()`.
- `Controller\Adminhtml\ViewTracker\Index` and `Controller\Adminhtml\ViewTracker\View`: admin report pages, `ADMIN_RESOURCE = Panth_QuickView::view_tracker`.
- `Block\QuickView`: `isEnabled()`, `getQuickViewUrl()`, `getHelper()`.
- `Block\ProductViewTracker`: `getCurrentProduct()`, `getFormattedPrice()`, `getProductImageUrl()`.
- `Block\Adminhtml\ViewTracker`: `getViewStats()`, `getMostViewedProducts()`, `getRecentViews()`, `getViewTrendData()`, `getHourlyDistribution()`, `getTopCustomers()`, `getTimeAgo()`, `getView()`, `getProductInfo()`, `getCustomerInfo()`, `getProductViewHistory()`, `getCustomerViewHistory()`.
- `Helper\Data`: `isEnabled()`, `showImageGallery()`, `showShortDescription()`, `showSku()`, `showStockStatus()`, `showAddToCart()`, all accepting an optional store ID.
- `Model\RecentlyViewed`, `Model\ResourceModel\RecentlyViewed`, `Model\ResourceModel\RecentlyViewed\Collection`: ORM for `panth_recently_viewed` (event prefix `panth_recently_viewed`, cache tag `panth_recently_viewed`).

JavaScript integration points:

- Dispatch `window.dispatchEvent(new CustomEvent('open-quick-view', {detail: {productId: 123}}))` from any template to open the modal for a product. On Luma, an element with a `data-quickview-id` attribute also opens the modal when clicked.
- The Hyva modal is the Alpine component `quickViewModal()`.

`etc/di.xml` is empty; the module declares no plugins, preferences, observers, cron jobs, web APIs or console commands.

ACL resources (`etc/acl.xml`):

- `Panth_QuickView::quickview` ("Quick View & Compare")
  - `Panth_QuickView::view_tracker` ("View Tracker")
  - `Panth_QuickView::comparison` ("Product Comparison")
  - `Panth_QuickView::config` ("Configuration")

Database tables (`etc/db_schema.xml`):

- `panth_recently_viewed`: `view_id`, `customer_id`, `visitor_id`, `product_id` (foreign key to `catalog_product_entity.entity_id`, cascade delete), `store_id`, `viewed_at`.
- `panth_product_comparison`: `comparison_id`, `customer_id`, `session_id`, `product_ids`, `created_at`, `updated_at`. This table is created but no code in this version reads or writes it.

Files shipped but not referenced by any layout in this version: `view/frontend/templates/login-modal.phtml`, `notification-toast.phtml`, `product-list-button.phtml`, `product/list/quickview-button.phtml`, and `Model\Config\Backend\Enabled` (not attached to any system.xml field).

## Uninstallation

```bash
bin/magento module:disable Panth_QuickView
composer remove mage2kishan/module-quickview
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

The module has no uninstall script. The `panth_recently_viewed` and `panth_product_comparison` tables and any `panth_quickview/*` rows in `core_config_data` remain in the database and must be dropped or deleted manually if you no longer want them. `mage2kishan/module-core` (`Panth_Core`) is left installed because other Panth modules may depend on it.

## Support

- Product page: [kishansavaliya.com/magento-2-quickview.html](https://kishansavaliya.com/magento-2-quickview.html)
- Contact form: [kishansavaliya.com/contact](https://kishansavaliya.com/contact)
- Email: kishansavaliyakb@gmail.com
- GitHub issues: [github.com/mage2sk/module-quickview/issues](https://github.com/mage2sk/module-quickview/issues)

## Documentation

[USER_GUIDE.md](USER_GUIDE.md) covers installation, the configuration fields, what the storefront modal shows, the admin View Tracker dashboard and detail page, a short troubleshooting table and support contacts.

## License

Commercial software license. See [LICENSE.txt](LICENSE.txt) in this repository.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## Links

- Website: [kishansavaliya.com](https://kishansavaliya.com)
- All extensions: [kishansavaliya.com/magento-extensions.html](https://kishansavaliya.com/magento-extensions.html)
- GitHub: [github.com/mage2sk/module-quickview](https://github.com/mage2sk/module-quickview)
- Packagist: [packagist.org/packages/mage2kishan/module-quickview](https://packagist.org/packages/mage2kishan/module-quickview)
