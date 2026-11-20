=== PrimePlaced - Withdrawal Button & Refunds for WooCommerce ===
Contributors: dobrzepostawieni
Tags: woocommerce, withdrawal button, returns, refund, right of withdrawal
Requires at least: 6.4
Requires PHP: 8.1
Tested up to: 7.1
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Withdrawal button and returns for WooCommerce: EU right of withdrawal form, confirmation email with PDF, returns panel and refunds.

== Description ==

PrimePlaced - Withdrawal Button & Refunds adds a withdrawal button and a complete returns process to a WooCommerce shop. Customers withdraw from the contract in three steps without an account, get an immediate confirmation by email with a PDF, and the shop handles every return request and refund in one WooCommerce panel.

EU Directive 2023/2673 adds an online withdrawal function to consumer law: a button labelled "withdraw from contract here" that stays available for the whole withdrawal period, an online withdrawal statement the customer submits with a "confirm withdrawal" button, and an acknowledgement of receipt on a durable medium. EU member states were to apply these rules from 19 June 2026 through their national laws, so the exact date and details depend on your country. This plugin gives WooCommerce that function (known as "Widerrufsbutton" in Germany and "przycisk odstąpienia od umowy" in Poland) together with the returns and refund workflow behind it.

Everything runs on your own server. The free plugin works without any external service, account or license.

= Withdrawal button and form for customers =

* A visible "Withdraw from contract here" button in the footer, next to orders in My account and in the order emails. You can also place it with the "Withdrawal button" block or the `[ppwb_button]` shortcode.
* A three-step withdrawal form for guests (order number and email address) and logged-in customers. No account, no login and no reason needed. The form also works without JavaScript.
* Choice of products and quantities, an optional return reason and the full text of the withdrawal statement before the customer confirms.
* An immediate confirmation by email with a request number, the date and time of the withdrawal, return instructions and a PDF on a durable medium.
* A return status page with a timeline, shipping details (tracking number or proof of shipment) and the option to cancel the request before the refund.
* My account > Returns with all of the customer's return requests.

= Returns management for the shop =

* WooCommerce > Returns: a list of return requests built like the orders list, with status views, search, bulk actions and the refund deadline.
* Request details like the order edit screen: products, the statement with its SHA-256 hash, a history that cannot be edited and notes for the team or the customer.
* Return statuses with allowed transitions and an email for each step. These are native WooCommerce emails that you can edit.
* WooCommerce refunds in one click: automatic with payment gateways that support refunds, manual for cash on delivery and bank transfer (the customer gives an IBAN, which is stored encrypted).
* Deductions for reduced value of the goods, with a reason that is sent to the customer.
* A daily reminder about refunds close to the statutory 14-day deadline and a dashboard widget.
* Product and category exclusions for the statutory exceptions, shown to customers with the reason.
* Return request numbers such as ZW-2026-000123, in a format you choose.
* A setup wizard, a diagnostics report for support and GDPR tools for personal data export and erasure.

= Built for WooCommerce =

* Compatible with High-Performance Order Storage (HPOS) and the block checkout.
* Templates for the form, the status page, the emails and the PDF can be overridden in your theme, like WooCommerce templates.
* Translation ready. Language packs come from translate.wordpress.org.

= Pro features =

An optional paid Pro version is available separately. The free plugin is fully functional on its own and does not contain locked features, limits or trial periods.

Pro areas include return shipments with carriers (InPost Parcel Locker return codes and Sendcloud return labels), return statistics, reply templates, CSV export, automations such as automatic refunds, webhooks, credit notes after a refund (PDF Invoices & Packing Slips, Fakturownia, wFirma and inFakt), a separate complaints process for defective products, white label, WP-CLI commands and settings export and import.

= No legal advice =

This plugin is a tool and does not provide legal advice. Check your obligations, your withdrawal period and your statutory exceptions with a lawyer.

== Installation ==

1. Install and activate WooCommerce.
2. Install PrimePlaced - Withdrawal Button through the WordPress plugins screen, or upload the plugin files to `/wp-content/plugins/primeplaced-withdrawal-button/`, and activate it.
3. Complete the short setup wizard: return address, who pays for return shipping, withdrawal period, button placement and number format.
4. The plugin creates the "Withdrawal from contract" and "Return status" pages for you. Open WooCommerce > Returns to see incoming requests.

== Frequently Asked Questions ==

= How do I add a withdrawal button to WooCommerce? =

Install and activate the plugin and finish the setup wizard. The button appears in the footer of the shop, next to orders in My account and in the order emails you choose. To place it somewhere else, use the "Withdrawal button" block or the `[ppwb_button]` and `[ppwb_info]` shortcodes.

= Is this the withdrawal function required by EU Directive 2023/2673? =

The plugin is built for the function described in Article 11a, which the directive adds to the Consumer Rights Directive: a clearly labelled button that is available for the whole withdrawal period, a statement the customer submits with a "Confirm withdrawal" button, and an acknowledgement of receipt with the content, date and time of the statement on a durable medium (email and PDF), sent right away. Each EU country implements the directive in its own law and may apply it from a different date. Whether your shop meets all of its legal obligations depends on your settings and your country, so check with a lawyer.

