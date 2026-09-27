import test from "node:test";
import assert from "node:assert/strict";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const app = require("../app.json");
const security = require("../plugins/withOpesInsureSecurity.js");

const sampleManifest = () => ({
  manifest: {
    $: { "xmlns:android": "http://schemas.android.com/apk/res/android" },
    "uses-permission": [{ $: { "android:name": "android.permission.INTERNET" } }],
    application: [{ $: { "android:name": ".MainApplication" }, activity: [{ $: { "android:name": ".MainActivity", "android:exported": "true" } }] }],
  },
});

test("OPS-04: CropImageActivity is forced non-exported with tools:replace", () => {
  const m = security.applyNonExportedActivities(sampleManifest(), security.DEFAULT_NON_EXPORTED_ACTIVITIES);
  assert.equal(m.manifest.$["xmlns:tools"], "http://schemas.android.com/tools");
  const crop = m.manifest.application[0].activity.find((a) => a.$["android:name"] === "com.canhub.cropper.CropImageActivity");
  assert.ok(crop);
  assert.equal(crop.$["android:exported"], "false");
  assert.equal(crop.$["tools:replace"], "android:exported");
  const main = m.manifest.application[0].activity.find((a) => a.$["android:name"] === ".MainActivity");
  assert.equal(main.$["android:exported"], "true");
  // idempotent
  security.applyNonExportedActivities(m, security.DEFAULT_NON_EXPORTED_ACTIVITIES);
  assert.equal(m.manifest.application[0].activity.filter((a) => a.$["android:name"] === crop.$["android:name"]).length, 1);
  assert.equal(crop.$["tools:replace"], "android:exported");
});

test("OPS-06: READ_EXTERNAL_STORAGE limited to maxSdkVersion 32", () => {
  const m = security.applyLegacyStorageLimit(sampleManifest());
  const read = m.manifest["uses-permission"].find((p) => p.$["android:name"] === "android.permission.READ_EXTERNAL_STORAGE");
  assert.equal(read.$["android:maxSdkVersion"], "32");
  assert.equal(read.$["tools:replace"], "android:maxSdkVersion");
});

test("OPS-06: unneeded Android permissions are blocked", () => {
  const blocked = app.expo.android.blockedPermissions;
  for (const p of [
    "android.permission.SYSTEM_ALERT_WINDOW",
    "android.permission.DOWNLOAD_WITHOUT_NOTIFICATION",
    "android.permission.WRITE_EXTERNAL_STORAGE",
    "android.permission.RECEIVE_BOOT_COMPLETED",
    "android.permission.READ_APP_BADGE",
    "com.sec.android.provider.badge.permission.WRITE",
    "com.huawei.android.launcher.permission.CHANGE_BADGE",
    "me.everything.badger.permission.BADGE_COUNT_WRITE",
  ]) assert.ok(blocked.includes(p), p);
  for (const keep of ["android.permission.CAMERA", "android.permission.RECORD_AUDIO", "android.permission.USE_BIOMETRIC", "android.permission.POST_NOTIFICATIONS", "android.permission.ACCESS_FINE_LOCATION", "android.permission.READ_EXTERNAL_STORAGE"]) {
    assert.ok(!blocked.includes(keep), keep);
  }
});
