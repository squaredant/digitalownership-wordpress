# DigitalOwnership Publication Verification for WordPress

> **Beta software:** This plugin is being prepared for WordPress.org review.
> Test its publication and account-approval workflow with your own
> DigitalOwnership account before relying on it in production.

This WordPress plugin is the first publishing integration for the
DigitalOwnership web-publication verification protocol.

It lets an editor create a fixed version of a selected post or page. The page
is then rendered from that fixed version, rather than from a separately
maintained copy of the text. The account owner approves its registration, then
reviews a separately prepared public evidence file that visitors use to verify it.

The plugin does not upload original content to DigitalOwnership and does not
store a pipeline credential in WordPress.

## License

The plugin code is licensed under the GNU General Public License, version 2 or
later (GPLv2-or-later). See [LICENSE](LICENSE). The DigitalOwnership name and
SquaredAnt branding are not granted as trademark rights by that software
license.

## Privacy And External Service

DigitalOwnership is an external service. The plugin contacts it only after a
WordPress Administrator connects the site to a DigitalOwnership account, or an
Editor explicitly requests a registration that the account holder approves.

For an approved registration, WordPress sends the SHA-512 fingerprint of the
fixed JSON file, its hash scope, blockchain registry details, and a request
identifier. It does not send the publication body, local image bytes,
attachments, or other original file bytes for registration.

The public evidence manifest intentionally contains the linked account's
registration email, fixed-content URL, SHA-512 fingerprint, and public
blockchain references. The email is public because independent verification
uses it to derive the account-linked registry key. Do not enable publication
verification where that disclosure is unsuitable.

