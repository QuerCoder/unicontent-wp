=== UNICONTENT AI Content Generator ===
Contributors: unicontent
Tags: ai, content generation, seo, woocommerce, ecommerce
Requires at least: 5.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.2.9.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate product descriptions, SEO meta tags, and post content directly in WordPress with queue processing and review workflow.

== Description ==

UNICONTENT AI Content Generator helps editors and store owners generate content in bulk for WordPress and WooCommerce.

Features:

* API key connection with balance check
* Prompt templates with token variables
* Ready template catalog
* Batch queue processing with progress tracking
* Review and approve/reject workflow before publishing
* Support for post fields, meta fields, and ACF fields

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install the ZIP via **Plugins > Add New > Upload Plugin**.
2. Activate **UNICONTENT AI Content Generator**.
3. Open **AI Content** in the WordPress admin menu.
4. Add your API key on the dashboard or settings screen.
5. Configure templates and start generation.

== Frequently Asked Questions ==

= Does it work with WooCommerce? =

Yes. The plugin supports WooCommerce product fields and tokens.

= Can I review content before publishing? =

Yes. Use generation mode with review workflow and approve/reject in bulk.

= Which languages are supported? =

Russian and English are supported in the admin interface.

== Changelog ==

= 0.2.9.1 =

* Added publish date range in Generate wizard for comments and WooCommerce reviews
* Date range now supports random publish datetime between two selected dates
* If date range is empty, comments/reviews are published with current date and time
* Passed publish-date context through queue publish mode and review approve action

= 0.2.9.0 =

* Added new generation scenarios: WordPress comments and WooCommerce reviews
* Added flexible prompt blocks editor in Templates (base prompt + multiple blocks)
* Improved SEO plugin selector behavior with unavailable plugin visibility
* Improved scenario-aware template payload compatibility

= 0.2.7 =

* Added English localization support
* Added WordPress.org metadata and readme
* Improved ready template catalog filtering
* Fixed back link in ready template catalog
* Standardized decimal separator output to dot
