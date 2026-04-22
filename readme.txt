=== Stellar Meta — Enterprise Facebook & Instagram Integration ===
Contributors: stellarsavers
Tags: facebook, meta, pixel, woocommerce, tracking, GA4, google ads, AI, LTV, churn
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later

Enterprise-grade Meta + Google integration for WooCommerce with AI intelligence.

== Description ==

Stellar Meta v2 is a complete ecommerce tracking and intelligence platform built natively inside WordPress. No third-party SaaS fees, no data leakage to external tools.

= v2 New Features =

**GA4 Server-Side (Measurement Protocol v2)**
Sends purchase, refund, and add_to_cart events directly from PHP to Google Analytics 4. Bypasses iOS ITP, Safari, Firefox ETP, and ad blockers — every conversion captured.

**Google Ads Enhanced Conversions (Server-Side)**
Sends purchase conversions with SHA-256 hashed customer data directly to Google Ads API. Improves attribution accuracy 10–20% per Google benchmarks. Directly improves Smart Bidding performance.

**AI LTV Prediction Engine**
GPT-4o predicts 90-day and 12-month lifetime value per customer after their first purchase. LTV tier (low/mid/high/vip) is appended to every CAPI event as custom_data — feeds Meta Value Optimization bidding automatically.

**Churn Prediction + Win-Back Audiences**
Daily RFM scoring identifies customers at risk of churning. High-risk and critical customers are automatically added to a Meta win-back Custom Audience — catch them before they go silent.

**Anomaly Detection + Slack/Email Alerts**
Monitors ROAS, conversion volume, and pixel health every 15 minutes. Alerts via email and Slack when ROAS drops, pixel stops firing, or conversion volume spikes. Stops budget burn within minutes of a problem.

**Revenue Heatmap**
Day-of-week × hour-of-day revenue grid built from 90 days of order data. Identify your best ad scheduling windows instantly.

**Cohort Analytics**
Monthly acquisition cohorts with retention curves and 6-month revenue per cohort. Replaces $500/month SaaS tools (Triple Whale, Northbeam, Lifetimely) natively.

**Exit Intent Events**
JavaScript detects exit intent via mouseleave velocity and fires CAPI + GA4 events immediately. Enables retargeting within seconds of cart abandonment.

**Cross-Device Identity Stitching**
Server-set HttpOnly cookie (stellar_uid) persists across ITP. Stitches anonymous sessions to authenticated users on login or purchase. Feeds identity graph to Meta advanced matching.

**GA4 Enhanced Ecommerce**
Full item-level event schema: view_item_list, view_item, add_to_wishlist, view_promotion, refund — all with complete item arrays.

**Real-Time Bid Signal Scoring**
Scores every visitor 0–100 on purchase probability based on device, LTV history, page context, and time of day. Score passed to Meta as custom_data.bid_signal.

= Original v1 Features =
* Meta Pixel with multi-channel support (GA4, TikTok)
* CAPI engine with retry queue and exponential backoff
* Smart deduplication (browser + server events)
* AI product enrichment via GPT-4o
* Audience segment builder with Meta sync
* Product catalog feed (XML/RSS)
* GDPR / CCPA consent management
* Funnel analytics dashboard

== Installation ==

1. Upload the stellar-meta folder to /wp-content/plugins/
2. Activate through the WordPress Plugins menu
3. Navigate to Stellar Meta → Settings
4. Enter your Meta Pixel ID and CAPI Access Token
5. For GA4 server-side: enter GA4 Measurement ID and API Secret
6. For AI features: enter your OpenAI API key
7. Enable the features you want from Settings → Tracking Features

== Frequently Asked Questions ==

= Does GA4 server-side replace the GA4 browser tag? =
No — they work together. The browser tag fires for most users. The server-side Measurement Protocol fires for users where the browser tag is blocked (iOS, ad blockers). Deduplication prevents double-counting using transaction_id.

= Does LTV prediction require OpenAI? =
AI-powered predictions use GPT-4o and require an OpenAI API key. A built-in RFM fallback model works without an API key, but produces less accurate predictions.

= Is customer data safe? =
All PII (email, phone, name) is SHA-256 hashed before leaving your server. The stellar_uid cookie contains only an opaque UUID — no PII. Full GDPR/CCPA compliance built in.

== Changelog ==

= 2.0.0 =
* NEW: GA4 Server-Side via Measurement Protocol v2
* NEW: GA4 Enhanced Ecommerce (full item schema)
* NEW: Google Ads Enhanced Conversions server-side
* NEW: AI LTV Prediction Engine (GPT-4o + RFM fallback)
* NEW: Churn Prediction + win-back audience automation
* NEW: Anomaly Detection with Slack + email alerts
* NEW: Revenue Heatmap (day × hour grid)
* NEW: Cohort Analytics (retention curves + revenue per cohort)
* NEW: Exit Intent event tracking
* NEW: Cross-Device Identity Stitching (stellar_uid cookie)
* NEW: Real-Time Bid Signal Scoring (0–100)
* NEW: Intelligence admin page
* NEW: Alerts admin page
* UPDATED: Event builder attaches LTV tier + bid score to all Purchase events
* UPDATED: Settings page with all v2 toggle controls
* UPDATED: Dashboard with intelligence summary cards + anomaly banner
* FIX: ROAS Tracker class was empty — now fully implemented

= 1.0.2 =
* Initial enterprise release