= Is this the "Widerrufsbutton" or "przycisk odstąpienia od umowy"? =

Yes. These are the German and Polish names for the same online withdrawal function. Every text shown to customers can be translated.

= Does the customer need an account or a reason? =

No. Guests use the order number and the email address from the order. Logged-in customers can pick their order directly. Giving a reason is optional.

= When does the withdrawal period start? =

At delivery. The plugin uses the date the order was marked as completed. Before that the customer can always withdraw. The period is at least 14 days and ends at the end of the last day in the shop timezone.

= Can I use it without the footer button? =

The law requires the button to be easy to find for the whole withdrawal period. You can turn the footer button off and place the button yourself with the "Withdrawal button" block or the shortcodes.

= How are refunds made? =

From the return request you refund the customer through WooCommerce. Payment gateways that support refunds send the money back automatically. For cash on delivery and bank transfer orders the customer gives a bank account number, and you mark the refund as paid after the transfer.

= Can I exclude products from returns? =

Yes. You can exclude single products and whole categories, for example goods made to the customer's specification or sealed goods that were opened. Customers see such products in the form with the reason.

= Which carriers can the customer pick for the return? =

The common carriers of the country the customer sends from (taken from the order), then DHL, DPD, GLS, UPS and FedEx, and "Other". Developers can change the list with the `ppwb_return_carriers` filter.

= Can I change the emails? =

Yes. Every email is a WooCommerce email: change the subject, heading and additional content in WooCommerce > Settings > Emails, or override the templates in your theme like WooCommerce templates (copy `templates/emails/request.php` to `yourtheme/primeplaced-withdrawal-button/emails/request.php`). Email designer plugins that style WooCommerce emails style these too. The statement text in the confirmation cannot be edited: it is legal evidence and must match the PDF and its SHA-256 hash.

= Are the PDF files public? =

No. They are stored with random names in a protected folder and are served only to shop staff and, through a signed link, to the customer.

= Does it work with HPOS and the block checkout? =

Yes. The plugin reads orders only through the WooCommerce order API and declares compatibility with High-Performance Order Storage and the cart and checkout blocks.

= Does the plugin send data to a remote service? =

No customer or order data leaves your site. The plugin uses the Freemius SDK for optional opt-in usage data, licensing and upgrades; see the External services section for exactly when it contacts Freemius and what it sends.

= Where can I find the documentation and get help? =

The documentation is at https://dobrzepostawieni.pl/withdrawal-button/documentation and covers installation, setup, refunds and emails. Ask questions about the free plugin on the support forum on WordPress.org. More ways to get help: https://dobrzepostawieni.pl/withdrawal-button/support

= What happens on uninstall? =

Your data stays by default, because return requests are legal records. To remove the tables, settings and files when the plugin is deleted, turn on "Delete data on uninstall" in the settings first.

== Screenshots ==

1. Withdrawal button in the shop footer.
2. Withdrawal form: choosing the products and quantities to return.
3. Confirmation with the request number, the date and time and the PDF.
4. Return status page with the timeline and shipping details.
5. WooCommerce > Returns: the list of return requests with statuses and refund deadlines.
6. Return request details: products, statement, history and the refund.
7. Settings: withdrawal period, how it is counted and who pays for return shipping.

== Development ==

The complete, human-readable source of the generated JavaScript in `assets/build/` is available on GitHub: https://github.com/Dobrzepostawieni/primeplaced-withdrawal-button

`assets/src/front/form.ts` is the source of `assets/build/ppwb-form.js`. To rebuild `assets/build/`, clone the repository and run `npm ci` and then `npm run build` with Node.js 20 or newer. All other JavaScript, CSS and PHP files in the plugin are shipped unminified.

== External services ==

This plugin uses the [Freemius](https://freemius.com/) SDK, a service provided by Freemius, Inc., for opt-in usage diagnostics, account and license management, and the optional upgrade flow. Customer data, orders and return requests are never sent to Freemius.

The plugin contacts Freemius (`api.freemius.com`, `wp.freemius.com`, `checkout.freemius.com`) only in these cases:

* **After an administrator opts in** on the plugin's welcome screen: the administrator's name and email address; the site's homepage URL and title, WordPress and PHP versions, and site language; the plugin and SDK versions and whether the plugin is active, deactivated, or uninstalled. If the administrator also allows it, the names, slugs, versions, and active status of installed plugins and themes are sent. Opting in is optional, and the administrator can skip it or change permissions later from the plugin's Account page.
* **When an administrator submits deactivation feedback**: the selected reason and any text entered.
* **When an administrator starts the upgrade or checkout flow**: plan and pricing data are loaded from Freemius, and the information entered during checkout is sent to Freemius to process the purchase.

If the administrator does not opt in and does not use these screens, no data is sent to Freemius.

Freemius terms of service: https://freemius.com/terms/
Freemius privacy policy: https://freemius.com/privacy/

== Privacy ==

The plugin stores the customer's name, email address, order number, returned products, the optional reason, the statement text with its date and time, a salted hash of the IP address (never the raw address) and, when the refund is made by bank transfer, the encrypted account number. This data stays in your WordPress database and in a protected folder on your server. It is included in the WordPress personal data export and erasure tools.

== Changelog ==

= 1.0.0 =

* Initial release.
