import React, { useState } from "react";
import { StyleSheet, Switch, Text, View } from "react-native";
import { Bell, BellRing, Lock, Mail, MessageSquare, type LucideIcon } from "lucide-react-native";
import { AccountApi, NotificationPreferences } from "@/api/client";
import { Button, Card, Screen } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { AgentButton, AgentCard, AgentSection, AgentShell } from "@/components/agent";
import { AgentLoadGate, AgentOfflineNote } from "@/components/security/AgentStates";
import { useLoad } from "@/hooks/useLoad";
import { useIsAgentPortal } from "@/hooks/useIsAgentPortal";
import { errorMessage } from "@/lib/purchase";
import { AGENT_NOTIF_REQUIRED, AGENT_NOTIF_SECTIONS } from "@/lib/securityActivity";
import { useTranslation } from "@/i18n";
import { openNotificationSettings, registerForPush } from "@/notifications/push";
import { colors, radius, space, type } from "@/theme/tokens";
import { agentColors as c, agentIcon, agentLayout as L, agentType as T } from "@/theme/agent";

const channelIcon = (key: string): LucideIcon => (key === "push" ? BellRing : key === "email" ? Mail : key === "sms" ? MessageSquare : Bell);

/**
 * Notification preferences (GET/PUT /mobile/account/notification-preferences).
 * One implementation; the agent portal renders the spec v2 screen 02 layout.
 */
