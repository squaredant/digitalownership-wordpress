=== DigitalOwnership Publication Verification ===
Contributors: squaredant
Tags: provenance, publishing, verification, blockchain, content integrity
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.7.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Beta plugin: create fixed, verifiable versions of selected posts and pages.

== Description ==

This is beta software while it is prepared for WordPress.org review. Test the
publication and account-approval workflow with your own DigitalOwnership
account before relying on it for a production publishing process.

DigitalOwnership Publication Verification creates a fixed JSON version of selected WordPress posts and pages. When a site owner enables it for a publication, the published body is rendered from that version and includes a public verification link. The writer requests review of the fixed version. Once it is registered, the writer can choose earlier public versions to disclose and add an optional public revision note before requesting review of the separate public evidence file.

WordPress Editors can prepare content and request approval. Use an organisation-owned publishing account, such as `publications@company.example`, for the linked DigitalOwnership account. The account holder reviews a version-specific rendered preview and decides whether to approve or decline; no credit is spent when it is declined.

The plugin does not upload your content to DigitalOwnership. It stores a site-local connection token in WordPress so approved registration requests can be made without placing a reusable DigitalOwnership credential in post metadata or browser code.

== External service and privacy ==

This plugin uses the DigitalOwnership service at https://digitalownership.squaredant.com/ only after a WordPress Administrator connects the site to a DigitalOwnership account, or an Editor requests a registration that the account holder approves.

For an approved registration, the plugin sends DigitalOwnership the SHA-512 fingerprint of the fixed JSON file, its hash scope, blockchain registry details, and a request identifier. It does not send the post body, local image bytes, attachments, or other original file bytes for registration.

The plugin can publish a public evidence manifest for a completed version. That manifest contains the DigitalOwnership account registration email, the public fixed-content URL, its SHA-512 fingerprint, and public blockchain references. The email address is public by design because independent verifiers use it to derive the account-linked registration key. Use an organisation-owned publishing address instead of a personal email address, and do not use the plugin for content where this disclosure is unsuitable.

DigitalOwnership's [Terms of Service](https://digitalownership.squaredant.com/terms) and [Privacy Notice](https://digitalownership.squaredant.com/privacy) apply to the external service. The plugin also adds suggested text under **Settings > Privacy** in WordPress for site administrators to review and include in their own privacy policy.

== Installation ==

1. Upload the plugin ZIP through **Plugins > Add New > Upload Plugin**, then activate it. Alternatively, upload the extracted folder to `/wp-content/plugins/`.
2. As a WordPress Administrator, open **Settings > DigitalOwnership Verification** and select **Connect DigitalOwnership account**. Use the organisation-owned publishing account.
3. Publish a post or page, then save it. Creating a fixed version always uses the last saved content; unsaved editor changes are not included.
4. In the DigitalOwnership publication evidence box, select **Use a fixed, verifiable version of this publication and show its public verification link**, then choose **Update**. This enables the public verification link; it does not register content.
5. Select **Step 2: Create fixed version of saved content**.
6. The writer requests account review from the evidence box. The DigitalOwnership account holder reads the version-specific preview, checks the SHA-512, and approves or declines.
7. After the fixed content is registered, choose any earlier public versions readers should be able to open and verify, optionally add a public revision note, then request review of the separate public evidence file. The account holder reviews that exact file before it is registered. Refresh the publication workflow in WordPress only if it has not updated after a short wait.

Only publicly published, non-password-protected posts and pages can create or serve publication evidence. Moving a post to draft, private, or trash removes its public fixed-content and evidence files and disables their public URLs. Exact bytes are retained in a private WordPress archive that only administrators can download. If the post later becomes public again, create and register a new fixed version; archived or withdrawn evidence cannot be selected or republished as a public historical version. Permanent deletion removes that archive too.

== Changelog ==

= 0.7.5 =
* Detect the confirmed saved post timestamp in Gutenberg before refreshing the publication evidence panel.

= 0.7.4 =
* Refresh the publication evidence panel reliably after a successful manual Gutenberg save so changed content is flagged without a browser refresh.

= 0.7.3 =
* Document the activation-time evidence metadata cleanup query for Plugin Check.

= 0.7.2 =
* Document intentional public-route and administrator-only Plugin Check exceptions.

= 0.7.1 =
* Address WordPress Plugin Check filesystem, output escaping, and translation annotations.

= 0.7.0 =
* Relicense the plugin package as GPLv2 or later and include a license notice.

= 0.6.12 =
* Remove beta wording from the active documentation.

= 0.6.11 =
* Clarify that archived or withdrawn evidence remains administrator-only and cannot later be republished as public version history.

= 0.6.10 =
* Show the optional public revision note only when an earlier public registered version can be disclosed.

= 0.6.9 =
* Let writers choose earlier public registered versions and an optional public revision note before the public evidence file is reviewed.
* Show the selected version history and revision note to readers from the approved evidence file.

= 0.6.8 =
* Show an editor-only history of the fixed public versions, including timestamps and links to each fixed-content file and evidence manifest.

= 0.6.7 =
* Clarify that the per-publication checkbox enables public verification but does not start a registration.
* Show the first fixed-version action only after a public post has been enabled and saved.

