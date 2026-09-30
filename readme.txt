=== SEO Content Pilot ===
Contributors: LukeJiao
Tags: seo, ai, content, gsc, geo, keyword, automation, gpt, openai, search-console
Requires at least: 6.6
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keyword expansion → AI article generation → scheduled publishing → GSC + GEO verification. A closed-loop content growth tool for WordPress.

== Description ==

SEO Content Pilot is an end-to-end content growth plugin for WordPress. It turns search demand into published, measurable articles — without leaving the dashboard.

The plugin closes the loop that most tools leave open:

1. **Expand** a handful of seed keywords into a 200+ keyword pool via Google Autocomplete, an LLM and (optionally) DataForSEO.
2. **Score and cluster** the pool by search volume, intent, keyword difficulty and semantic similarity (cosine ≥ 0.82 → same article).
3. **Generate** an outline, full article, SEO metadata and FAQ JSON-LD from your configured LLM provider — with anti-AI detection rules baked in.
4. **Publish** on your schedule: save as draft, publish immediately, or spread posts across days inside a daily window.
5. **Verify** the outcome in Google Search Console (clicks, impressions, position, AI Overview citations) and against live AI engines (ChatGPT / Gemini / Perplexity) via weekly Prompt Tracking.

The result is a working data flywheel: published keywords feed back into GSC, GSC surfaces opportunity keywords, and opportunities flow back into the pool.

**Highlights**

* **Bring-your-own keys.** OpenAI, DataForSEO, Google OAuth, Gemini and Perplexity are all optional and stored encrypted with `sodium_crypto_secretbox` derived from `SECURE_AUTH_KEY`. Nothing leaves your server unless you call the provider you configured.
* **Real GSC integration.** Either install Site Kit by Google and we reuse its token, or plug in your own OAuth client — no manual token copying.
* **GEO / AI Overview visibility.** Weekly tracking of how ChatGPT, Gemini and Perplexity mention your brand, cite your pages, and position you against competitors. Optional `/llms.txt` endpoint for AI crawlers.
* **IndexNow + URL Inspection.** Automatic IndexNow submission on publication, plus a +3 / +7 day URL Inspection pass to confirm indexing.
* **Editorial safety rails.** Tone, length, language, author, default category, daily publishing cap, scheduled window — all configurable. Defaults are conservative: draft mode, low daily cap, prompt templates pre-filled with E-E-A-T and answer-block rules.
* **Single-language UI today, code is i18n-ready.** All PHP and JS strings are wrapped in `__()` and the React UI loads a JED locale file per request. A `zh_CN` translation ships in `/languages`.

**Architecture**
PHP 8.1+ with a PSR-4 autoloader, Action Scheduler (bundled) for the async pipeline, React 18 + `@wordpress/components` for the admin SPA, and `@wordpress/i18n` for the frontend. No Composer dependencies, no remote code loading, no obfuscation.

== Installation ==

1. Upload the `seo-content-pilot` folder to `/wp-content/plugins/`, **or** install the plugin zip directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Visit **SEO Pilot → Settings** and add at least one LLM API key (OpenAI / Gemini / Perplexity).
4. *(Optional)* Add DataForSEO credentials to enable search volume, KD, SERP and AIO data.
5. *(Optional)* Connect Google Search Console — the easiest path is installing **Site Kit by Google** and connecting Search Console there; SEO Content Pilot will reuse the token. Alternatively, create your own Google Cloud OAuth client and paste the ID + Secret into Settings.
6. *(Optional)* Add Gemini / Perplexity keys if you want weekly AI-visibility tracking.

The plugin runs no background work until you do these things: there are no surprise API calls.

== Frequently Asked Questions ==

= Does this work without DataForSEO? =

Yes. Keyword expansion will use Google Autocomplete + your LLM. You only lose search volume, keyword difficulty, SERP features and AI Overview detection.

= Is there a free tier? =

The plugin is free. You only pay for the third-party APIs you choose to use — OpenAI for the data tier, DataForSEO for SEO data, Google for GSC. The plugin does not proxy or markup any of them.

= Where are my API keys stored? =

Encrypted with `sodium_crypto_secretbox`, keyed off `SECURE_AUTH_KEY` from `wp-config.php`. They never leave your server and are never echoed back to the browser.

= Can I review AI drafts before they go live? =

Yes — this is the default. Set **Publish mode** to "Save as draft (recommended)". Switch to "Publish immediately" or "Scheduled (spread across days)" only after you've validated a few outputs.

= What about Google AI Overview tracking? =

The plugin calls the DataForSEO SERP API weekly for keywords you've flagged as `tracked`, parses the response for an AI Overview block, and checks whether your domain (or any of your configured competitor domains) is cited. Results appear on the GEO page and the Dashboard.

= Will it get my site penalised for AI content? =

SEO Content Pilot bakes in a few anti-pattern rules — anti-AI phrases are avoided in the system prompt, templates favour E-E-A-T signals (question H2s, answer blocks, data tables, author bio), and the daily publishing cap is intentionally conservative. None of that is a guarantee; it's still your responsibility to review the output. The default mode is draft so humans stay in the loop.

= Does it support languages other than English? =

The plugin UI currently ships in English with a partial Simplified Chinese (zh_CN) translation. The text domain is `seo-content-pilot`, the strings are all wrapped in `__()`, and a translation template lives in `/languages` — so additional translations can be contributed through the standard WordPress translation workflow.

= Does it require Action Scheduler? =

Action Scheduler is bundled inside the plugin. If WooCommerce or another plugin already provides a newer copy of Action Scheduler, that copy is used instead (Action Scheduler self-de-dupes by comparing versions).

== Screenshots ==

1. Dashboard — GSC traffic, AI Overview citations, brand search trend.
2. Keywords — seed expansion, metrics run, enqueue / delete actions.
3. Generate — single-article quick generation with progress feedback.
4. Queue — per-job step view (outline → sections → finalize → image → publish).
5. Opportunities — striking-distance, low-CTR and unexpected-traffic mining from GSC.
6. GEO — engine-by-engine mention / citation / position, weekly tracking and competitor list.
7. Settings — LLM provider, content preferences, publishing rules, GSC and brand config.

== Changelog ==

= 0.1.0 =
* Initial release.
* Keyword expansion via Google Autocomplete, LLM, and DataForSEO.
* Semantic clustering (cosine ≥ 0.82) and intent classification.
* Outline → sections → finalize → image → publish pipeline via Action Scheduler.
* Yoast / RankMath metadata injection; FAQ JSON-LD; featured image generation with gpt-image → Pexels → skip fallback.
* Google Search Console OAuth (Site Kit reuse + own client), 16-month history backfill and daily sync.
* Opportunity mining: striking distance, low CTR, unexpected traffic.
* AI Overview detection via DataForSEO SERP.
* GEO tracking across ChatGPT, Gemini and Perplexity with weekly scheduled checks.
* IndexNow submission on publish + URL Inspection +3 / +7 day pass.
* Optional `/llms.txt` endpoint for AI crawlers.
* `zh_CN` translation bundled in `/languages/seo-content-pilot-zh_CN.po`.

== Upgrade Notice ==

= 0.1.0 =
First public release.

== Arbitrary section ==

= Credits =
DataForSEO powers the keyword, SERP and AI Overview data. Action Scheduler (by Automattic) is bundled for the async pipeline. `@wordpress/i18n`, `@wordpress/components` and `@wordpress/element` power the React admin SPA.