=== ServiceFlow ===
Contributors: sylvestreui
Tags: chat, orders, invoices, stripe, payments, service, client, account
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any Custom Post Type into a complete client service platform — chat, orders, payments, invoices and notifications in one plugin.

== Description ==

ServiceFlow turns any Custom Post Type into a full client service management system. Each post gets its own real-time chat, allowing clients and service providers to communicate directly, place orders, track progress and pay online.

**Core Features (Free)**

* Real-time chat attached to any CPT post
* Service packs and options management with pricing, delays and descriptions
* Order workflow: pending → paid → started → completed → revision → accepted
* Client account page with dashboard, orders, invoices and profile (`[serviceflow_account]` shortcode)
* Admin dashboard with statistics and recent activity
* In-app notification system
* Customisable accent colour and chat button position
* Fully responsive — mobile-friendly sidebar and chat
* WP-Cron warning if DISABLE_WP_CRON is active

**Premium Features (via Freemius)**

* Stripe Checkout integration for online payments (single, deposit, installments, monthly subscription)
* Automatic PDF invoice generation
* Email notifications (new order, status change, payment links, reminders)
* Full email template editor with live preview
* Todo list per order with progress tracking
* Automatic payment link sending on due date (WP Cron)
* Configurable payment reminders N days before due date
* Unlimited services (free plan limited to 1)
* Extra pages, express delivery and maintenance pricing options
* Elementor Dynamic Tags integration

== Installation ==

1. Upload the `serviceflow` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **ServiceFlow → Settings** and choose your Custom Post Type
4. Create a page and add the `[serviceflow_my_account]` shortcode for the client account dashboard (orders, invoices, profile). Optionally, add `[serviceflow_account]` in your header/navbar as a login/avatar widget.
5. *(Premium)* Configure Stripe under **ServiceFlow → Stripe** — paste your API keys and webhook secret
6. *(Premium)* Configure email notifications under **ServiceFlow → Notifications**

**WP Cron (for automatic payment links)**

On low-traffic sites, add a real system cron job for reliable daily scheduling:
`* 8 * * * curl https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1`

== Frequently Asked Questions ==

= Which Custom Post Types are supported? =

Any public Custom Post Type registered on your site. Configure it in ServiceFlow → Settings.

= Is Stripe required? =

No. Without Stripe (free plan), orders follow a manual chat-based workflow. Enabling Stripe (premium) adds automatic payment collection via Stripe Checkout.

= Can I customise the chat appearance? =

Yes. You can change the accent colour and chat button position (bottom-left, bottom-right, top-left, top-right) from the settings page. All UI elements adapt to the chosen colour automatically.

= What payment modes does Stripe support? =

Four modes per service: single payment, 50% deposit + balance on delivery, installments (40% upfront + N monthly payments), or pure monthly subscription.

= Where is the webhook URL for Stripe? =

Go to **ServiceFlow → Stripe** in your admin. The webhook URL is displayed there — copy it into your Stripe Dashboard under Developers → Webhooks. Select the `checkout.session.completed` event.

== External Services ==

This plugin optionally connects to the following third-party services:

= Stripe (premium only) =
Used to process online payments via Stripe Checkout. Only active when Stripe is enabled by the site administrator and API keys are configured.
* Service: https://stripe.com
* Privacy Policy: https://stripe.com/privacy
* Terms of Service: https://stripe.com/legal

= Freemius =
Used to manage plugin licensing, upgrades and trials. Activated on first use of the plugin admin area (opt-in required).
* Service: https://freemius.com
* Privacy Policy: https://freemius.com/privacy

No data is transmitted to external services in the free plan without explicit configuration by the site administrator.

== Changelog ==

= 1.6.4 =
* Add: expired Stripe session detection — payment buttons turn grey with "Lien expiré" badge after 24h
* Add: `sf_payment_success` return URL handler — fallback Stripe API check if webhook not yet received
* Fix: `[serviceflow_my_account]` shortcode documented correctly in installation instructions (was `[serviceflow_account]`)
* Fix: TVA always 0 in free plan (tax_rate now premium-gated at all calculation points: display, order storage, chat message)
* Fix: "Commander via le chat" flickering to Stripe button in free plan (ServiceFlow_Stripe::is_enabled() now checks premium license first)
* Fix: unread badge persisting after page refresh (lastId now persisted in localStorage per user/post)
* Fix: manual order total stored as HT instead of TTC (tax_rate applied before create_order call)

= 1.6.3 =
* Fix: migration backfill for `due_date` on existing payment schedule rows (pre-1.6.2 installs)
* Fix: options checkboxes deselecting every 5 seconds due to poll calling syncCardState on unchanged state
* Fix: revision delay input field losing value on each poll cycle
* Fix: unread message badge not resetting when chat opened (admin hasLoaded edge case)
* Fix: sidebar sticky position in account dashboard jumping between tabs
* Add: WP-Cron disabled warning in admin (DISABLE_WP_CRON detection)
* Add: Sous-total / TVA / Total breakdown in service card
* Add: payment mode badge displayed below "Choose your pack" title
* Add: email template live preview modal (admin notifications page)
* Improve: scrollbar styling — ultra-thin, appears on hover only
* Improve: empty chat message updated to more engaging copy

= 1.6.2 =
* Add: automatic payment link sending via WP Cron on due date
* Add: payment reminder emails N days before due date (configurable)
* Add: real-time payment button status (turns green on payment confirmation)
* Add: `[SF_SCHED:id]` marker system linking chat messages to schedule rows
* Add: `due_date` column on payment schedule table
* Add: full email template editor for all 5 notification types
* Fix: Stripe webhook URL — switched from REST API to admin-ajax (resolves 404 on some hosts)
* Fix: URL regex truncating checkout links in chat messages (token-based replacement)
* Fix: sidebar scroll capturing page scroll instead of card content

= 1.6.1 =
* Add: Freemius SDK integration (licensing, trial, upgrade flow)
* Add: premium feature gating (Stripe, invoices, todos, emails, unlimited services)
* Add: ServiceFlow_Payments class with full installment/monthly/deposit schedule management
* Add: todo list per order with auto-complete on order acceptance
* Add: Elementor Dynamic Tags for service data

= 1.5.0 =
* Add: Stripe Checkout integration
* Add: user account page with dashboard, orders, invoices and profile
* Add: admin dashboard with statistics
* Add: notification system (in-app + email)
* Add: invoice PDF generation
* Improve: mobile responsiveness

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.6.4 =
Fixes TVA calculation, Stripe button flickering in free plan, and unread badge persistence. Adds expired session detection. Safe to update — no DB migration required.

= 1.6.3 =
Minor fixes and UX improvements. Safe to update — includes an automatic DB migration for existing payment schedules.

= 1.6.2 =
Adds WP Cron payment automation and email templates. Run the update then visit any admin page once to trigger the DB migration.

= 1.5.0 =
Major update with Stripe payments, user accounts, invoices and notifications.