= 0.6.6 =
* Show an explicit warning after a previously verified publication is made non-public and later republished.
* Refresh the publication-evidence box after an explicit Gutenberg save so changed-content warnings appear without a manual browser refresh.

= 0.6.5 =
* Retain unpublished fixed JSON and evidence files in a private administrator-only archive while removing public copies.
* Let new public manifests declare and independently verify which earlier registered version they replace.

= 0.6.4 =
* Remove stale local evidence for non-public publications when the plugin is activated or upgraded.

= 0.6.3 =
* Allow publication evidence only for publicly published, non-password-protected posts and pages.
* Remove local evidence files and disable their public routes when a publication is unpublished, made private, trashed, or deleted.

= 0.6.2 =
* Make the per-publication opt-in explicit: enabling a fixed version also enables its public verification link.

= 0.6.1 =
* Serve local images in newly created fixed versions through versioned publisher URLs that verify the recorded SHA-512 before returning bytes.
* Provide browser-verification CORS headers from the plugin route, without requiring web-server configuration for standard WordPress uploads.

= 0.6.0 =
* Prepare the public evidence-file review automatically after fixed-content registration.
* Use one consistent account-review layout for fixed content and public evidence files, including rendered previews and browser-side SHA-512 comparison.

= 0.5.9 =
* Separate writer review requests from account-holder approval or decline decisions.
* Use an immutable rendered preview of the fixed JSON file during account review.

= 0.5.8 =
* Let account holders read the fixed publication and compare its SHA-512 fingerprint before approving its registration.

= 0.5.7 =
* Hide obsolete approval and evidence controls when saved WordPress content has changed; prompt the editor to create a new fixed version instead.

= 0.5.6 =
* Clarify installation, account linking, saved-content snapshots, and the two registration approvals.

= 0.5.5 =
* Recommend an organisation-owned publishing account because its registration email is public in completed evidence manifests.

= 0.5.4 =
* Add WordPress privacy-policy suggested text and clear external-service disclosure.
* Prepare a lean review ZIP and documented Plugin Check workflow for WordPress.org submission.

= 0.5.3 =
* Add in-dashboard guidance for editorial, WordPress-administrator, and account-holder responsibilities.

= 0.5.2 =
* Clarify that a fixed version always captures the last saved WordPress content.

= 0.5.1 =
* Present fixed-content and public-evidence registration as two explicit steps.
* Keep the new-version action out of an active registration workflow.

= 0.5.0 =
* Record SHA-512 fingerprints for local WordPress upload images in new fixed JSON versions.
* Let visitors optionally check declared image bytes independently in their browser.

= 0.4.2 =
* Simplify the editor to one account-reviewed registration path and fixed JSON content.
* Remove the legacy manifest-upload route from the normal editor interface.

= 0.4.1 =
* Submit evidence actions through a dedicated WordPress admin form so they work reliably in the block editor.

= 0.4.0 =
* Generate a private manifest draft from a completed canonical registration.
* Require a separate account approval before publishing and registering the manifest.
* Publish the unchanged manifest bytes and enable verification automatically after completion.

= 0.3.4 =
* Make status refresh independent of the WordPress editor form.

= 0.3.3 =
* Submit evidence-box actions as protected POST requests through the WordPress editor form.

= 0.3.2 =
* Fix evidence-box registration actions inside the WordPress post editor.

= 0.3.1 =
* Fix a PHP parse error in the account-approval request workflow.

= 0.3.0 =
* Add linked-account canonical-file registration from the WordPress evidence box.
* Offer an explicit immediate-registration option and a stricter account-owner approval option.
* Keep manifest generation and its separate registration manual during this beta slice.

= 0.2.1 =
* Send account-link requests to the independent DigitalOwnership account page instead of falling back to the WordPress dashboard.

= 0.2.0 =
* Add a revocable DigitalOwnership account-connection flow for this WordPress installation. Registration from the WordPress editor is planned for a later beta update.

= 0.1.9 =
* Add release-package checks and clarify whether a canonical version is awaiting registration or has an attached manifest.

= 0.1.8 =
* Preserve the exact uploaded manifest bytes so an exact-file registration remains verifiable after attachment.

= 0.1.7 =
* Fix canonical JSON, Markdown, and page metadata output to use real line breaks rather than literal backslash-n characters.

= 0.1.6 =
* Add an administrator-only diagnostic query mode for beta integration troubleshooting.

= 0.1.5 =
* Resolve the canonical publication from the singular page query rather than a mutable content-filter context.

= 0.1.4 =
* Render active canonical content independently of theme loop and query-state checks.

= 0.1.3 =
* Serve canonical artifact URLs directly without a WordPress trailing-slash redirect.

= 0.1.2 =
* Render the canonical body and visible verification panel after other content filters.

= 0.1.1 =
* Fix public artifact lookup for canonical versions generated by the initial beta.

= 0.1.0 =
* Initial beta: versioned JSON and Markdown canonical artifacts, manifest attachment, CORS-enabled artifact delivery, visible verification links, and provenance discovery metadata.
