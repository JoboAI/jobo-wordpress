=== Job Board & Career Site Jobs – Jobo ===
Contributors: joboai
Tags: job board, job listings, career site, jobs, recruitment
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Fill your job board automatically from millions of live jobs across 200+ ATS platforms. Incremental sync, and listings close themselves.

== Description ==

**Job Board & Career Site Jobs** keeps a WordPress job board stocked without anyone posting jobs by hand. It pulls live listings from Jobo's index of millions of jobs — sourced directly from employer career sites and 200+ applicant tracking systems including Greenhouse, Lever, Workday, Ashby, SmartRecruiters and BambooHR — and keeps them current on a schedule.

Works with **WP Job Manager** out of the box, writing the same fields your theme already renders. No WP Job Manager? The plugin ships its own job post type, with an apply link and JobPosting structured data on each listing, so it works on any site.

= Why this is different from a generic importer =

Most job importers hand you a CSV or an RSS feed and leave the hard parts to you. This one is built around the two things that actually keep a board healthy:

* **Incremental sync.** After the first import, each sync fetches only jobs that are new or have changed since the last one, so syncs stay fast as your board grows. A job is billed once per 30 days.
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
5. Tick **Sync automatically** and save. Nothing is imported on a schedule until you do; **Sync now** imports straight away.

== Frequently Asked Questions ==

= Do I need WP Job Manager? =

No. If WP Job Manager is active the plugin writes to it, so your existing theme and shortcodes work unchanged. If it is not, the plugin registers its own Jobs post type.

= Will this duplicate listings? =

No. Every listing is matched on its Jobo job ID, so repeated syncs update in place. Even a full resync updates rather than duplicates.

= What happens to jobs I have edited by hand? =

Updates never change the status of a listing, so if you unpublish something it stays unpublished. Title, description and metadata are refreshed from the source. The exception: a listing the plugin retired when its job closed gets its status back if the job reopens.

= How much does it cost? =

Feed imports use your Job Search plan's included jobs first, then the pay-as-you-go rate — $3.00 per 1,000 jobs, the same rate that applies without a plan. The Unlimited plan drops per-job charges on Feed imports. A job is billed once per 30 days: receiving it again in that time is free. The first sync, a filter change and **Resync everything** import every matching job; other syncs import only new or changed jobs. Checking for closed jobs is always free. The settings screen shows included jobs, wallet balance and what the last sync billed.

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

Used to import jobs, close listings that are no longer open, check the connection, and suggest values in the filter fields. Requests are made on each scheduled sync, when you click **Sync now** or **Test connection**, when you open the plugin's settings screen while connected (to load the lists behind the filter fields), and as you type in a filter field.

Each request sends your Jobo API key. An import also sends the filters you saved (locations, sources, companies and the other settings on the plugin's page), and a lookup sends the text you typed in that filter field. Every request carries the plugin's name and version and your site's address in its User-Agent header. The plugin sends no data about your site's visitors.

= Company logos (images.jobo.world) =

Company logos are not copied to your site. A listing stores the address of its company's logo, usually on Jobo's image host. Where your theme shows the logo, a visitor's browser loads it from that host, which sees the visitor's IP address and the page address, as with any embedded image.

= Jobo sign-in (enterprise.jobo.world) =

Used only when you click **Sign in with Jobo**. The first time, the plugin registers your site with Jobo by sending a name made from your site's address ("WordPress – example.com") and the address of the plugin's sign-in page on your site. Your browser is then sent to Jobo to sign in and approve the connection, and the plugin exchanges the code Jobo returns for an API key, which it saves in your site's database. The plugin also stores the email address of the Jobo account you signed in with, to show who is connected.

When you click **Disconnect**, or delete the plugin while signed in, the plugin sends that API key back to Jobo so it is revoked.

If you paste an API key and never click **Sign in with Jobo**, the plugin does not contact this address.

Jobo's [terms of service](https://jobo.world/terms) and [privacy policy](https://jobo.world/privacy) apply to both.

== Changelog ==

= 0.4.0 =
* New: **Scheduled sync** switch, off on a new site so nothing is imported before you choose filters. A site that has synced keeps syncing.
* New: built-in Jobs listings show company, location, salary and an apply link, and carry JobPosting structured data.
* A job that reopens gets its listing back.
* A filter change takes effect on the next sync, and listings that no longer match are retired.
* Fixed: city-only locations, closures missed on busy days, overlapping syncs, slow feed pages timing out.
* Status shows what the last sync billed.

= 0.3.1 =
* Fixed: text typed in a filter field reaches Jobo as typed. A company search with &, + or # (AT&T) was cut short.
* Fixed: the check for closed jobs sends its start time correctly encoded.
* New: Description layout. **Enhanced (beta)** gives every description the same headings, lists and key facts.
* Uninstalling also removes the cached filter lists.
* External services now lists what each request carries and where company logos load from.

= 0.3.0 =
* New: Sign in with Jobo. Approve the connection in your Jobo account; there is no API key to paste. Needs HTTPS. Pasting a key still works.
* The settings screen shows the connected account, with a **Disconnect** button that revokes the site's key.

= 0.2.0 =
* New company, occupation, category, language and industry filters, and an Exclude section.
* Job descriptions are imported as HTML instead of Markdown.

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.4.0 =
Adds a Scheduled sync switch (on for a site that has synced) and listing pages for the built-in post type.
