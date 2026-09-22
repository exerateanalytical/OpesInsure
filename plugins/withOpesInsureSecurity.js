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
  return withFlagSecure(config, props.enableFlagSecure === true);
};
