import React, { useEffect, useState } from "react";
import { Alert, Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { router } from "expo-router";
import * as LocalAuthentication from "expo-local-authentication";
import { ChevronRight, Fingerprint, History, KeyRound, Laptop, LockKeyhole, LogOut, ShieldCheck, Smartphone, SmartphoneNfc } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { AgentButton, AgentCard, AgentEmptyState, AgentNavRow, AgentSection, AgentShell, AgentStatusChip } from "@/components/agent";
import { AgentLoadGate, AgentOfflineNote } from "@/components/security/AgentStates";
import { useLoginEventLabel } from "@/components/security/loginEvent";
import { useSession } from "@/store/session";
import { AccountApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useIsAgentPortal } from "@/hooks/useIsAgentPortal";
import { loginStatus, sortSessions } from "@/lib/securityActivity";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { agentColors as c, agentIcon, agentLayout as L, agentType as T } from "@/theme/agent";
import { BiometricLock } from "@/security/biometric";
import { useRuntime } from "@/store/runtime";
import { environmentConfig } from "@/config/environment";
import { resolveLockPolicy } from "@/lib/appLock";
import { STEP_UP_PURPOSES } from "@/lib/stepUpFlow";
import { STEP_UP_CANCELLED, withStepUp } from "@/security/step-up";

export default function Security() {
  const { t } = useTranslation();
  const agent = useIsAgentPortal();
  const signOutEverywhere = useSession((s) => s.signOutEverywhere);
  const [enabled, setEnabled] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState<"bio" | "all" | null>(null);
  const runtimeSecurity = useRuntime((s) => s.bootstrap?.security);
  const policy = resolveLockPolicy(runtimeSecurity, environmentConfig);
  const devices = useLoad(() => AccountApi.devices(), []);
  // Recent security activity is agent-layout only; the customer layout links to the full list.
  const activity = useLoad(() => (agent ? AccountApi.loginActivity() : Promise.resolve([])), [agent]);
  const eventLabel = useLoginEventLabel();
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
  const lockPolicyText = t("lockPolicyBody", {
    seconds: Math.round(policy.relockGraceMs / 1000),
    minutes: Math.round(policy.idleTimeoutMs / 60000),
  });
  const openDevice = (id: string) => router.push({ pathname: "/account/device/[id]", params: { id } });

  if (agent) {
    const notAvailable = <Text style={a.na}>{t("agNotAvailable")}</Text>;
    return (
      <AgentShell variant="drilldown" title={t("secSessionsTitle")} refreshing={devices.loading} onRefresh={() => { void devices.reload(); void activity.reload(); }}>
        <AgentOfflineNote />
        <AgentSection title={t("secAccountSecurity")}>
          <AgentCard padded={false}>
            {/* Sign-in is by one-time code: there is no password, PIN or separate 2FA on the backend yet. */}
            <AgentNavRow icon={KeyRound} title={t("secPassword")} subtitle={t("secPasswordOtp")} right={notAvailable} chevron={false} divider={false} />
            <AgentNavRow icon={LockKeyhole} title={t("secTxnPin")} right={notAvailable} chevron={false} />
            <AgentNavRow icon={SmartphoneNfc} title={t("secTwoFactor")} right={notAvailable} chevron={false} />
            <View style={[a.row, a.divider]}>
              <Fingerprint size={agentIcon.row} color={agentIcon.color} strokeWidth={agentIcon.stroke} />
              <View style={a.rowText}>
                <Text style={a.rowTitle}>{t("secBiometricLogin")}</Text>
                <Text style={a.rowSub}>{enabled ? t("biometricOn") : t("biometricOff")}</Text>
              </View>
              <Switch
                accessibilityLabel={t("secBiometricLogin")}
                value={enabled}
                disabled={busy === "bio"}
                onValueChange={() => void change()}
                trackColor={{ true: c.actionBlue, false: c.borderStrong }}
                thumbColor={c.surface}
                {...({ activeThumbColor: c.surface } as object)}
              />
            </View>
            <AgentNavRow icon={ShieldCheck} title={t("secDeviceStatus")} subtitle={t("secDeviceStatusBody")} onPress={() => router.push("/security/device-status")} />
          </AgentCard>
          <Text style={a.note}>{lockPolicyText}</Text>
        </AgentSection>

        <AgentSection
          title={t("secActiveSessions")}
          action={
            <Pressable accessibilityRole="link" hitSlop={8} onPress={() => router.push("/account/devices")}>
              <Text style={a.link}>{t("secViewAll")}</Text>
            </Pressable>
          }
        >
          <AgentLoadGate loading={devices.loading} error={devices.error} data={devices.data} onRetry={() => void devices.reload()} rows={2}>
            {(list) => {
              const sorted = sortSessions(list);
              const others = sorted.filter((d) => !d.current);
              return (
                <>
                  {sorted.length ? (
                    <AgentCard padded={false}>
                      {sorted.slice(0, 5).map((d, i) => (
                        <AgentNavRow
                          key={d.id}
                          icon={/web/i.test(d.platform) ? Laptop : Smartphone}
                          title={d.name}
                          subtitle={[d.platform, d.current ? null : t("secLastActive", { date: f.dateTime(d.last_seen_at) }), d.approx_location].filter(Boolean).join(" · ")}
                          right={d.current ? <AgentStatusChip status="Current" label={t("secCurrentSession")} /> : undefined}
                          divider={i > 0}
                          accessibilityLabel={t("devOpenDetail", { name: d.name })}
                          onPress={() => openDevice(d.id)}
                        />
                      ))}
                    </AgentCard>
                  ) : null}
                  {others.length === 0 ? <AgentEmptyState icon={Laptop} title={t("secSessionsEmpty")} body={t("secSessionsEmptyBody")} /> : null}
                </>
              );
            }}
          </AgentLoadGate>
          <Text style={a.note}>{t("signOutAllBody")}</Text>
          <AgentButton label={t("signOutAll")} icon={LogOut} variant="danger" loading={busy === "all"} onPress={everywhere} />
          {message ? <Text accessibilityRole="alert" style={a.error}>{message}</Text> : null}
        </AgentSection>

        <AgentSection
          title={t("secRecentActivity")}
          action={
            <Pressable accessibilityRole="link" hitSlop={8} onPress={() => router.push("/account/login-activity")}>
              <Text style={a.link}>{t("secViewAll")}</Text>
            </Pressable>
          }
        >
          <AgentLoadGate loading={activity.loading} error={activity.error} data={activity.data} onRetry={() => void activity.reload()} rows={3}>
            {(rows) =>
              rows.length ? (
                <AgentCard padded={false}>
                  {rows.slice(0, 4).map((row, i) => (
                    <AgentNavRow
                      key={row.id}
                      icon={History}
                      title={eventLabel(row)}
                      subtitle={[row.device_name ?? row.platform ?? t("secActivityUnknownDevice"), f.dateTime(row.occurred_at)].join(" · ")}
                      status={loginStatus(row)}
                      divider={i > 0}
                      onPress={() => router.push({ pathname: "/account/login-activity/[id]", params: { id: row.id } })}
                    />
                  ))}
                </AgentCard>
              ) : (
                <AgentEmptyState icon={History} title={t("laEmpty")} body={t("laEmptyBody")} />
              )
            }
          </AgentLoadGate>
        </AgentSection>
      </AgentShell>
    );
  }

  return (
    <Screen>
      <BrandHeader title={t("secPageTitle")} subtitle={t("secPageSubtitle")} back right={null} />
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
        <Text style={styles.meta}>{lockPolicyText}</Text>
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
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
const a = StyleSheet.create({
  row: { minHeight: L.rowMinHeight, flexDirection: "row", alignItems: "center", gap: 14, paddingHorizontal: L.cardPadding, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  rowText: { flex: 1, gap: 2 },
  rowTitle: { ...T.cardTitle, color: c.text },
  rowSub: { ...T.secondary, color: c.secondary },
  na: { ...T.caption, color: c.muted, maxWidth: 110, textAlign: "right" },
  note: { ...T.caption, color: c.secondary, paddingHorizontal: 4 },
  link: { ...T.caption, color: c.actionBlue },
  error: { ...T.secondary, color: c.danger },
});
