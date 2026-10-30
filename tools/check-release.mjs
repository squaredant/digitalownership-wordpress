import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(fileURLToPath(new URL("..", import.meta.url)));
const read = (relativePath) => readFileSync(resolve(root, relativePath), "utf8");
const fail = (message) => {
  throw new Error(`Release check failed: ${message}`);
};

const plugin = read("digitalownership-publication-verification.php");
const wordpressReadme = read("readme.txt");
const implementation = read("includes/class-dovp-plugin.php");
const license = read("LICENSE");

const headerVersion = plugin.match(/\* Version:\s*([^\s]+)/)?.[1];
const runtimeVersion = plugin.match(/define\('DOVP_VERSION',\s*'([^']+)'\)/)?.[1];
const stableTag = wordpressReadme.match(/^Stable tag:\s*([^\s]+)$/m)?.[1];
if (!headerVersion || headerVersion !== runtimeVersion || headerVersion !== stableTag) {
  fail("plugin header, DOVP_VERSION, and readme stable tag must match");
}

if (!plugin.includes('Plugin URI: https://digitalownership.squaredant.com/')) {
  fail("plugin header must identify the public service URL");
}
if (!plugin.includes('License: GPLv2 or later') || !plugin.includes('License URI: https://www.gnu.org/licenses/gpl-2.0.html')) {
  fail("plugin header must identify the GPLv2-or-later license");
}
if (!wordpressReadme.includes('License: GPLv2 or later') || !wordpressReadme.includes('License URI: https://www.gnu.org/licenses/gpl-2.0.html')) {
  fail("WordPress readme must identify the GPLv2-or-later license");
}
if (!license.includes('GNU General Public License') || !license.includes('either version 2 of the License') || !/any later\s+version/.test(license)) {
  fail("the distributed package must include a GPLv2-or-later license notice");
}
if (!implementation.includes("wp_add_privacy_policy_content")) {
  fail("plugin must provide WordPress privacy-policy suggested text");
}
if (implementation.includes("echo '<style>")) {
  fail("preview styles must be loaded through the WordPress stylesheet queue");
}
if (!implementation.includes("wp_enqueue_style($handle)") || !implementation.includes("wp_print_styles(array($handle))")) {
  fail("standalone preview pages must enqueue and print their stylesheet");
}
for (const requiredDisclosure of [
  "== External service and privacy ==",
  "https://digitalownership.squaredant.com/terms",
  "https://digitalownership.squaredant.com/privacy",
  "does not send the post body, local image bytes, attachments, or other original file bytes",
]) {
  if (!wordpressReadme.includes(requiredDisclosure)) {
    fail(`readme must disclose ${requiredDisclosure}`);
  }
}

