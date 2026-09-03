# Local SEO

A small WordPress plugin that generates `LocalBusiness` JSON-LD structured data in
`wp_head` from a settings form.

It uses a shared `@id` so the output merges into an existing `Organization` node
(for example the one The SEO Framework emits) instead of conflicting with it.
Blank fields are omitted from the output.

## Installation

1. Download the latest release zip from the
   [Releases page](https://github.com/fotan/local_seo/releases), **or** clone this
   repo into `wp-content/plugins/local_seo`.
2. Activate **Local SEO** in the WordPress Plugins list.
3. Go to **Settings → Local SEO** and fill in the business details you want in the
   schema.

## Configuration

All settings live on the **Settings → Local SEO** admin page and are stored in the
`local_seo_options` row of `wp_options`. Fields left blank are not written to the
JSON-LD.

Two filters are available for developers:

| Filter | Purpose | Default |
| --- | --- | --- |
| `local_seo_wp_head_priority` | Priority of the `wp_head` action that prints the schema | `2` |
| `local_seo_options` (via the standard settings API) | The saved options array | — |

## Updates

Updates are delivered straight from this GitHub repo using
[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
(vendored in `vendor/`). The repo is public, so no access token is needed.

When a new version is tagged, WordPress shows a normal "update available" notice
on the Plugins screen — and bulk-update tools like MainWP pick it up the same way.
Updating replaces the plugin files exactly like any other plugin update.

### Cutting a release

Releases are automated by `.github/workflows/release.yml`:

1. Bump the `Version:` header in `local_seo.php`.
2. Commit and push to the `master` branch.
3. The workflow reads the new version, creates a matching git tag
   (e.g. `1.0.1`) and a GitHub Release with auto-generated notes.
4. Plugin Update Checker on each site sees the new tag within its normal check
   window (roughly every 12 hours, or immediately via **Check for updates**).

No build step is required — the release is the tagged source tree, PUC included.



## ** NOTICE **

This was an itch I needed to scratch.  For expediency I used Claude Code to build it.  

From a cursory look, it's secure enough.  I don't think it's going to open anything up to the bad guys, but it's not guaranteed.

Use at your own risk.  It's not my fault if you install some shit code you found on the internet.

----------------------------



## License

GPL-2.0-or-later.
