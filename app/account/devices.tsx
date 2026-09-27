import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Smartphone } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

export default function Devices() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const { data, loading, error, reload } = useLoad(() => AccountApi.devices(), []);
  const [busy, setBusy] = useState<string | null>(null);
  const [actionError, setActionError] = useState<unknown>(null);
  const revoke = async (id: string) => {
    setBusy(id);
    setActionError(null);
    try {
      await AccountApi.revokeDevice(id);
      await reload();
    } catch (e) {
      setActionError(e);
    } finally {
      setBusy(null);
    }
  };
  return (
    <Screen>
      <BrandHeader title={t("devTitle")} back right={null} />
      {actionError ? <ErrorCard error={actionError} fallback={t("errGeneric")} /> : null}
      <StatePanel
        loading={loading}
        error={error}
        data={data}
        onRetry={() => void reload()}
        loadingLabel={t("devLoading")}
        emptyTitle={t("devEmpty")}
        emptyMessage={t("devEmptyBody")}
      >
        {(devices) => (
          <>
            {devices.map((device) => (
              <Card key={device.id} style={styles.card} onPress={() => router.push({ pathname: "/account/device/[id]", params: { id: device.id } })} accessibilityLabel={t("devOpenDetail", { name: device.name })}>
                <View style={styles.row}>
                  <TintedIcon icon={Smartphone} tint={device.current ? "green" : "neutral"} size={48} />
                  <View style={styles.flex}>
                    <Text style={styles.title}>{device.name}</Text>
                    <Text style={styles.body}>{t("devLastSeen", { platform: device.platform, date: f.dateTime(device.last_seen_at) })}</Text>
                    {/* Detail fields from newer backends; each shown only when sent. */}
                    {device.model || device.os_version || device.app_version ? (
                      <Text style={styles.body}>
                        {[device.model, device.os_version, device.app_version ? `v${device.app_version}` : null].filter(Boolean).join(" · ")}
                      </Text>
                    ) : null}
                    {device.approx_location ? <Text style={styles.body}>{t("devApproxLocation", { place: device.approx_location })}</Text> : null}
                    {device.first_seen_at ? <Text style={styles.body}>{t("devFirstSeen", { date: f.dateTime(device.first_seen_at) })}</Text> : null}
                    {device.last_auth_method ? <Text style={styles.body}>{t("devLastAuth", { method: device.last_auth_method.replaceAll("_", " ").toLowerCase() })}</Text> : null}
                    {device.attestation_status ? (
                      <View style={styles.chipStart}>
                        <StatusChip
                          label={td(`devAttest_${device.attestation_status.toUpperCase()}`, device.attestation_status)}
                          tone={device.attestation_status.toUpperCase() === "PASS" ? "success" : device.attestation_status.toUpperCase() === "FAIL" ? "danger" : "neutral"}
                        />
                      </View>
                    ) : null}
                  </View>
                  {device.current ? <StatusChip label={t("devThis")} tone="success" /> : null}
                </View>
                {!device.current ? (
                  <Button label={t("devRevoke")} variant="danger" loading={busy === device.id} onPress={() => void revoke(device.id)} />
                ) : null}
              </Card>
            ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  chipStart: { flexDirection: "row", marginTop: space.x1 },
});
