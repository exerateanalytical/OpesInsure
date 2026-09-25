#!/usr/bin/env node
/**
 * Publishes an OTA update with the SAME EXPO_PUBLIC_* env the target build
 * profile was built with (read from eas.json), to that profile's channel.
 * EXPO_PUBLIC_* values are baked into the JS bundle at update time, so a bare
 * `eas update` would take whatever is in the shell / .env.* and could flip
 * SHOW_DEMO_LOGIN or blank the API URL for a whole population.
 *
 *   node scripts/publish-update.mjs production-apk --message "..."
 *   node scripts/publish-update.mjs production --message "..." --dry-run
 *
 * Runs `npm run verify` and the release doctor first; refuses on failure.
 */
import { readFileSync } from "node:fs";
import { spawnSync } from "node:child_process";

const [profileName, ...rest] = process.argv.slice(2);
const dryRun = rest.includes("--dry-run");
const passThrough = rest.filter((a) => a !== "--dry-run");
const eas = JSON.parse(readFileSync("eas.json", "utf8"));
const profile = eas.build[profileName ?? ""];
if (!profile) {
  process.stderr.write(`Unknown build profile "${profileName}". Use one of: ${Object.keys(eas.build).join(", ")}\n`);
  process.exit(1);
}
const parent = profile.extends ? eas.build[profile.extends] : {};
const env = { ...(parent?.env ?? {}), ...(profile.env ?? {}) };
const channel = profile.channel ?? parent?.channel;
if (!channel) {
  process.stderr.write(`Profile "${profileName}" has no update channel.\n`);
  process.exit(1);
}
if (env.EXPO_PUBLIC_APP_ENV === "production" && !String(env.EXPO_PUBLIC_API_BASE_URL ?? "").startsWith("https://")) {
  process.stderr.write("PRODUCTION_HTTPS_API_REQUIRED: the profile env has no https API URL.\n");
  process.exit(1);
}
const run = (cmd, args, extraEnv = {}) => {
  const r = spawnSync(cmd, args, { stdio: "inherit", shell: true, env: { ...process.env, ...extraEnv } });
  if (r.status !== 0) process.exit(r.status ?? 1);
};
run("npm", ["run", "verify"]);
run("node", ["scripts/release-doctor.mjs", ...(env.EXPO_PUBLIC_APP_ENV === "production" ? ["--production"] : []), ...(profileName === "production" ? ["--store"] : [])], env);
const args = ["eas", "update", "--channel", channel, "--non-interactive", ...passThrough];
process.stdout.write(`Publishing to channel "${channel}" with ${JSON.stringify(env)}\n`);
if (dryRun) {
  process.stdout.write(`[dry-run] npx ${args.join(" ")}\n`);
  process.exit(0);
}
run("npx", args, env);
