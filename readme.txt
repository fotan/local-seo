=== Local SEO ===
Contributors: mattdanskine
Tested up to: 6.6
Stable tag: 1.2.0
Requires at least: 5.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Outputs LocalBusiness JSON-LD in wp_head from a settings form, merging into an existing Organization node via a shared @id.

== Description ==

Local SEO generates `LocalBusiness` JSON-LD structured data in `wp_head` from a
simple settings form. It uses a shared `@id` so the output merges into an
existing `Organization` node (for example the one The SEO Framework emits)
instead of conflicting with it. Blank fields are omitted from the output.

Settings live under **Settings &rarr; Local SEO** and are available to the Editor
role and up (filter `local_seo_capability` to change that).

Updates are delivered from the plugin's public GitHub repository via Plugin
Update Checker, so new versions show up in the normal Plugins list and in
bulk-update tools like MainWP.

== Installation ==

1. Upload the plugin to `wp-content/plugins/local-seo`, or install the zip from
   the Plugins screen.
2. Activate **Local SEO**.
3. Open **Settings &rarr; Local SEO** and fill in the business details.

== Changelog ==

= 1.2.0 =
* Added a `[local_seo_hours]` shortcode to display opening hours on the page, gated behind an "Enable" checkbox. Output uses per-day/per-row CSS classes (`.ls-hours-day-monday`, `.ls-hours-today`, etc.) so hours can be styled from the theme.

= 1.1.0 =
* Opening Hours now supports multiple rows with different hours per group of days (e.g. Mon-Fri 9-5, Sat 10-2), instead of one shared time range for all checked days. Existing single-range settings are migrated automatically.
* Added `local_seo_get_hours()` for theme/site code that wants to display the same hours elsewhere on the page.

= 1.0.4 =
* Plugin slug/text domain normalised to "local-seo".

= 1.0.1 =
* Menu and settings page now available to the Editor role and up (filter `local_seo_capability`).
* GitHub-release auto-updates via Plugin Update Checker.

= 1.0.0 =
* Initial release.