Review the [DigitalOwnership Terms of Service](https://digitalownership.squaredant.com/terms)
and [Privacy Notice](https://digitalownership.squaredant.com/privacy). The
plugin also adds suggested text at **Settings > Privacy** in WordPress; each
site owner must review and include it in its own privacy policy where
appropriate.

The plugin keeps version records in WordPress post metadata, generated files
under `wp-content/uploads/digitalownership-publications/`, and the site-local
connection token in the WordPress options table. They remain until a site
administrator removes the relevant metadata and generated files. Removing the
token prevents future service requests but cannot retract public evidence that
has already been published.

## Connect A DigitalOwnership Account

The plugin can link this WordPress installation to a DigitalOwnership account.
In WordPress, open **Settings > DigitalOwnership Verification** and select
**Connect DigitalOwnership account**. Sign in and approve the connection at
DigitalOwnership. The account page receives only a SHA-256 hash of a new
site-local token; the raw token is never placed in the browser URL.

Use an organisation-owned publishing account, such as
`publications@company.example`, rather than an individual's account. Its
registration email is written into each completed public evidence manifest so
that visitors can independently derive the account-linked registration key.
Treat that email address as public for every version this site publishes.

The site token is stored in the WordPress options table and can be removed in
WordPress or revoked from the linked devices list in the DigitalOwnership
account. Restrict WordPress administrator access accordingly.

## Editorial Separation Of Duties

The intended newsroom or publishing workflow does not require reporters to
have a DigitalOwnership account or a pipeline credential:

- A WordPress **Editor** writes and saves the publication, creates its fixed
  version, and may request account approval.
- A WordPress **Administrator** connects or removes the site-level
  DigitalOwnership device token.
- The DigitalOwnership **account holder** reviews the fixed content and
  fingerprint, then approves or declines each registration request.

Keep the organisation-owned DigitalOwnership account access and pipeline
credentials with the responsible senior publisher or administrator. A
WordPress Editor cannot approve a registration merely by creating its fixed
version.

The account holder does not need WordPress editing access: they review and
decide through DigitalOwnership. The review page shows a rendered view of the
version-specific fixed JSON file, rather than the mutable current WordPress
post. A person with unrestricted WordPress or server access remains an
operational trust boundary and should be controlled separately.

The plugin uses one registration path: **Request account review of fixed
publication**. The writer creates the fixed version and requests review. The
account holder reads that immutable version, checks its SHA-512 in
DigitalOwnership, then approves or declines before a credit is spent.
After that registration completes, the writer can choose which earlier public
versions readers should see, add an optional public revision note, and request
the separate evidence-file review. The plugin stores only
public ledger evidence in WordPress post metadata.

After the fixed-version registration completes, WordPress builds the evidence
file from the completed public-ledger evidence and unchanged fixed content. It
obtains the linked account email only to place it in the manifest, where it is
public by design and needed by visitors to derive the account-linked registry
key. The account page opens the evidence-file review with a rendered preview
and its distinct SHA-512 fingerprint. Once completed, WordPress publishes the
exact drafted bytes and enables the visitor-verification link.

## Release Package

Create the installable plugin ZIP from this directory:

```sh
node tools/check-release.mjs
node tools/build-release.mjs
```

This creates `dist/digitalownership-publication-verification-<version>.zip`.
Upload that ZIP through **Plugins > Add New > Upload Plugin**, or extract its
single plugin folder into `wp-content/plugins/`. The generated `dist/` folder
is not committed to Git.

For WordPress.org review packaging and the official Plugin Check scan, follow
[the WordPress.org release checklist](docs/wordpress-org-release.md). The
Plugin Check helper requires a local WordPress installation and WP-CLI:

```sh
WP_PATH=/absolute/path/to/wordpress ./tools/plugin-check.sh
```

## Scope Of This First Version

- Supports WordPress posts and pages.
- Generates JSON fixed-content files.
- Keeps each generated version under WordPress uploads; it does not overwrite
  an earlier fixed-content file.
- Serves public artifacts from versioned WordPress URLs with CORS access for
  `https://digitalownership.squaredant.com`.
- Renders the selected post/page body from its active fixed version.
- Records SHA-512 fingerprints for local WordPress upload images used by new
  fixed versions. Visitors can optionally check those image bytes directly in
  their own browser.
- Generates a `digitalownership-publication-manifest-v1` manifest from a
  completed fixed-version registration and preserves its exact registered bytes.
- Adds a human-visible **Verify this version** link, `rel="provenance"` and
  `rel="alternate"` discovery links, plus matching Schema.org JSON-LD.

The plugin verifies the selected post or page body and declared local-image
evidence. It does not verify the wider theme, third-party embeds, remote media,
authorship, or legal ownership.

## Installation

1. In WordPress, open **Plugins > Add New > Upload Plugin** and upload the
   release ZIP. Alternatively, copy the extracted
   `digitalownership-publication-verification` folder into `wp-content/plugins/`.
2. Activate **DigitalOwnership Publication Verification** from the Plugins
   screen. Activation configures the publication artifact URLs automatically.
3. As a WordPress Administrator, open **Settings > DigitalOwnership
   Verification** and select **Connect DigitalOwnership account**. Sign in with
   the organisation-owned publishing account and approve the connection.
4. Edit a post or page. Save or update it first. In the **DigitalOwnership
   publication evidence** box, select **Create fixed version of saved content**.

Creating a fixed version uses the last saved WordPress content. Unsaved block
editor changes are not included. If the post is edited after a fixed version is
created, create and register a new version before presenting the changed text
as verified.

The generated public artifact URL has this form:

```text
https://publisher.example/digitalownership-publication/42/2026-10-22t120000z/content.json
```

The files themselves are retained under:

```text
wp-content/uploads/digitalownership-publications/<post-id>/<version>/
```

Do not edit a generated artifact in place. Create and register a new version
when the publication changes.

## Publication Visibility And Removal

Only a publicly published, non-password-protected post or page can create or
serve publication evidence. Drafts, private posts, password-protected posts,
and scheduled posts cannot create a fixed version.

If a publication is moved to draft or private status, or is trashed, the plugin
removes its web-accessible fixed-content and evidence files and makes the former
versioned URLs return `404`. It retains the exact JSON and manifest bytes in a
private WordPress post-metadata archive. Only an administrator can download
those archived files from the publication evidence panel. Permanent deletion of
the WordPress post also deletes the private archive.

Removal cannot retract a file that a third party already downloaded, a copy
held by an intermediary cache, or the public-ledger fingerprint created for an
already approved registration. The ledger contains a fingerprint, not the
publication body or image bytes. A later public version can declare that it
replaces an earlier registered version without republishing the earlier text.

## Register A Version

The writer uses **Request account review of fixed publication**. The account
holder separately reads the immutable fixed-publication preview, checks the
exact SHA-512 fingerprint, and either approves or declines. WordPress provides
the readable preview and the SHA-512 before opening the DigitalOwnership
approval page. That page can independently download the fixed JSON file in the
approver's browser and compare its SHA-512 with the approval request. A decline
spends no credit; the writer can request a fresh review of the unchanged fixed
version. WordPress retrieves the public-ledger result automatically; use
**Refresh publication workflow** only if it has not updated after a short wait.

After the fixed-version status is **completed**, WordPress creates the evidence
file from the exact fixed-content file and completed registration evidence. The
account page advances to its second review, where the account owner reads the
rendered evidence file and independently checks its fingerprint. When that
registration is completed, the plugin publishes the exact approved bytes at the
versioned manifest URL and activates the visible verification link.

The WordPress server does not need a CLI credential, API key, or wallet private
key. It uses the administrator-approved site connection and each registration
still needs the linked account holder's separate approval. The fixed-content
file and the public evidence file are distinct evidence objects; each consumes
one registration credit when newly registered.

## CORS And Image Checks

The plugin automatically adds the required CORS permission to its generated
fixed-content, public-evidence, and versioned local-image URLs. Basic and
local-image verification work without further Nginx, Apache, or CDN changes
for standard WordPress uploads.

## Publish An Update Safely

A fixed version is a historical snapshot. Never overwrite it or replace
its attached manifest. When the WordPress content changes:

1. Edit and review the post or page.
2. Select **Create new fixed version**. The editor shows that verification is
   not ready for that new version.
3. Request and complete fixed-version registration.
4. The writer may select earlier public versions and add a public revision note.
   WordPress prepares the manifest and the account holder separately approves
   it. Refresh status only if the workflow has not updated after a short wait. The
   editor enables the verification link only after both registrations complete.

Earlier versioned artifact and manifest URLs remain available as historical
evidence. The visible post/page always renders the current fixed version.
When the saved post has changed, the editor hides incomplete or expired actions
for the older version and shows only the action to create a new fixed version.

The writer decides which earlier public registered versions to disclose with a
new version. Selected versions and the optional revision note are written into
the new evidence file before the account holder reviews its SHA-512 fingerprint.
Readers can then open or independently verify those selected historical
versions from the public evidence panel. Private or withdrawn versions remain
administrator-only archive material and cannot be republished through this
history feature.

## What Readers And Machines Receive

For an attached manifest, the public page includes:

```html
<link rel="alternate" type="application/json" href="...content.json">
<link rel="provenance" type="application/json" href="...manifest.json">
<link rel="alternate" type="application/vnd.digitalownership.manifest+json" href="...manifest.json">
```

`rel="provenance"` is a DigitalOwnership discovery convention, not a browser,
search-engine, or LLM quality guarantee. The plugin also emits Schema.org
metadata that describes visible publication data and links to the fixed-content
artifact and independent verification page. It does not assert that content is
true, authoritative, or high quality.

Readers select **Verify this version**. Their browser opens the independent
DigitalOwnership verifier, downloads the fixed-content file and manifest from
the publisher, and checks the published fingerprint against the public ledger.

## Content Contract

Only the post or page body is verified. The WordPress theme, navigation,
comments, cookie banner, advertisements, and other dynamic layout elements are
outside the verified area. This is intentional: registering a whole modern HTML
response is brittle and can make unchanged publication text fail verification.

Once enabled, the page body is served from the selected fixed version.
If the WordPress post is edited later, the editor sees a warning until a new
fixed version is created and registered. This prevents a changed draft
from silently replacing the verified version.

### Local Image Evidence

For each local WordPress image, the fixed version records its SHA-512
fingerprint and replaces the displayed image URL with a version-specific
verification route. The image is not copied. When a reader opens the page,
that route checks the current upload before serving it and refuses to display
it if its bytes have changed. The fixed version also removes responsive-image
attributes (`srcset` and `sizes`) so the browser cannot silently choose a
different image variant.

At the independent verifier, a reader can select **Also check declared local
images in this browser**. The reader's browser downloads each declared image
from the publisher's versioned plugin URL and hashes it locally;
DigitalOwnership does not receive, proxy, or store the image bytes. Before
returning an image, WordPress rechecks it against the SHA-512 fingerprint in
that fixed version. If the original upload was changed, replaced, or deleted,
the versioned URL refuses to return it. The verifier limits this optional check
to 25 images and 50 MB in total.

A successful fixed-content verification without the image option does not
verify image bytes. Remote images, video players, iframes, CSS backgrounds,
and third-party embeds are not image evidence in this release. Older fixed
versions retain their original registered image URLs; create a new fixed
version to use versioned image URLs after installing this release.

## Site Administrator Validation Checklist

This checklist is for the person installing or maintaining the plugin, not for
ordinary readers or editorial users publishing a normal version.

1. Generate a JSON version for a test page.
2. Confirm the page body matches the generated artifact.
3. Confirm the artifact URL and any versioned local-image URL return
   `Access-Control-Allow-Origin: https://digitalownership.squaredant.com`.
4. Request and approve the fixed-version registration in WordPress.
5. After fixed-content registration, choose any public earlier versions to
   disclose, optionally add a public revision note, and request the separate
   rendered evidence-file review.
6. Confirm the visible verification link opens
   `https://digitalownership.squaredant.com/verify-publication` with the
   manifest URL parameter.
7. Edit the WordPress page and confirm the warning appears while the public
   page still renders the previously registered fixed content.

## Release Checks

`node tools/check-release.mjs` performs source-level checks before packaging:

- plugin header, runtime constant, and WordPress stable-tag versions match;
- public artifacts are served with the required scoped CORS header;
- local WordPress-upload images use versioned, SHA-512-checked publisher URLs
  and fixed versions do not retain responsive-image selection markup;
- uploaded manifests are stored as their original bytes, not reformatted JSON;
- the public verification link uses the independent verifier and carries the
  manifest URL.

These checks do not replace testing on a real WordPress installation. Run the
test checklist above for each supported WordPress/theme combination.

## Security Notes

- Do not put `DIGITALOWNERSHIP_PIPELINE_CREDENTIAL` or a five-minute access
  token in WordPress options, post metadata, JavaScript, or the database.
- The manifest and fixed-content file are public by design. Do not use this
  plugin for confidential pages.
- Do not bypass the independent verifier with a publisher-hosted green badge.
