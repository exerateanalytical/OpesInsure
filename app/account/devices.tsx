import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { AccountApi } from "@/api/client";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function Devices() {
  const { t } = useTranslation();
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
      <AppHeader title={t("devTitle")} back />
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
              <Card key={device.id}>
                {device.current ? <StatusChip label={t("devThis")} tone="success" /> : null}
                <Text style={styles.title}>{device.name}</Text>
                <Text style={styles.body}>{t("devLastSeen", { platform: device.platform, date: f.dateTime(device.last_seen_at) })}</Text>
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
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
