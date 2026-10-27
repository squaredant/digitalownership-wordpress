import { cpSync, existsSync, mkdtempSync, mkdirSync, readFileSync, rmSync, statSync } from "node:fs";
import { tmpdir } from "node:os";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const root = resolve(fileURLToPath(new URL("..", import.meta.url)));
const pluginFile = resolve(root, "digitalownership-publication-verification.php");
const plugin = readFileSync(pluginFile, "utf8");
const version = plugin.match(/define\('DOVP_VERSION',\s*'([^']+)'\)/)?.[1];
if (!version) throw new Error("Could not determine the plugin version.");

const check = spawnSync(process.execPath, [resolve(root, "tools/check-release.mjs")], { stdio: "inherit" });
if (check.status !== 0) process.exit(check.status || 1);

const packageName = "digitalownership-publication-verification";
const dist = resolve(root, "dist");
const output = resolve(dist, `${packageName}-${version}.zip`);
const temporary = mkdtempSync(resolve(tmpdir(), "dovp-release-"));
const packageDirectory = resolve(temporary, packageName);

try {
  mkdirSync(packageDirectory, { recursive: true });
  // Keep the distributed ZIP limited to files WordPress needs at runtime and
  // the WordPress.org readme. Development documentation stays in the source repo.
  for (const entry of ["digitalownership-publication-verification.php", "includes", "readme.txt", "LICENSE"]) {
    cpSync(resolve(root, entry), resolve(packageDirectory, entry), { recursive: true });
  }
  mkdirSync(dist, { recursive: true });
  if (existsSync(output)) rmSync(output);
  const zip = spawnSync("zip", ["-qr", output, packageName], { cwd: temporary, stdio: "inherit" });
  if (zip.error) throw zip.error;
  if (zip.status !== 0) throw new Error("zip could not create the release package.");
  if (statSync(output).size > 10 * 1024 * 1024) {
    throw new Error("The WordPress.org submission ZIP must not exceed 10 MB.");
  }
  console.log(`Created ${output}`);
} finally {
  rmSync(temporary, { recursive: true, force: true });
}
