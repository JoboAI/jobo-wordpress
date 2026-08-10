=== Job Board & Career Site Jobs – Jobo ===
Contributors: jobo
Tags: job board, job listings, career site, jobs, recruitment
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fill your job board automatically from millions of live jobs across 100+ ATS platforms. Incremental sync, and listings close themselves.

== Description ==

**Job Board & Career Site Jobs** keeps a WordPress job board stocked without anyone posting jobs by hand. It pulls live listings from Jobo's index of millions of jobs — sourced directly from employer career sites and 100+ applicant tracking systems including Greenhouse, Lever, Workday, Ashby, SmartRecruiters and BambooHR — and keeps them current on a schedule.

Works with **WP Job Manager** out of the box, writing the same fields your theme already renders. No WP Job Manager? The plugin ships its own job post type, so it works on any site.

= Why this is different from a generic importer =

Most job importers hand you a CSV or an RSS feed and leave the hard parts to you. This one is built around the two things that actually keep a board healthy:

* **Incremental sync.** Each run resumes from a stored cursor instead of re-scanning from the top, so syncs stay fast as your board grows and you are not billed repeatedly for jobs you already have.
* **Jobs close themselves.** Jobo tracks when a listing disappears from the employer's careers page and tells your site, so you are not advertising roles that were filled weeks ago. This check never costs credits.

= Features =

* Filter by location, ATS source, work model, employment type and experience level
* Import as published, draft or pending review
* Choose what happens when a job closes — draft it, trash it, or leave it up
* Company name, website, logo, location, salary and apply URL mapped automatically
* Hourly, twice-daily or daily sync via WP-Cron, plus a manual "Sync now"
* Shared job allowance, credit balance and sync history visible in the admin
* Handles rate limits, expired cursors and billing failures gracefully instead of silently stopping

= Requirements =

A Jobo API key. [Create one here](https://enterprise.jobo.world/api-keys) — the free tier is enough to try it out. Feed imports use the shared Job Search allowance or the same $3 per 1,000 public direct job rate as Search; Jobs Feed makes Feed imports unlimited.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Jobo Jobs**.
3. Paste your Jobo API key.
4. Pick the locations and filters you want. Start typing in the Locations or Sources fields and choose from the suggestions — no need to know the exact spelling or ATS slug. Leave everything blank to import all jobs.
5. Click **Sync now**, or wait for the first scheduled run.

== Frequently Asked Questions ==

= Do I need WP Job Manager? =

No. If WP Job Manager is active the plugin writes to it, so your existing theme and shortcodes work unchanged. If it is not, the plugin registers its own Jobs post type.

= Will this duplicate listings? =

No. Every listing is matched on its Jobo job ID, so repeated syncs update in place. Even a full resync updates rather than duplicates.

= What happens to jobs I have edited by hand? =

Updates never change the status of a listing, so if you unpublish something it stays unpublished. Title, description and metadata are refreshed from the source.

= How much does it cost? =

Feed imports use your shared Job Search allowance first, then its normal job rate. Without a plan, the current public direct rate is $3 per 1,000 jobs. Jobs Feed makes Feed imports unlimited. Checking for closed jobs is always free. The settings screen shows allowance and wallet state after every sync.

= Does uninstalling delete my jobs? =

No. Uninstalling removes the plugin's settings and sync state only. Imported listings are your content and are left alone.

= How do the filter suggestions work? =

Start typing in the Locations or Sources fields and the plugin suggests matching values as you go — locations resolve through Jobo's location lookup, and sources are pulled live from the current job index, so you always pick a value the API actually recognises instead of guessing at spelling. If JavaScript is off, the plain text fields still work; the suggestions are a convenience on top of them, not a requirement.

= Where do the jobs come from? =

Jobo's index, sourced directly from employer career sites and 100+ applicant tracking systems, including Greenhouse, Lever, Workday, Ashby, SmartRecruiters and BambooHR.

= I'm stuck. Where do I get help? =

Email [support@jobo.world](mailto:support@jobo.world), or check the full setup guide at [jobo.world/docs/connectors/wordpress](https://jobo.world/docs/connectors/wordpress).

== Screenshots ==

1. The Jobo Jobs settings screen, showing connection status.
2. Location and source fields suggesting matching values as you type.
3. The sync status panel — credit balance and import history.

== Changelog ==

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
