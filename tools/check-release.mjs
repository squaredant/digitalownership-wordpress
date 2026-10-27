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
if (!implementation.includes("file_put_contents(trailingslashit($directory) . 'manifest.json', $contents, LOCK_EX)")) {
  fail("uploaded manifests must be stored as their original bytes");
}
if (implementation.includes("file_put_contents(trailingslashit($directory) . 'manifest.json', wp_json_encode($manifest")) {
  fail("uploaded manifests must not be reformatted before storage");
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
if (!implementation.includes("$format = 'json';")) {
  fail("new WordPress publication versions must use JSON");
}
if (!implementation.includes("if ($record && !$source_changed)")) {
  fail("changed posts must not expose obsolete fixed-version approval controls");
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
if (!implementation.includes('refresh_evidence_box_after_editor_save') || !implementation.includes('enqueue_block_editor_assets')) {
  fail("the Gutenberg evidence box must refresh after an explicit editor save");
}
if (!implementation.includes('A previously verified version was withdrawn when this post was no longer public.')) {
  fail("republished posts must warn when no active verified version remains");
}
if (!implementation.includes('This checkbox does not register anything.') || !implementation.includes('Step 2: Create fixed version of saved content')) {
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
