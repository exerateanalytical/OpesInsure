const {
  AndroidConfig,
  withAndroidManifest,
  withMainActivity,
} = require("@expo/config-plugins");

function withManifestSecurity(config) {
  return withAndroidManifest(config, (result) => {
    const application = AndroidConfig.Manifest.getMainApplicationOrThrow(
      result.modResults,
    );
    application.$["android:allowBackup"] = "false";
    application.$["android:fullBackupContent"] = "false";
    application.$["android:usesCleartextTraffic"] = "false";
    return result;
  });
}

// OPS-04: third-party activities merged from libraries that must not be
// reachable by other apps (e.g. the vanniktech/canhub cropper declares
// CropImageActivity exported=true; the app only uses ExpoCropImageActivity).
const DEFAULT_NON_EXPORTED_ACTIVITIES = ["com.canhub.cropper.CropImageActivity"];
// OPS-06: legacy storage read is only needed up to Android 12L (API 32).
const LEGACY_READ_STORAGE = "android.permission.READ_EXTERNAL_STORAGE";
const LEGACY_READ_STORAGE_MAX_SDK = "32";

function ensureToolsNamespace(manifest) {
  manifest.$ = manifest.$ ?? {};
  if (!manifest.$["xmlns:tools"]) manifest.$["xmlns:tools"] = "http://schemas.android.com/tools";
}

function addToolsReplace(node, attribute) {
  const current = (node.$["tools:replace"] ?? "").split(",").map((v) => v.trim()).filter(Boolean);
  if (!current.includes(attribute)) current.push(attribute);
  node.$["tools:replace"] = current.join(",");
}

function applyNonExportedActivities(androidManifest, activities) {
  ensureToolsNamespace(androidManifest.manifest);
  const application = AndroidConfig.Manifest.getMainApplicationOrThrow(androidManifest);
  application.activity = application.activity ?? [];
  for (const name of activities) {
    let activity = application.activity.find((a) => a.$?.["android:name"] === name);
    if (!activity) {
      activity = { $: { "android:name": name } };
      application.activity.push(activity);
    }
    activity.$["android:exported"] = "false";
    addToolsReplace(activity, "android:exported");
  }
  return androidManifest;
}

function applyLegacyStorageLimit(androidManifest) {
  const manifest = androidManifest.manifest;
  ensureToolsNamespace(manifest);
  manifest["uses-permission"] = manifest["uses-permission"] ?? [];
  let perm = manifest["uses-permission"].find((p) => p.$?.["android:name"] === LEGACY_READ_STORAGE);
  if (!perm) {
    perm = { $: { "android:name": LEGACY_READ_STORAGE } };
    manifest["uses-permission"].push(perm);
  }
  // A blockedPermissions entry (tools:node="remove") wins: never re-add it.
  if (perm.$["tools:node"] === "remove") return androidManifest;
  perm.$["android:maxSdkVersion"] = LEGACY_READ_STORAGE_MAX_SDK;
  addToolsReplace(perm, "android:maxSdkVersion");
  return androidManifest;
}

function withComponentHardening(config, activities) {
  return withAndroidManifest(config, (result) => {
    applyNonExportedActivities(result.modResults, activities);
    applyLegacyStorageLimit(result.modResults);
    return result;
  });
}

function withFlagSecure(config, enabled) {
  if (!enabled) return config;
  return withMainActivity(config, (result) => {
    const marker = "OpesInsure FLAG_SECURE";
    if (result.modResults.contents.includes(marker)) return result;
    const secure = `\n    // ${marker}\n    window.setFlags(\n      android.view.WindowManager.LayoutParams.FLAG_SECURE,\n      android.view.WindowManager.LayoutParams.FLAG_SECURE\n    )`;
    result.modResults.contents = result.modResults.contents.replace(
      /super\.onCreate\((?:null|savedInstanceState)\)/,
      (match) => `${match}${secure}`,
    );
    return result;
  });
}

module.exports = function withOpesInsureSecurity(config, props = {}) {
  config = withManifestSecurity(config);
  config = withComponentHardening(
    config,
    Array.isArray(props.nonExportedActivities) ? props.nonExportedActivities : DEFAULT_NON_EXPORTED_ACTIVITIES,
  );
  return withFlagSecure(config, props.enableFlagSecure === true);
};

module.exports.applyNonExportedActivities = applyNonExportedActivities;
module.exports.applyLegacyStorageLimit = applyLegacyStorageLimit;
module.exports.DEFAULT_NON_EXPORTED_ACTIVITIES = DEFAULT_NON_EXPORTED_ACTIVITIES;
