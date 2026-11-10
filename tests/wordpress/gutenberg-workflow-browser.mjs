import { execFileSync } from "node:child_process";
import { existsSync } from "node:fs";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright-core";

const root = resolve(fileURLToPath(new URL("../..", import.meta.url)));
const composeFile = resolve(root, "tests/wordpress/docker-compose.yml");
const chrome = "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome";
const baseUrl = "http://localhost:8099";
const autosaveWarning = "There is an autosave of this post that is more recent than the version below.";

function fail(message) {
  throw new Error(`Browser workflow test failed: ${message}`);
}

function wp(...args) {
  return execFileSync(
    "docker",
    ["compose", "-f", composeFile, "run", "--rm", "cli", ...args, "--allow-root"],
    { cwd: root, encoding: "utf8" }
  ).trim();
}

function createPost(title, content) {
  const postId = wp(
    "post",
    "create",
    "--post_type=post",
    "--post_status=draft",
    "--post_author=1",
    `--post_title=${title}`,
    `--post_content=${content}`,
    "--porcelain"
  );
  if (!/^\d+$/.test(postId)) fail("Could not create a browser-test post.");
  return postId;
}

function makeActivePublication(postId) {
  wp("post", "update", postId, "--post_status=publish");
  const code = [
    `$id = ${postId};`,
    "$version = 'browser-test-active';",
    "update_post_meta($id, '_dovp_enabled', '1');",
    "update_post_meta($id, '_dovp_active_version', $version);",
    "update_post_meta($id, '_dovp_versions', array($version => array('version' => $version, 'publiclyReleased' => true)));",
    "echo $id;",
  ].join(" ");
  wp("eval", code);
}

function adminCookies() {
  const code = [
    "$user = get_user_by('login', 'admin');",
    "$expires = time() + HOUR_IN_SECONDS;",
    "echo wp_json_encode(array(",
    "array('name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie($user->ID, $expires, 'auth'), 'path' => ADMIN_COOKIE_PATH),",
    "array('name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie($user->ID, $expires, 'logged_in'), 'path' => COOKIEPATH)",
    "));",
  ].join(" ");
  const cookies = JSON.parse(wp("eval", code));
  return cookies.map((cookie) => ({ ...cookie, domain: "localhost" }));
}

async function assertNoAutosaveWarning(page, stage) {
  const visible = await page.getByText(autosaveWarning, { exact: false }).count();
  if (visible) fail(`${stage} showed Gutenberg's newer-autosave warning.`);
}

async function dismissEditorModals(page) {
  for (let attempt = 0; attempt < 3; attempt += 1) {
    const overlay = page.locator(".components-modal__screen-overlay");
    if (!await overlay.isVisible().catch(() => false)) return;
    const close = page.locator('.components-modal__frame button[aria-label="Close dialog"], .components-modal__frame button[aria-label="Close"]').first();
    if (await close.count()) {
      await close.click({ force: true });
    } else {
      await page.keyboard.press("Escape");
    }
    await page.waitForTimeout(250);
  }
}

if (!existsSync(chrome)) {
  fail(`Google Chrome was not found at ${chrome}.`);
}

// The Docker volume persists between test runs. Reset the fixture credential
// so browser login does not depend on how the local site was created earlier.
wp("user", "update", "admin", "--user_pass=admin-password");

const browser = await chromium.launch({
  executablePath: chrome,
  headless: true,
  args: ["--no-first-run", "--no-default-browser-check"],
});
const context = await browser.newContext({ viewport: { width: 1440, height: 1200 } });
await context.addCookies(adminCookies());
const page = await context.newPage();
page.setDefaultTimeout(5000);
page.setDefaultNavigationTimeout(20000);
try {
  const postId = createPost("Browser workflow test", "Saved test body.");
  await page.goto(`${baseUrl}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(500);
  await dismissEditorModals(page);
  await page.getByRole("button", { name: "Meta Boxes" }).click({ force: true });
  const evidenceEnabled = page.locator('#dovp-publication input[name="dovp_enabled"]');
  await evidenceEnabled.evaluate((input) => input.click());
  if (!await evidenceEnabled.isChecked()) fail("The publication-evidence checkbox could not be selected.");
  await page.evaluate(() => {
    window.wp.data.dispatch("core/editor").editPost({ title: "Browser workflow test saved" });
  });

  const saveDraft = page.locator(".editor-post-save-draft, .editor-post-publish-button__button").first();
  if (await saveDraft.isDisabled()) fail("The Gutenberg save control was disabled.");
  await dismissEditorModals(page);
  await saveDraft.click();
  const firstStep = page.locator('#dovp-publication a').filter({ hasText: "Step 1: Fix saved content for review" });
  try {
    await firstStep.waitFor({ state: "attached", timeout: 10000 });
  } catch (error) {
    const enabled = wp("post", "meta", "get", postId, "_dovp_enabled");
    const panel = await page.locator("#dovp-publication").innerText().catch(() => "(panel unavailable)");
    fail(`The evidence checkbox was not available after reload; saved DOVP value: ${enabled || "(empty)"}; URL: ${page.url()}; panel: ${panel.slice(0, 500)}.`);
  }
  await assertNoAutosaveWarning(page, "Saving the enabled draft");

  const activePostId = createPost("Browser trash safeguard", "Active verification test.");
  makeActivePublication(activePostId);
  await page.goto(`${baseUrl}/wp-admin/edit.php?post_type=post`, { waitUntil: "domcontentloaded" });
  const row = page.locator(`#post-${activePostId}`);
  await row.hover();
  const trashUrl = await row.locator(".row-actions .trash a").getAttribute("href");
  if (!trashUrl) fail("The active publication had no Trash action URL.");
  await page.goto(trashUrl, { waitUntil: "domcontentloaded" });
  await page.waitForTimeout(250);
  const trashResponse = await page.locator("body").innerText();
  if (!trashResponse.includes("Withdraw public verification first")) {
    fail(`An active verified publication could be trashed without withdrawal. Response: ${trashResponse.slice(0, 500)}.`);
  }

  console.log("PASS: Gutenberg save refresh and verified-publication trash protection.");
} finally {
  await browser.close();
}
