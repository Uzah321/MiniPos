const fs = require("fs");
const path = require("path");

const packageJsonPath = path.join(__dirname, "..", "package.json");
const outputPath = path.join(__dirname, "..", "src", "buildInfo.json");

const packageJson = JSON.parse(fs.readFileSync(packageJsonPath, "utf8"));

const now = new Date();

const pad = (value) => String(value).padStart(2, "0");

const buildNumber = [
  now.getFullYear(),
  pad(now.getMonth() + 1),
  pad(now.getDate()),
  pad(now.getHours()),
  pad(now.getMinutes()),
  pad(now.getSeconds()),
].join("");

const buildInfo = {
  appName: packageJson.name || "servenest-pos-enterprise",
  version: packageJson.version || "1.0.0",
  buildNumber,
  buildTime: now.toISOString(),
  displayVersion: `${packageJson.version || "1.0.0"}-${buildNumber}`,
};

fs.writeFileSync(outputPath, JSON.stringify(buildInfo, null, 2));

console.log(`Build info generated: ${buildInfo.displayVersion}`);
