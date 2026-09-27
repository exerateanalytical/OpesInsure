import React, { useRef, useState } from "react";
import { Platform, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { requireOptionalNativeModule } from "expo";
import { CameraOff, Keyboard, ScanLine } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { parseVerifyInput, verifyRoute } from "@/lib/verifyLink";
import { useTranslation } from "@/i18n";
import { colors, radius, type } from "@/theme/tokens";

/**
 * QR scanner for document verification. expo-camera is a native module that
 * older APKs do not contain: detect it with requireOptionalNativeModule and
 * only then load the JS package, otherwise show the manual-entry fallback
 * instead of crashing (OTA-safe).
 */
type CameraModule = typeof import("expo-camera");
const camera: CameraModule | null = (() => {
  try {
    if (Platform.OS !== "web" && !requireOptionalNativeModule("ExpoCamera")) return null;
    // eslint-disable-next-line @typescript-eslint/no-require-imports -- lazy: only after the native module is known to exist
    return require("expo-camera") as CameraModule;
  } catch {
    return null;
  }
})();

export default function VerifyScan() {
  const { t } = useTranslation();
  return (
    <Screen>
      <AppHeader title={t("vfScanTitle")} subtitle={t("vfSubtitle")} back />
      {camera ? <Scanner camera={camera} /> : <Unavailable body={t("vfScanUnavailable")} />}
      <Button label={t("vfEnterManually")} icon={Keyboard} variant="secondary" onPress={() => router.replace("/verify")} />
    </Screen>
  );
}

function Unavailable({ body }: { body: string }) {
  return (
    <Card>
      <CameraOff size={32} color={colors.neutral600} />
      <Text style={styles.body}>{body}</Text>
    </Card>
  );
}

function Scanner({ camera }: { camera: CameraModule }) {
  const { t } = useTranslation();
  const [permission, requestPermission] = camera.useCameraPermissions();
  const [unknown, setUnknown] = useState(false);
  const handled = useRef(false);
  if (!permission) return <Unavailable body={t("vfScanStarting")} />;
  if (!permission.granted) {
    return (
      <Card>
        <ScanLine size={32} color={colors.blue600} />
        <Text style={styles.body}>{t("vfCameraPermission")}</Text>
        {permission.canAskAgain ? <Button label={t("vfAllowCamera")} onPress={() => void requestPermission()} /> : null}
      </Card>
    );
  }
  const onScan = ({ data }: { data: string }) => {
    if (handled.current) return;
    const target = parseVerifyInput(data);
    if (!target || target.reference.length < 3) {
      setUnknown(true);
      return;
    }
    handled.current = true;
    router.replace(verifyRoute(target));
  };
  const CameraView = camera.CameraView;
  return (
    <View style={styles.frame}>
      <CameraView style={StyleSheet.absoluteFill} facing="back" barcodeScannerSettings={{ barcodeTypes: ["qr"] }} onBarcodeScanned={onScan} />
      <View style={styles.reticle} pointerEvents="none" />
      <Text style={styles.hint}>{unknown ? t("vfScanUnknown") : t("vfScanHint")}</Text>
    </View>
  );
}
const styles = StyleSheet.create({
  body: { ...type.body, color: colors.neutral700 },
  frame: { height: 360, borderRadius: radius.card, overflow: "hidden", backgroundColor: colors.navy950, alignItems: "center", justifyContent: "center" },
  reticle: { width: 220, height: 220, borderWidth: 3, borderColor: colors.white, borderRadius: radius.card, opacity: 0.85 },
  hint: { ...type.meta, color: colors.white, position: "absolute", bottom: 16, left: 16, right: 16, textAlign: "center" },
});