export default function Preferences() {
  const { t, td } = useTranslation();
  const agent = useIsAgentPortal();
  const { data: value, setData: setValue, loading, error: loadError, reload } = useLoad(() => AccountApi.notificationPreferences(), []);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const toggle = async (current: NotificationPreferences, key: keyof NotificationPreferences, next: boolean) => {
    setSaved(false);
    setError(null);
    try {
      if (key === "push" && next) {
        const result = await registerForPush();
        if (result.status === "denied") {
          setError(t("notifPushDenied"));
          if (!result.canAskAgain) openNotificationSettings();
          return;
        }
        if (result.status !== "registered") {
          setError(t("errGeneric"));
          return;
        }
      }
      setValue({ ...current, [key]: next });
    } catch (e) {
      setError(errorMessage(e, t("errGeneric")));
    }
  };
  const save = async (current: NotificationPreferences) => {
    setBusy(true);
    try {
      setValue(await AccountApi.saveNotificationPreferences(current));
      setError(null);
      setSaved(true);
    } catch (e) {
      setError(errorMessage(e, t("errGeneric")));
    } finally {
      setBusy(false);
    }
  };
  const feedback = (
    <>
      {error ? <Text accessibilityRole="alert" style={agent ? a.error : styles.error}>{error}</Text> : null}
      {saved ? <Text accessibilityLiveRegion="polite" style={agent ? a.ok : styles.ok}>{t("notifPrefsSaved")}</Text> : null}
    </>
  );

  if (agent) {
    return (
      <AgentShell
        variant="drilldown"
        title={t("notifPrefsTitle")}
        footer={value ? <AgentButton label={t("agNotifSave")} loading={busy} onPress={() => void save(value)} /> : undefined}
      >
        <Text style={a.intro}>{t("agNotifSubtitle")}</Text>
        <AgentOfflineNote />
        <AgentLoadGate loading={loading} error={loadError} data={value} onRetry={() => void reload()} rows={6}>
          {(current) => (
            <>
              {AGENT_NOTIF_SECTIONS.map((section) => (
                <AgentSection key={section.id} title={td(`agNotifSec_${section.id}`, section.id)}>
                  <AgentCard padded={false}>
                    {section.rows.map((row, i) => {
                      const label = td(`agNotif_${row.id}`, row.id);
                      const key = row.key;
                      return (
                        <View key={row.id} style={[a.row, i > 0 && a.divider]}>
                          <View style={a.rowText}>
                            <Text style={[a.rowTitle, !key && a.muted]}>{label}</Text>
                            {key ? null : <Text style={a.rowSub}>{t("agNotAvailable")}</Text>}
                          </View>
                          <Switch
                            accessibilityLabel={key ? label : `${label}, ${t("agNotAvailable")}`}
                            value={key ? current[key] : false}
                            disabled={!key || busy}
                            onValueChange={(next) => {
                              if (key) void toggle(current, key, next);
                            }}
                            trackColor={{ true: c.actionBlue, false: c.borderStrong }}
                            thumbColor={c.surface}
                            {...({ activeThumbColor: c.surface } as object)}
                          />
                        </View>
                      );
                    })}
                  </AgentCard>
                  {section.shared ? <Text style={a.note}>{td(`agNotifShared_${section.id}`, "")}</Text> : null}
                </AgentSection>
              ))}
              <AgentSection title={t("agNotifSec_security")}>
                <AgentCard padded={false}>
                  {AGENT_NOTIF_REQUIRED.map((id, i) => {
                    const label = td(`agNotif_${id}`, id);
                    return (
                      <View key={id} style={[a.row, i > 0 && a.divider]} accessible accessibilityLabel={`${label}, ${t("agNotifRequired")}`}>
                        <Text style={[a.rowTitle, a.rowText]}>{label}</Text>
                        <View style={a.required}>
                          <Lock size={14} color={c.navy} strokeWidth={agentIcon.stroke} />
                          <Text style={a.requiredText}>{t("agNotifRequired").toUpperCase()}</Text>
                        </View>
                      </View>
                    );
                  })}
                </AgentCard>
                <Text style={a.note}>{t("agNotifRequiredBody")}</Text>
              </AgentSection>
              {feedback}
            </>
          )}
        </AgentLoadGate>
      </AgentShell>
    );
  }

  return (
    <Screen>
      <BrandHeader title={t("notifPrefsTitle")} back right={null} />
      <StatePanel loading={loading} error={loadError} data={value} onRetry={() => void reload()} isEmpty={() => false} loadingLabel={t("notifPrefsLoading")}>
        {(current) => {
          const keys = Object.keys(current) as (keyof NotificationPreferences)[];
          return (
            <>
              <Card style={styles.card}>
                {keys.map((key, i) => (
                  <View key={key} style={[styles.row, i === keys.length - 1 && styles.rowLast]}>
                    <TintedIcon icon={channelIcon(key)} tint={current[key] ? "blue" : "neutral"} size={40} />
                    <Text style={styles.label}>{td(`notifPref_${key}`, key)}</Text>
                    <Switch
                      accessibilityLabel={td(`notifPref_${key}`, key)}
                      value={current[key]}
                      onValueChange={(next) => void toggle(current, key, next)}
                      trackColor={{ true: colors.blue600 }}
                    />
                  </View>
                ))}
              </Card>
              {feedback}
              <Button label={t("notifPrefsSave")} loading={busy} onPress={() => void save(current)} />
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature, gap: 0 },
  row: {
    minHeight: 60,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    paddingVertical: space.x2,
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  rowLast: { borderBottomWidth: 0 },
  label: { ...type.body, color: colors.navy950, flex: 1 },
  error: { ...type.meta, color: colors.dangerText },
  ok: { ...type.meta, color: colors.successText },
});
const a = StyleSheet.create({
  intro: { ...T.body, color: c.secondary },
  row: { minHeight: L.rowMinHeight, flexDirection: "row", alignItems: "center", gap: 12, paddingHorizontal: L.cardPadding, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  rowText: { flex: 1, gap: 2 },
  rowTitle: { ...T.cardTitle, color: c.text },
  rowSub: { ...T.secondary, color: c.muted },
  muted: { color: c.secondary },
  note: { ...T.caption, color: c.secondary, paddingHorizontal: 4 },
  required: { flexDirection: "row", alignItems: "center", gap: 4, height: 26, paddingHorizontal: 10, borderRadius: 99, backgroundColor: c.surfaceSoft },
  requiredText: { ...T.caption, color: c.navy },
  error: { ...T.secondary, color: c.danger },
  ok: { ...T.secondary, color: c.success },
});
