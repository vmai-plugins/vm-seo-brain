=== VM SEO Brain ===
Contributors: vmstudiocreatives
Tags: seo, rank math, search console, analytics, ai, automation
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 1.7.2
License: GPLv2 or later

An autonomous SEO layer for WordPress: it reads the business, researches keywords,
fixes what is broken, rebuilds the silo, and ships content through AI Puffer.

== What it does ==

**Central brain.** On first run it reads the homepage, key pages, taxonomies,
products and Search Console queries, then writes a business profile it holds in
its own memory table. Every prompt the plugin sends afterwards is anchored to
that profile, which is the difference between useful content and AI filler.

**Keyword research without a subscription.** Three sources merged and scored:
Search Console (what already earns impressions), Google autocomplete (live
demand), and AI expansion from the business profile (for sites with no history).
Scoring favours striking-distance terms over vanity volume.

**Error fixer.** A rule engine scans technical setup, on-page markup, taxonomies,
silo integrity and index coverage. *God Fix* works the queue on demand; *God Mode*
does the same nightly inside the scope you allow. Every autonomous write stores a
revert payload, so nothing is one-way.

**Silo generator and fixer.** Builds a pillar-and-cluster map from the keyword
clusters, then finds where the live site deviates: missing pillars, orphans,
posts that never link up. Internal links are inserted on an anchor phrase the AI
picks from sentences already in the article, not bolted to the footer.

**Category and tag optimiser.** Finds empty archives, one-post tags, near-duplicate
terms and missing descriptions. Merges with redirects, deindexes what should not
be indexed, and writes real archive copy so category pages rank in their own right.

**Content pipeline.** Gaps become a topic plan, the plan syncs to a Google Sheet
your team can edit, AI Puffer writes from the brief, images are generated,
internal links wired, Rank Math meta and FAQ schema attached, then draft or publish.

**Image engine.** Pollinations first (free), then ComfyUI on your own box, then
AI Puffer, then Pexels as stock fallback. Everything lands in the media library
with an SEO filename and real alt text.

== On growth targets ==

The dashboard tracks whatever target you set and projects against it using your
actual GA4 and Search Console trajectory. It will tell you when the target is out
of reach and what would have to change. It does not promise a number: organic
search has a 6 to 14 week lag between publishing and stable ranking, so a short
window mostly harvests pages that are already indexed. The plugin is built to
harvest that first, which is why striking-distance and CTR fixes run before new
content in the priority order.

== Requirements ==

* WordPress 6.2+, PHP 8.0+
* Rank Math (for meta and schema writes)
* A Google Cloud OAuth client with Search Console, Analytics and Sheets scopes
* At least one working AI provider in the chain

== Changelog ==

= 1.7.2 =
* Fixed mutual infinite recursion loop between VM SEO Brain and VM Social AI credential resolution during wp-admin update checks.
* Added static in-flight recursion guard with try-finally protection to VMSB_Settings::get().
* Decoupled admin_init heartbeat task execution into non-blocking async cron dispatch.
* Tuned AI Router and Image Engine provider timeouts to prevent Cloudflare 524 gateway timeouts.
* Guarded AI Link Genius broken links table query against missing database table errors.
* Added configurable Google broker endpoint and default direct OAuth visibility in settings.

= 1.7.1 =
* Fixed PHP 8 TypeError crash in VMSB_Content when internal link hints contain structured arrays.
* Fixed 504 Gateway Timeout during ComfyUI rendering by capping synchronous polling to 45s maximum.
* Fixed database full table scan in Task Runner by adding MD5 hash matching on queued payloads.
* Fixed postmeta timestamp string comparison in Link Indexer by casting meta_value to numeric UNSIGNED.
* Fixed blocking Google Indexing API calls during interactive post save by dispatching via single async event.

= 1.7.0 =
* Added 1-Click Google Sign-In with auto-discovery for Google Search Console verified domains and GA4 properties.
* Redesigned Google Integration settings with live connected profile cards and property dropdown selectors.
* Added bidirectional cross-plugin credential sync with VM Social AI.
* Hardened SQL IN-clause queries with parameterized placeholders in VMSB_Content and VMSB_Fixer.
* Preserved expandable developer custom app fallback for enterprise white-label installations.

= 1.6.2 =
* Auto-calibrated Rank Math score persistence into postmeta 'rank_math_seo_score' on optimization and publishing.
* Integrated automated Rank Math 85-90+ title and on-page optimization into VMSB_Content::produce() for all new daily articles.
* Enhanced server-side test scoring alignment for Rank Math PRO analytics compatibility.

= 1.6.1 =
* Added Unicode and JSON serialization artifact sanitization guard in Quality Gate and Content Optimizer (eliminates u003cstrongu003e leaks).
* Added Rank Math 85-90+ batch optimizer (boost_85) targeting striking distance posts.
* Enhanced Title Power Word and Number optimization engine for higher SERP CTR.
* Added WP-CLI command 'wp vmsb boost-85' for rapid background execution on VPS.

= 1.6.0 =
* Fixed Google OAuth token exchange failure caused by nonce vs transient state mismatch.
* Fixed Rank Math page metrics query targeting analytics table and missing columns.
* Fixed rollback status false-positive completion on post update failure.
* Fixed Unix epoch 1970 timestamp corruption when parsing invalid sheet dates.
* Fixed secondary keywords and internal link hints dropping when not strictly JSON-encoded.
* Fixed textarea line break stripping for business descriptions and services.
* Fixed GitHub updater stdClass uninitialized property notices in PHP 8.2+.
* Fixed Pexels image engine fallback and attribution edge-cases.
* Hardened D3 knowledge graph visualizer against blocked or offline CDN resources.
* Comprehensive PHP 8.2+ compatibility audit and strict null-safety enhancements.

= 1.5.0 =
* OmniRoute integration and enhanced resilience chain.

= 1.0.0 =
* First release.
