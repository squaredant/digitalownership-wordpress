# WordPress.org Release Guide

This guide prepares a release for the WordPress.org Plugin Directory and its
SVN release repository. Keep development in Git; use WordPress.org SVN only
for completed releases.

## Before creating the ZIP

1. Test the full approval workflow on an isolated WordPress site.
2. Connect an organisation-owned publishing account, such as
   `publications@company.example`, rather than a personal account. Its
   registration email is deliberately public in each completed evidence
   manifest.
3. Read the **External service and privacy** section in `readme.txt`. Keep it
   aligned with the actual service calls and the DigitalOwnership Terms and
   Privacy Notice.
4. Review the suggested text under **Settings > Privacy** in WordPress. A site
   owner must decide whether and how to include that text in its published
   privacy policy.
5. Confirm the plugin does not contact DigitalOwnership until an administrator
   connects an account, an editor submits an exact saved version, and a
   WordPress administrator approves that editorial request.
6. Confirm the publisher has deliberately enabled the per-post setting:
   **Use a fixed, verifiable version of this publication and show its public
   verification link.** This is the site owner's opt-in to the public
   DigitalOwnership link; the plugin does not add a site-wide credit or link.
7. Confirm that drafts and pending posts are not publicly accessible through
   any generated content, manifest, image, or workflow URL. They must remain
   private until both account-holder approvals have completed.

## WordPress.org Listing

Use the WordPress.org plugin slug assigned in the acceptance email. It defines
the public directory URL and SVN repository; it is not necessarily the GitHub
repository name. Keep the directory listing description, `readme.txt`, and the
actual service behavior aligned.

The plugin uses DigitalOwnership as an external service. `readme.txt` must
continue to disclose when the service is contacted, which data is sent, the
Terms and Privacy Notice URLs, and that a completed public manifest contains
the account registration email. Do not describe the plugin as legal compliance,
legal proof, or a guarantee of authorship or content quality.

## Build The Release ZIP

From this plugin directory, run:

```sh
node tools/check-release.mjs
node tools/build-release.mjs
```

The result is:

```text
dist/digitalownership-publication-verification-<version>.zip
```

The ZIP contains only the plugin bootstrap file, the `includes/` runtime code,
and the WordPress.org `readme.txt`. It excludes development documentation,
tools, previous ZIPs, operating-system files, and source-control files.

## Run Plugin Check

Install the official **Plugin Check** plugin in an isolated WordPress
installation, then run:

```sh
WP_PATH=/absolute/path/to/wordpress ./tools/plugin-check.sh
```

The script builds the exact review ZIP, installs/activates Plugin Check, and
runs its static scan using `wp plugin check`. It intentionally does not create
or modify publication evidence on a production website.

Plugin Check can also be run from **Tools > Plugin Check** in WordPress. Review
every error before submitting. Warnings need a documented decision: fix them,
or explain why they do not apply.

## Publish Version 1.0.0 Through SVN

Use the plugin slug WordPress.org assigned in its acceptance email. It is not
necessarily the GitHub repository name. Set it once below, then run the
commands from the plugin repository root.

```sh
WP_ORG_SLUG='replace-with-the-accepted-wordpress-org-slug'
SVN_ROOT="$HOME/wordpress-org/$WP_ORG_SLUG"
RELEASE_DIR="$(mktemp -d)/digitalownership-publication-verification"

svn checkout "https://plugins.svn.wordpress.org/$WP_ORG_SLUG/" "$SVN_ROOT"
node tools/check-release.mjs
node tools/build-release.mjs
unzip -q dist/digitalownership-publication-verification-1.0.0.zip -d "$(dirname "$RELEASE_DIR")"
rsync -a --delete "$RELEASE_DIR/" "$SVN_ROOT/trunk/"

svn add --force "$SVN_ROOT/trunk"
svn copy "$SVN_ROOT/trunk" "$SVN_ROOT/tags/1.0.0"
svn status "$SVN_ROOT"
svn commit "$SVN_ROOT" -m "Release version 1.0.0"
```

Use the WordPress.org username that submitted the plugin, preserving its exact
capitalisation. WordPress.org supports a dedicated SVN password in account
settings; use that rather than placing a password in a command or script.

`trunk/` must contain the *contents* of the release ZIP, not its enclosing
plugin directory and not the ZIP itself. `svn copy` creates the release tag
from that exact trunk content. Add directory icons, banner images, and
screenshots to `assets/` separately; they do not belong in the plugin ZIP.

For later releases, update the version metadata and `Stable tag`, replace
`trunk/` with the newly built ZIP contents, create `tags/<new-version>` with
`svn copy`, and commit the two changes together. WordPress.org can take time
to rebuild the public download and update metadata after a commit.

Re-run this release guide whenever the plugin starts sending different data to
DigitalOwnership, changes public evidence fields, or changes its approval and
permissions model.