if (!implementation.includes("header('Access-Control-Allow-Origin: https://digitalownership.squaredant.com');")) {
  fail("publication artifacts must allow the independent verifier origin");
}
if (!implementation.includes("file_put_contents($final_path, $contents, LOCK_EX)")) {
  fail("completed manifests must be stored as their exact approved bytes");
}
if (implementation.includes("file_put_contents($final_path, wp_json_encode($manifest")) {
  fail("completed manifests must not be reformatted before storage");
}
if (!implementation.includes("return add_query_arg('manifest', $manifest_url, $base);")) {
  fail("the verification link must supply the manifest URL");
}
if (!implementation.includes("$record['manifestDraft'] = $contents;")) {
  fail("generated manifests must remain private until their registration completes");
}
if (!implementation.includes("hash_equals($expected_hash, hash('sha512', $contents))")) {
  fail("published manifests must match the exact approved draft bytes");
}
if (!implementation.includes("'/publication-identity'")) {
  fail("the manifest workflow must obtain its public registration identity from the linked account");
}
if (!implementation.includes('id="dovp-action-form"') || !implementation.includes('form="dovp-action-form"')) {
  fail("evidence actions must submit through the dedicated admin form");
}
if (implementation.includes('dovp_register_publication') || implementation.includes('id="dovp-format"')) {
  fail("the editor must expose only the account-reviewed registration path and fixed JSON format");
}
if (!implementation.includes("content.json") || implementation.includes("canonical_markdown")) {
  fail("new WordPress publication versions must use JSON");
}
for (const requiredWorkflowPart of [
  "META_CANDIDATE_VERSION",
  "submit_editorial_review",
  "approve_editorial_review",
  "decline_editorial_review",
  "snapshot_matches_post",
  "release_completed_version",
  "'post_status' => 'publish'",
  "review_token_valid",
  "current_user_can('manage_options')",
]) {
  if (!implementation.includes(requiredWorkflowPart)) {
    fail(`pre-publication editorial workflow must include ${requiredWorkflowPart}`);
  }
}
if (!implementation.includes("send_fixed_content_preview") || !implementation.includes("dovp_preview")) {
  fail("account review must use a version-specific rendered fixed-content preview");
}
if (!implementation.includes("'digitalownership-wordpress-canonical-content-v2'")) {
  fail("new WordPress publication versions must use the image-evidence content schema");
}
if (!implementation.includes("'assets' => $image_evidence['assets']")) {
  fail("new fixed versions must declare local image evidence");
}
if (!implementation.includes("hash_file('sha512', $path)")) {
  fail("local image evidence must use SHA-512 fingerprints");
}
if (!implementation.includes('private function fixed_image_evidence($post_id, $version, $body_html)')) {
  fail("fixed versions must prevent browsers from selecting an unrecorded responsive image variant");
}
if (!implementation.includes('private function serve_versioned_asset($record, $asset_index)') || !implementation.includes("'dovp_asset_index'")) {
  fail("local image evidence must use a versioned plugin asset route");
}
if (!implementation.includes("hash_equals($expected_hash, hash_file('sha512', $path))")) {
  fail("versioned image routes must check the original upload before serving it");
}
if (!implementation.includes('private function is_public_publication($post)') || !implementation.includes("'publish' === $post->post_status")) {
  fail("publication evidence must be limited to publicly published posts and pages");
}
if (!implementation.includes('remove_evidence_for_non_public_post') || !implementation.includes('remove_publication_evidence')) {
  fail("unpublished and deleted posts must remove local publication evidence");
}
if (!implementation.includes("META_PRIVATE_ARCHIVE") || !implementation.includes('archive_publication_evidence')) {
  fail("unpublished publication evidence must be retained only in the private administrator archive");
}
if (!implementation.includes('refresh_evidence_box_after_editor_save') || !implementation.includes('register_editor_status_route') || !implementation.includes("digitalownership-publication/v1")) {
  fail("the Gutenberg evidence box must refresh through its authenticated status endpoint");
}
if (!implementation.includes("'postStatus' => sanitize_key($post->post_status)") || !implementation.includes("window.location.reload()") || !implementation.includes("editor.isEditedPostDirty")) {
  fail("a clean Gutenberg editor must synchronize after background publication without discarding draft edits");
}
if (!implementation.includes("Registration complete.") || !implementation.includes("Verify live version")) {
  fail("the editor must show a clear completed-registration state");
}
if (!implementation.includes("render_registration_progress") || !implementation.includes("Register evidence file and publish")) {
  fail("the editor must show the complete registration-progress overview");
}
if (!implementation.includes("Submit for administrator review") || implementation.includes("Submit for editorial approval")) {
  fail("the author submission step must clearly distinguish administrator review from approval");
}

if (!implementation.includes("Fixed-content SHA-512:") || !implementation.includes("candidate_sha512")) {
  fail("the candidate workflow must retain the fixed-content SHA-512 across review steps");
}
if (!implementation.includes("Evidence-file SHA-512:") || !implementation.includes("manifestDraftSha512")) {
  fail("the evidence-file review step must show the distinct manifest SHA-512");
}
if (!implementation.includes("Request a new account review") || !implementation.includes("dovp_request_publication_approval")) {
  fail("an administrator must be able to replace an expired fixed-content account review");
}
if (!implementation.includes("Request a new evidence-file review") || !implementation.includes("dovp_request_manifest_approval") || !implementation.includes("manifestApprovalAttempt")) {
  fail("an administrator must be able to replace an expired evidence-file account review");
}
if (!implementation.includes('This checkbox does not register anything.') || !implementation.includes('Create private fixed version of saved content')) {
  fail("the editor must distinguish verification-link opt-in from fixed-version creation");
}
if (!implementation.includes('Publication version history') || !implementation.includes("gmdate('Y-m-d H:i:s'")) {
  fail("the editor must show timestamped links for stored public publication versions");
}
if (!implementation.includes("$manifest['supersedes'] = $record['supersedes']")) {
  fail("a selected direct predecessor must remain declared in the evidence manifest");
}
if (!implementation.includes("$manifest['previousVersions'] = $previous_versions") || !implementation.includes('public_history_candidates')) {
  fail("writers must be able to disclose selected earlier public versions in the approved evidence file");
}
if (!implementation.includes("$manifest['revisionNote'] = $revision_note") || !implementation.includes('Publisher revision note')) {
  fail("the approved evidence-file preview must disclose the public revision note");
}

console.log(`Release checks passed for DigitalOwnership Publication Verification ${headerVersion}.`);
