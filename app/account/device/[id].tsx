import React, { useState } from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Smartphone } from "lucide-react-native";
import { AccountApi, DeviceSession } from "@/api/client";
import { Button, Screen, StatusChip } from "@/components/ui";
import { BrandHeader } from "@/components/design";
import { SummaryCard, SummaryField } from "@/components/forms/SchemaSummary";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useIsAgentPortal } from "@/hooks/useIsAgentPortal";
import { AgentButton, AgentCard, AgentEmptyState, AgentShell, AgentStatusChip } from "@/components/agent";
import { AgentLoadGate, AgentOfflineNote } from "@/components/security/AgentStates";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";
import { agentColors as c, agentIcon, agentLayout as L, agentType as T } from "@/theme/agent";

/** Device detail (SEC-ACC-003). Read from the signed-in user's own device list; revoke is enforced server-side. */
export default function DeviceDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td } = useTranslation();
  const agent = useIsAgentPortal();
  const f = useFormatters();
  const { data, loading, error, reload } = useLoad(async () => {
    const all = await AccountApi.devices();
    return all.find((d) => d.id === id) ?? null;
  }, [id]);
  const [busy, setBusy] = useState(false);
  const [actionError, setActionError] = useState<unknown>(null);
  const revoke = () =>
    Alert.alert(t("devRevoke"), t("devRevokeConfirm"), [
      { text: t("cancel"), style: "cancel" },
      {
        text: t("devRevoke"),
        style: "destructive",
        onPress: async () => {
          setBusy(true);
          setActionError(null);
          try {
            await AccountApi.revokeDevice(String(id));
            router.back();
          } catch (e) {
            setActionError(e);
          } finally {
            setBusy(false);
          }
        },
      },
    ]);
  const fields = (device: DeviceSession): [string, string | null | undefined][] => [
    [t("devFieldPlatform"), [device.platform, device.os_version].filter(Boolean).join(" ")],
    [t("devFieldModel"), device.model],
    [t("devFieldApp"), device.app_version],
    [t("devFieldFirstSeen"), device.first_seen_at ? f.dateTime(device.first_seen_at) : null],
    [t("devFieldLastSeen"), f.dateTime(device.last_seen_at)],
    [t("devFieldLastAuth"), device.last_auth_method ? device.last_auth_method.replaceAll("_", " ").toLowerCase() : null],
    [t("devFieldAttestation"), device.attestation_status ? td(`devAttest_${device.attestation_status.toUpperCase()}`, device.attestation_status) : null],
    [t("devFieldLocation"), device.approx_location],
  ];

  if (agent) {
    return (
      <AgentShell variant="drilldown" title={t("secSessionDetail")}>
        <AgentOfflineNote />
        {actionError ? <ErrorCard error={actionError} fallback={t("errGeneric")} /> : null}
        <AgentLoadGate loading={loading} error={error} data={loading || error ? undefined : { device: data }} onRetry={() => void reload()} rows={5}>
          {({ device }) => {
            if (!device) return <AgentEmptyState icon={Smartphone} title={t("devDetailMissing")} body={t("devEmptyBody")} />;
            return (
              <>
                <AgentCard style={a.hero}>
                  <Smartphone size={28} color={agentIcon.color} strokeWidth={agentIcon.stroke} />
                  <Text style={a.heroTitle}>{device.name}</Text>
                  {device.current ? <AgentStatusChip status="Current" label={t("secCurrentSession")} /> : null}
                </AgentCard>
                <AgentCard padded={false}>
                  {fields(device).map(([label, value], i) => (
                    <View key={label} style={[a.field, i > 0 && a.divider]}>
                      <Text style={a.label}>{label}</Text>
                      <Text style={[a.value, !value && a.muted]}>{value || t("devFieldNotReported")}</Text>
                    </View>
                  ))}
                </AgentCard>
                {device.current ? (
                  <Text style={a.note}>{t("devCurrentHint")}</Text>
                ) : (
                  <AgentButton label={t("devRevoke")} variant="danger" loading={busy} onPress={revoke} />
                )}
              </>
            );
          }}
        </AgentLoadGate>
      </AgentShell>
    );
  }

  return (
    <Screen>
      <BrandHeader title={t("devDetailTitle")} subtitle={t("secDevicesBody")} back right={null} />
      {actionError ? <ErrorCard error={actionError} fallback={t("errGeneric")} /> : null}
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        isEmpty={(d) => d === null}
        onRetry={() => void reload()}
        emptyTitle={t("devDetailMissing")}
        emptyMessage={t("devEmptyBody")}
      >
        {(device) => {
          if (!device) return null;
          const rows = fields(device);
          return (
            <>
              <SummaryCard icon={Smartphone} title={device.name}>
                {device.current ? (
                  <View style={styles.chip}>
                    <StatusChip label={t("devThis")} tone="success" />
                  </View>
                ) : null}
                {rows.map(([label, value], i) => (
                  <SummaryField key={label} first={i === 0 && !device.current} label={label} value={value || t("devFieldNotReported")} />
                ))}
              </SummaryCard>
              {device.current ? (
                <Text style={styles.label}>{t("devCurrentHint")}</Text>
              ) : (
                <Button label={t("devRevoke")} variant="danger" loading={busy} onPress={revoke} />
              )}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  chip: { flexDirection: "row", paddingBottom: space.x2 },
  label: { ...type.meta, color: colors.neutral600 },
});
const a = StyleSheet.create({
  hero: { alignItems: "center", gap: 8, paddingVertical: 20 },
  heroTitle: { ...T.sectionTitle, color: c.heading, textAlign: "center" },
  field: { minHeight: 52, flexDirection: "row", alignItems: "center", justifyContent: "space-between", gap: 12, paddingHorizontal: L.cardPadding, paddingVertical: 10 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  label: { ...T.secondary, color: c.secondary, flexShrink: 0 },
  value: { ...T.body, color: c.text, flex: 1, textAlign: "right" },
  muted: { color: c.muted },
  note: { ...T.caption, color: c.secondary, paddingHorizontal: 4 },
});
