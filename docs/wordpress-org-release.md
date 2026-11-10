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

## Publish A Version Through SVN

Use the plugin slug WordPress.org assigned in its acceptance email. The slug
is the directory identifier in the plugin URL and SVN URL, for example
`example-plugin` in `https://wordpress.org/plugins/example-plugin/`. It is not
a WordPress.org username and is not necessarily the GitHub repository name.

Set it once below, then run the commands from the plugin repository root. This
flow also copies the repository's screenshots into the top-level WordPress.org
SVN `assets/` directory. Those assets are separate from the plugin ZIP.

```sh
cd /Users/samlinsen/Dropbox/2.smartcontracts/projects/intellectualpropertychain/digitalownership-wordpress

WP_ORG_SLUG='digitalownership-publication-verification'
VERSION='1.0.1'
SVN_ROOT="$HOME/wordpress-org/$WP_ORG_SLUG"
RELEASE_PARENT="$(mktemp -d)"

cleanup() {
  rm -rf "$RELEASE_PARENT"
}
trap cleanup EXIT

# Obtain or update the WordPress.org SVN working copy.
if [ -d "$SVN_ROOT/.svn" ]; then
  svn update "$SVN_ROOT"
else
  mkdir -p "$(dirname "$SVN_ROOT")"
  svn checkout "https://plugins.svn.wordpress.org/$WP_ORG_SLUG/" "$SVN_ROOT"
fi

# Validate and build the exact release package.
node tools/check-release.mjs
node tools/build-release.mjs
unzip -t "dist/digitalownership-publication-verification-$VERSION.zip"

# Replace SVN trunk with the release package contents.
unzip -q "dist/digitalownership-publication-verification-$VERSION.zip" -d "$RELEASE_PARENT"
rsync -a --delete --exclude='.svn' \
  "$RELEASE_PARENT/digitalownership-publication-verification/" \
  "$SVN_ROOT/trunk/"

# Copy WordPress.org screenshots outside trunk.
mkdir -p "$SVN_ROOT/assets"
for source in screenshots/screenshot-*.PNG; do
  [ -f "$source" ] || continue
  filename="$(basename "$source" .PNG)"
  cp "$source" "$SVN_ROOT/assets/$filename.png"
done

# Schedule new release files and inspect all pending changes.
svn add --force "$SVN_ROOT/trunk" "$SVN_ROOT/assets"
svn status "$SVN_ROOT"

printf '\nReview the SVN status above. Press Enter to create tag %s and commit, or Ctrl-C to stop. ' "$VERSION"
read -r

# Create the immutable public release tag and publish trunk, assets, and tag.
svn copy "$SVN_ROOT/trunk" "$SVN_ROOT/tags/$VERSION"
svn commit "$SVN_ROOT" -m "Release version $VERSION"


```

Use the WordPress.org username that submitted the plugin, preserving its exact
capitalisation. WordPress.org supports a dedicated SVN password in account
settings; use that rather than placing a password in a command or script.

`trunk/` must contain the *contents* of the release ZIP, not its enclosing
plugin directory and not the ZIP itself. `svn copy` creates the release tag
from that exact trunk content. Directory icons, banner images, and screenshots
belong in the top-level SVN `assets/` directory; they do not belong in the
plugin ZIP or `trunk/assets/`.

The repository screenshots are currently upper-case `.PNG` files. The command
above copies them as `screenshot-1.png` through `screenshot-9.png`, matching
the captions in `readme.txt` and the WordPress.org naming convention. Review
`svn status` before committing. Do not run `svn copy` again if the `1.0.0` tag
already exists; make a new version and tag for any corrected release.

For later releases, update the version metadata and `Stable tag`, replace
`trunk/` with the newly built ZIP contents, create `tags/<new-version>` with
`svn copy`, and commit the two changes together. WordPress.org can take time
to rebuild the public download and update metadata after a commit.

Re-run this release guide whenever the plugin starts sending different data to
DigitalOwnership, changes public evidence fields, or changes its approval and
permissions model.
