import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { History, ShieldAlert } from "lucide-react-native";
import { AccountApi, LoginActivity } from "@/api/client";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { AgentCard, AgentEmptyState, AgentShell, AgentStatusChip } from "@/components/agent";
import { AgentLoadGate, AgentOfflineNote } from "@/components/security/AgentStates";
import { useLoginEventLabel } from "@/components/security/loginEvent";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useIsAgentPortal } from "@/hooks/useIsAgentPortal";
import { loginStatus, riskFlags, timeOf } from "@/lib/securityActivity";
import { useTimezone } from "@/store/timezone";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";
import { agentColors as c, agentIcon, agentLayout as L, agentType as T } from "@/theme/agent";

/**
 * Login Event Detail (agent spec v2, behind screen 04). Read from the signed-in
 * user's own login activity; location is server-derived, never GPS.
 */
export default function LoginEventDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const agent = useIsAgentPortal();
  const f = useFormatters();
  const timeZone = useTimezone((s) => s.timezone);
  const eventLabel = useLoginEventLabel();
  const { data, loading, error, reload } = useLoad(async () => {
    const all = await AccountApi.loginActivity();
    return all.find((row) => row.id === id) ?? null;
  }, [id]);

  const fields = (row: LoginActivity): [string, string | null | undefined][] => [
    [t("laFieldDevice"), row.device_name],
    [t("laFieldPlatform"), row.platform],
    [t("laFieldApp"), row.app_version ? `OpesInsure v${row.app_version}` : null],
    [t("laFieldMethod"), row.method ? row.method.replaceAll("_", " ").toLowerCase() : null],
    // The API reports only a country code today; city is shown as not reported.
    [t("laFieldCity"), null],
    [t("laFieldCountry"), row.country_code],
    [t("laFieldDate"), f.date(row.occurred_at)],
    [t("laFieldTime"), timeOf(row.occurred_at, timeZone, f.language)],
    [t("laFieldRecognized"), row.new_device ? t("no") : t("yes")],
    [t("laFieldIp"), row.masked_ip],
  ];

  if (agent) {
    return (
      <AgentShell variant="drilldown" title={t("laDetailTitle")}>
        <AgentOfflineNote />
        <AgentLoadGate loading={loading} error={error} data={loading || error ? undefined : { row: data }} onRetry={() => void reload()} rows={5}>
          {({ row }) => {
            if (!row) return <AgentEmptyState icon={History} title={t("laDetailTitle")} body={t("laDetailMissing")} />;
            const status = loginStatus(row);
            return (
              <>
                <AgentCard style={a.hero}>
                  {status === "Suspicious" ? (
                    <ShieldAlert size={28} color={c.danger} strokeWidth={agentIcon.stroke} />
                  ) : (
                    <History size={28} color={agentIcon.color} strokeWidth={agentIcon.stroke} />
                  )}
                  <Text style={a.heroTitle}>{eventLabel(row)}</Text>
                  <Text style={a.heroSub}>{f.dateTime(row.occurred_at)}</Text>
                </AgentCard>
                <AgentCard padded={false}>
                  <View style={a.field}>
                    <Text style={a.label}>{t("laFieldStatus")}</Text>
                    <AgentStatusChip status={status} />
                  </View>
                  {fields(row).map(([label, value]) => (
                    <View key={label} style={[a.field, a.divider]}>
                      <Text style={a.label}>{label}</Text>
                      <Text style={[a.value, !value && a.muted]}>{value || t("devFieldNotReported")}</Text>
                    </View>
                  ))}
                  {riskFlags(row).map((flag) => (
                    <View key={flag} style={[a.field, a.divider]}>
                      <Text style={[a.value, { color: c.danger }]}>{td(`loginFlag_${flag}`, flag.replaceAll("_", " ").toLowerCase())}</Text>
                    </View>
                  ))}
                </AgentCard>
              </>
            );
          }}
        </AgentLoadGate>
      </AgentShell>
    );
  }

  return (
    <Screen>
      <BrandHeader title={t("laDetailTitle")} back right={null} />
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        isEmpty={(d) => d === null}
        onRetry={() => void reload()}
        emptyTitle={t("laDetailTitle")}
        emptyMessage={t("laDetailMissing")}
      >
        {(row) => {
          if (!row) return null;
          const status = loginStatus(row);
          return (
            <Card style={styles.card}>
              <View style={styles.row}>
                <TintedIcon icon={status === "Suspicious" ? ShieldAlert : History} tint={status === "Suspicious" ? "red" : "blue"} size={48} />
                <Text style={[styles.title, styles.flex]}>{eventLabel(row)}</Text>
              </View>
              <StatusChip
                label={td(`agentSt_${status.replace(" ", "")}`, status)}
                tone={status === "Suspicious" ? "danger" : status === "New Device" ? "warning" : status === "Recognized" ? "success" : "neutral"}
              />
              {fields(row).map(([label, value]) => (
                <View key={label} style={styles.field}>
                  <Text style={styles.label}>{label}</Text>
                  <Text style={styles.value}>{value || t("devFieldNotReported")}</Text>
                </View>
              ))}
            </Card>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  card: { borderRadius: radius.feature, gap: space.x3 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  field: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.neutral200, paddingTop: space.x2, gap: 2 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  label: { ...type.meta, color: colors.neutral600 },
  value: { ...type.body, color: colors.navy950 },
});
const a = StyleSheet.create({
  hero: { alignItems: "center", gap: 8, paddingVertical: 20 },
  heroTitle: { ...T.sectionTitle, color: c.heading, textAlign: "center" },
  heroSub: { ...T.secondary, color: c.secondary },
  field: { minHeight: 52, flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 12, paddingHorizontal: L.cardPadding, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  label: { ...T.secondary, color: c.secondary, flexShrink: 0 },
  value: { ...T.body, color: c.text, flex: 1, textAlign: "right" },
  muted: { color: c.muted },
});
