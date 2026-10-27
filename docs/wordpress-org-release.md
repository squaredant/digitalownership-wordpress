# WordPress.org Release Checklist

This checklist prepares a review ZIP for the WordPress.org Plugin Directory. It
does not replace WordPress.org's manual review.

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
   connects an account or an editor explicitly requests a registration.
6. Confirm the publisher has deliberately enabled the per-post setting:
   **Use a fixed, verifiable version of this publication and show its public
   verification link.** This is the site owner's opt-in to the public
   DigitalOwnership link; the plugin does not add a site-wide credit or link.

## First submission

Submit from a WordPress.org account that uses a maintained SquaredAnt company
email address. WordPress.org treats the account identity as part of its
trademark and ownership review. The suggested display name is
**DigitalOwnership Publication Verification**. WordPress.org determines the
final plugin slug at submission, so do not promise a particular directory URL
until the review has accepted it.

Upload the production ZIP at:

```text
https://wordpress.org/plugins/developers/add/
```

Use this concise submission description:

> Create fixed, independently verifiable versions of selected WordPress posts
> and pages. The plugin keeps original content on the publisher's site,
> registers only SHA-512 fingerprints after separate account-owner approval,
> and gives readers a public verification link for completed versions.

The service is a permitted SaaS dependency, but the review must be able to see
the disclosure in `readme.txt`: when the service is contacted, which data is
sent, the Terms and Privacy Notice URLs, and that a completed public manifest
contains the account registration email. Do not describe the plugin as legal
compliance, legal proof, or a guarantee of authorship or content quality.

## Build the review ZIP

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

## After approval

WordPress.org provides an SVN release repository with `trunk/`, `tags/`, and
`assets/`. Keep development in Git. Use SVN only for reviewed release files:

1. Copy the contents of the release ZIP into `trunk/`, without its enclosing
   plugin directory.
2. Copy `trunk/` to `tags/<version>` using `svn copy`.
3. Add optional directory artwork to `assets/` separately; do not put those
   images inside the installable plugin ZIP.
4. Commit the completed release once. WordPress.org builds the public download
   and update metadata from the SVN repository.

## Submit and maintain

Submit the ZIP through the WordPress.org plugin submission form using the
organisation's maintained WordPress.org account. After approval, WordPress.org
provides an SVN repository. Put current development code in `trunk/`, copy each
released version to `tags/<version>/`, and keep `readme.txt`'s `Stable tag`
equal to the released tag.

Re-run this checklist whenever the plugin starts sending different data to
DigitalOwnership, changes public evidence fields, or changes its approval and
permissions model.
