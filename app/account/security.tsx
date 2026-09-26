import React, { useEffect, useState } from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import * as LocalAuthentication from "expo-local-authentication";
import { Fingerprint, LogOut, Smartphone } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { BiometricLock } from "@/security/biometric";
import { useRuntime } from "@/store/runtime";
import { environmentConfig } from "@/config/environment";
import { resolveLockPolicy } from "@/lib/appLock";

export default function Security() {
  const { t } = useTranslation();
  const signOutEverywhere = useSession((s) => s.signOutEverywhere);
  const [enabled, setEnabled] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState<"bio" | "all" | null>(null);
  const runtimeSecurity = useRuntime((s) => s.bootstrap?.security);
  const policy = resolveLockPolicy(runtimeSecurity, environmentConfig);
  useEffect(() => {
    void BiometricLock.enabled().then(setEnabled);
  }, []);
  const change = async () => {
    setBusy("bio");
    setMessage(null);
    try {
      if (enabled) {
        await BiometricLock.disable();
        setEnabled(false);
        return;
      }
      const supported = await LocalAuthentication.hasHardwareAsync();
      const enrolled = await LocalAuthentication.isEnrolledAsync();
      if (!supported || !enrolled) {
        setMessage(t("biometricSetupFirst"));
        return;
      }
      const result = await LocalAuthentication.authenticateAsync({ promptMessage: t("biometricEnablePrompt") });
      if (result.success) {
        await BiometricLock.enable();
        setEnabled(true);
      }
    } catch (e) {
      setMessage(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(null);
    }
  };
  const everywhere = () =>
    Alert.alert(t("signOutAllTitle"), t("signOutAllConfirm"), [
      { text: t("cancel"), style: "cancel" },
      {
        text: t("signOutAll"),
        style: "destructive",
        onPress: async () => {
          setBusy("all");
          setMessage(null);
          try {
            await signOutEverywhere();
            router.replace("/(auth)/sign-in");
          } catch (e) {
            // Nothing was cleared locally: the user stays signed in and can retry.
            setMessage(e instanceof Error ? e.message : t("signOutAllFailed"));
          } finally {
            setBusy(null);
          }
        },
      },
    ]);
  return (
    <Screen>
      <BrandHeader title={t("securityDevices")} back right={null} />
      <Card style={styles.card}>
        <View style={styles.headRow}>
          <TintedIcon icon={Fingerprint} tint={enabled ? "green" : "gold"} size={56} />
          <View style={styles.flex}>
            <StatusChip label={enabled ? t("biometricOn") : t("biometricOff")} tone={enabled ? "success" : "warning"} />
            <Text style={styles.body}>{t("biometricBody")}</Text>
          </View>
        </View>
        <Text style={styles.meta}>
          {t("lockPolicyBody", {
            seconds: Math.round(policy.relockGraceMs / 1000),
            minutes: Math.round(policy.idleTimeoutMs / 60000),
          })}
        </Text>
        <Button label={enabled ? t("biometricDisable") : t("biometricEnable")} loading={busy === "bio"} onPress={() => void change()} />
      </Card>
      <Banner icon={Smartphone} tint="blue" title={t("reviewDevices")} onPress={() => router.push("/account/devices")} />
      <Card style={styles.card}>
        <SectionHeading title={t("signOutAllTitle")} icon={LogOut} />
        <Text style={styles.body}>{t("signOutAllBody")}</Text>
        <Button label={t("signOutAll")} icon={LogOut} variant="danger" loading={busy === "all"} onPress={everywhere} />
      </Card>
      {message ? <Text accessibilityRole="alert" style={styles.error}>{message}</Text> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: space.x2 },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
