// Check tracked files only. Report paths and reasons, never credential values.
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";

const tracked = execFileSync("git", ["ls-files", "-z"], { encoding: "utf8" })
  .split("\0").filter(Boolean);
const privatePath = /(?:^|\/)(?:\.env(?!\.example$)(?:\.[^/]*)?|\.?deploy\.env|\.local|\.site-content|\.claude|\.ssh)(?:\/|$)|^(?:backend\/(?:config\.php|storage\/|vendor\/)|api\/_backend\.php|server\/admin-config\.php)|\.(?:pem|p12|pfx|key)$/i;
const failures = tracked.filter((file) => privatePath.test(file))
  .map((file) => `${file}: private file must not be tracked`);

if (tracked.includes("data/admin-accounts.json")) {
  const data = JSON.parse(readFileSync("data/admin-accounts.json", "utf8"));
  if (!Array.isArray(data.accounts) || data.accounts.length) {
    failures.push("data/admin-accounts.json: public account credentials are forbidden");
  }
}
if (failures.length) {
  console.error(failures.join("\n"));
  process.exitCode = 1;
} else {
  console.log(`Repository security check passed (${tracked.length} tracked files).`);
}
