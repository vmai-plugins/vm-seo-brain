=== VM SEO Brain ===
Contributors: vmstudiocreatives
Tags: seo, rank math, search console, analytics, ai, automation
Requires at least: 6.2
Requires PHP: 8.0
Stable tag: 1.5.0
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

= 1.0.0 =
* First release.
