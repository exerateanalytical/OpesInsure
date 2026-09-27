import React, { useEffect, useState } from "react";
import { Alert, Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { router } from "expo-router";
import * as LocalAuthentication from "expo-local-authentication";
import { ChevronRight, Fingerprint, History, Laptop, LogOut, ShieldCheck, Smartphone } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { useSession } from "@/store/session";
import { AccountApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { BiometricLock } from "@/security/biometric";
import { useRuntime } from "@/store/runtime";
import { environmentConfig } from "@/config/environment";
import { resolveLockPolicy } from "@/lib/appLock";
import { STEP_UP_PURPOSES } from "@/lib/stepUpFlow";
import { STEP_UP_CANCELLED, withStepUp } from "@/security/step-up";

export default function Security() {
  const { t } = useTranslation();
  const signOutEverywhere = useSession((s) => s.signOutEverywhere);
  const [enabled, setEnabled] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState<"bio" | "all" | null>(null);
  const runtimeSecurity = useRuntime((s) => s.bootstrap?.security);
  const policy = resolveLockPolicy(runtimeSecurity, environmentConfig);
  const devices = useLoad(() => AccountApi.devices(), []);
  const f = useFormatters();
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
            // SIGN_OUT_EVERYWHERE step-up first; retried once if the server still asks.
            const done = await withStepUp(STEP_UP_PURPOSES.signOutEverywhere, () => signOutEverywhere());
            if (done === STEP_UP_CANCELLED) return;
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
      <BrandHeader title={t("secPageTitle")} subtitle={t("secPageSubtitle")} back />
      <SectionHeading title={t("secSignIn")} />
      <Card style={styles.card}>
        <View style={styles.row}>
          <TintedIcon icon={Fingerprint} tint={enabled ? "green" : "blue"} size={44} />
          <View style={styles.flex}>
            <Text style={styles.title}>{t("secBiometricLogin")}</Text>
            <Text style={styles.body}>{t("biometricBody")}</Text>
          </View>
          <Switch
            accessibilityLabel={t("secBiometricLogin")}
            value={enabled}
            disabled={busy === "bio"}
            onValueChange={() => void change()}
            trackColor={{ true: colors.blue600, false: colors.neutral300 }}
            thumbColor={colors.white}
          />
        </View>
        <StatusChip label={enabled ? t("biometricOn") : t("biometricOff")} tone={enabled ? "success" : "warning"} />
        <Text style={styles.meta}>
          {t("lockPolicyBody", {
            seconds: Math.round(policy.relockGraceMs / 1000),
            minutes: Math.round(policy.idleTimeoutMs / 60000),
          })}
        </Text>
        <Pressable accessibilityRole="button" onPress={() => router.push("/security/device-status")} style={[styles.row, styles.divider]}>
          <TintedIcon icon={ShieldCheck} tint="blue" size={44} />
          <View style={styles.flex}>
            <Text style={styles.title}>{t("secDeviceStatus")}</Text>
            <Text style={styles.body}>{t("secDeviceStatusBody")}</Text>
          </View>
          <ChevronRight size={20} color={colors.neutral500} />
        </Pressable>
      </Card>

      <SectionHeading title={t("secTrusted")} />
      <Card style={styles.card} onPress={() => router.push("/account/devices")} accessibilityLabel={t("reviewDevices")}>
        <View style={styles.row}>
          <TintedIcon icon={Laptop} tint="blue" size={44} />
          <View style={styles.flex}>
            <Text style={styles.title}>{devices.data ? t("secDevicesCount", { n: devices.data.length }) : t("reviewDevices")}</Text>
            <Text style={styles.body}>{t("secDevicesBody")}</Text>
          </View>
          <ChevronRight size={20} color={colors.neutral500} />
        </View>
      </Card>
      <Card style={styles.card} onPress={() => router.push("/account/login-activity")} accessibilityLabel={t("secActivityTitle")}>
        <View style={styles.row}>
          <TintedIcon icon={History} tint="blue" size={44} />
          <View style={styles.flex}>
            <Text style={styles.title}>{t("secActivityTitle")}</Text>
            <Text style={styles.body}>{t("secActivityLinkBody")}</Text>
          </View>
          <ChevronRight size={20} color={colors.neutral500} />
        </View>
      </Card>
      {(devices.data ?? []).slice(0, 3).map((d) => (
        <Card key={d.id} style={styles.card} onPress={() => router.push({ pathname: "/account/device/[id]", params: { id: d.id } })} accessibilityLabel={t("devOpenDetail", { name: d.name })}>
          <View style={styles.row}>
            <TintedIcon icon={Smartphone} tint={d.current ? "green" : "neutral"} size={44} />
            <View style={styles.flex}>
              <View style={styles.topRow}>
                <Text style={[styles.title, styles.grow]}>{d.name}</Text>
                {d.current ? <StatusChip label={t("devThis")} tone="success" /> : null}
              </View>
              <Text style={styles.body}>{t("devLastSeen", { platform: d.platform, date: f.dateTime(d.last_seen_at) })}</Text>
            </View>
          </View>
        </Card>
      ))}
      <Text style={styles.body}>{t("signOutAllBody")}</Text>
      <Button label={t("signOutAll")} icon={LogOut} variant="danger" loading={busy === "all"} onPress={everywhere} />
      {message ? <Text accessibilityRole="alert" style={styles.error}>{message}</Text> : null}
      <Banner icon={ShieldCheck} tint="blue" title={t("secKeepSafeTitle")} body={t("secKeepSafeBody")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  divider: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200, paddingTop: space.x3, minHeight: 48 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.body, fontSize: 13, lineHeight: 18, color: colors.neutral600 },
  topRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: space.x2 },
  grow: { flexBasis: 100, flexGrow: 1, flexShrink: 1 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
