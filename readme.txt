=== Job Board & Career Site Jobs – Jobo ===
Contributors: jobo
Tags: job board, job listings, career site, jobs, recruitment
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fill your job board automatically from millions of live jobs across 200+ ATS platforms. Incremental sync, and listings close themselves.

== Description ==

**Job Board & Career Site Jobs** keeps a WordPress job board stocked without anyone posting jobs by hand. It pulls live listings from Jobo's index of millions of jobs — sourced directly from employer career sites and 200+ applicant tracking systems including Greenhouse, Lever, Workday, Ashby, SmartRecruiters and BambooHR — and keeps them current on a schedule.

Works with **WP Job Manager** out of the box, writing the same fields your theme already renders. No WP Job Manager? The plugin ships its own job post type, so it works on any site.

= Why this is different from a generic importer =

Most job importers hand you a CSV or an RSS feed and leave the hard parts to you. This one is built around the two things that actually keep a board healthy:

* **Incremental sync.** After the first import, each sync fetches only jobs that are new or have changed since the last one, so syncs stay fast as your board grows and syncing hourly does not bill unchanged jobs again.
* **Jobs close themselves.** Jobo tracks when a listing disappears from the employer's careers page and tells your site, so you are not advertising roles that were filled weeks ago. This check is always free.

= Features =

* Filter by location, company, occupation, job category, language, ATS source, work model, employment type, experience level and posting date
* Company filters: industry, business model, size, revenue, and staffing agencies in or out
* Exclude companies, occupations, categories, industries, sources and more
* Import as published, draft or pending review
* Choose what happens when a job closes — draft it, trash it, or leave it up
* Company name, website, logo, location, salary and apply URL mapped automatically
* Hourly, twice-daily or daily sync via WP-Cron, plus a manual "Sync now"
* Included jobs, wallet balance and sync history visible in the admin
* Handles rate limits, expired cursors and billing failures gracefully instead of silently stopping

= Requirements =

A Jobo account. Sign in with Jobo from the plugin's settings, or paste an API key you [create here](https://enterprise.jobo.world/api-keys?src=wordpress). The $5 free starting balance is enough to try it out. Feed imports use your Job Search plan's included jobs first, then the pay-as-you-go rate ($3.00 per 1,000 jobs); The Unlimited plan drops per-job charges on Feed imports.

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings → Jobo Jobs**.
3. Click **Sign in with Jobo**, sign in, and approve the connection. Jobo's approval screen lists your site as an unverified app with your site address, which is expected for a WordPress site. Signing in needs a site served over HTTPS; otherwise, or if you prefer, choose **Use an API key instead** and paste a key.
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

Feed imports use your Job Search plan's included jobs first, then the pay-as-you-go rate — $3.00 per 1,000 jobs, the same rate that applies without a plan. The Unlimited plan drops per-job charges on Feed imports. The first sync imports every job that matches your filters; after that, each sync imports only jobs that are new or have changed. Changing your filters, or clicking **Resync everything**, imports every matching job again. Checking for closed jobs is always free. The settings screen shows included jobs and wallet balance after every sync.

= Does uninstalling delete my jobs? =

No. Uninstalling removes the plugin's settings and sync state only. Imported listings are your content and are left alone.

= How do the filter suggestions work? =

Start typing in a filter field and the plugin suggests matching values as you go — locations resolve through Jobo's location lookup, companies through Jobo's company directory (a picked company covers all of its careers boards), occupations come from Jobo's occupation taxonomy, and sources, industries and job categories are pulled live from the current job index, so you always pick a value the API actually recognises instead of guessing at spelling. If JavaScript is off, the plain text fields still work; the suggestions are a convenience on top of them, not a requirement.

= Where do the jobs come from? =

Jobo's index, sourced directly from employer career sites and 200+ applicant tracking systems, including Greenhouse, Lever, Workday, Ashby, SmartRecruiters and BambooHR.

= I'm stuck. Where do I get help? =

Email [support@jobo.world](mailto:support@jobo.world), or check the full setup guide at [jobo.world/docs/connectors/wordpress](https://jobo.world/docs/connectors/wordpress).

== External services ==

This plugin connects to Jobo (https://jobo.world), the service the jobs come from. It cannot import jobs without it.

= The Jobo API (connect.jobo.world) =

Used to import jobs, close listings that are no longer open, check the connection, and suggest values in the filter fields. Requests are made on each scheduled sync, when you click **Sync now** or **Test connection**, and as you type in a filter field.

Each request sends your Jobo API key and the filters you saved (locations, sources, companies and the other settings on the plugin's page). No data about your site's visitors is sent.

= Jobo sign-in (enterprise.jobo.world) =

Used only when you click **Sign in with Jobo**. The first time, the plugin registers your site with Jobo by sending a name made from your site's address ("WordPress – example.com") and the address of the plugin's sign-in page on your site. Your browser is then sent to Jobo to sign in and approve the connection, and the plugin exchanges the code Jobo returns for an API key, which it saves in your site's database. The plugin also stores the email address of the Jobo account you signed in with, to show who is connected.

When you click **Disconnect**, or delete the plugin while signed in, the plugin sends that API key back to Jobo so it is revoked.

If you paste an API key and never click **Sign in with Jobo**, the plugin does not contact this address.

Jobo's [terms of service](https://jobo.world/terms) and [privacy policy](https://jobo.world/privacy) apply to both.

== Screenshots ==

1. The Jobo Jobs settings screen, showing connection status.
2. Location and source fields suggesting matching values as you type.
3. The sync status panel — wallet balance and import history.

== Changelog ==

= 0.3.0 =
* New: Sign in with Jobo. Connect the site by signing in to your Jobo account and approving the connection; there is no API key to create or paste. Needs a site served over HTTPS.
* Pasting an API key still works, under **Use an API key instead**. A site already connected with a key keeps working with no action.
* The settings screen shows which account the site is connected as, with a **Disconnect** button. Disconnecting, or deleting the plugin, revokes the key the site was given.

= 0.2.0 =
* New filters: companies (with company search), occupations, job categories and subcategories, languages, industries, primary industry, business model, company size, annual revenue, staffing agencies, and a posted-after date.
* New Exclude section: leave out companies, occupations, categories, industries, sources, languages and more.
* Job descriptions are imported as HTML instead of Markdown, so they render formatted.
* Existing settings keep working unchanged, and upgrading does not trigger a full re-import.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.3.0 =
Adds Sign in with Jobo. Your saved API key, filters and sync position are kept.

= 0.2.0 =
Adds company, occupation, category, language and company filters plus excludes. Your current filters and sync position are kept.

= 0.1.0 =
Initial release.
