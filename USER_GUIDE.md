# Panth QuickView -- User Guide

## 1. Installation

Install via Composer from the Adobe Commerce Marketplace:

```bash
composer require mage2kishan/module-quickview
bin/magento module:enable Panth_QuickView
bin/magento setup:upgrade
bin/magento cache:flush
```

The module requires `mage2kishan/module-core` (installed automatically
as a Composer dependency).

## 2. Configuration

Go to **Stores > Configuration > Panth Extensions > Quick View**.

| Setting               | Default | Description                                    |
|-----------------------|---------|------------------------------------------------|
| Enable Quick View     | Yes     | Master on/off switch for the module            |
| Show Image Gallery    | Yes     | Display thumbnail strip beneath the main image |
| Show Short Description| Yes     | Show truncated description in the modal        |
| Show SKU              | Yes     | Display SKU below the product name             |
| Show Stock Status     | Yes     | Show In Stock / Out of Stock badge             |
| Show Add to Cart      | Yes     | Render quantity selector and Add to Cart button|

Save the configuration and flush the cache after making changes.

## 3. Storefront -- Quick View Modal

A Quick View button appears on every product card in category and
search-result pages. Clicking it opens a modal with:

- Product image gallery (swipeable thumbnails)
- Product name, SKU, price (including special / sale price)
- Stock status badge
- Truncated short description
- Quantity selector + Add to Cart (simple/virtual products)
- "Select Options" link (configurable/bundle/grouped products)
- "View Full Details" link to the product page

The modal is responsive and works on mobile, tablet, and desktop.

## 4. Admin -- Product View Tracker

Navigate to **Panth Infotech > Quick View > View Tracker** in the
admin sidebar.

### Dashboard

- **Stat cards** -- Total views, today, this week, this month, and
  unique visitors.
- **30-Day Trend chart** -- Line chart showing total views and unique
  products viewed per day.
- **Hourly Distribution chart** -- Bar chart of today's views by hour.
- **Top Customers** -- Ranked list of logged-in customers by view count.
- **Most Viewed Products** -- Ranked list with SKU and direct admin links.
- **Recent Views** -- Live feed of the latest product views with viewer
  name, time ago, and a "View Details" link.

### View Detail Page

Click "View Details" on any entry to see:

- Full product info (image, name, SKU, price, type)
- View metadata (timestamp, viewer type, viewer name)
- Product view history timeline
- Customer view history timeline (if the viewer was logged in)

## 5. Troubleshooting

| Symptom                         | Solution                                   |
|---------------------------------|--------------------------------------------|
| Quick View button not showing   | Verify module is enabled in admin config   |
| Modal opens but shows spinner   | Check browser console for JS/network errors|
| View Tracker dashboard is empty | Views are recorded on product page load;   |
|                                 | browse some products on the storefront     |

## 6. Support

- **Email:** kishansavaliyakb@gmail.com
- **Website:** https://kishansavaliya.com
- **WhatsApp:** +91 84012 70422
