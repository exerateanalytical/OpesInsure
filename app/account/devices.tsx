import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight, Laptop, Smartphone } from "lucide-react-native";
import { AccountApi } from "@/api/client";
import { Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { useFormatters } from "@/hooks/useFormatters";
import { sortSessions } from "@/lib/securityActivity";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Signed-in devices (GET /mobile/account/devices), current first. Each row
 * opens the device detail, the one place that shows every field and revokes
 * (with a confirmation).
 */
export default function Devices() {
  const { t } = useTranslation();
  const f = useFormatters();
  const { data, loading, error, reload } = useLoad(() => AccountApi.devices(), []);
  return (
    <Screen>
      <BrandHeader title={t("devTitle")} subtitle={t("secDevicesBody")} back right={null} />
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
            {sortSessions(devices).map((device) => (
              <Card key={device.id} style={styles.card} onPress={() => router.push({ pathname: "/account/device/[id]", params: { id: device.id } })} accessibilityLabel={t("devOpenDetail", { name: device.name })}>
                <View style={styles.row}>
                  <TintedIcon icon={/web/i.test(device.platform) ? Laptop : Smartphone} tint={device.current ? "green" : "blue"} size={44} />
                  <View style={styles.flex}>
                    <View style={styles.topRow}>
                      <Text style={[styles.title, styles.grow]}>{device.name}</Text>
                      {device.current ? <StatusChip label={t("devThis")} tone="success" /> : null}
                    </View>
                    <Text style={styles.body}>{t("devLastSeen", { platform: device.platform, date: f.dateTime(device.last_seen_at) })}</Text>
                    {device.approx_location ? <Text style={styles.body}>{t("devApproxLocation", { place: device.approx_location })}</Text> : null}
                  </View>
                  <ChevronRight size={20} color={colors.neutral500} />
                </View>
              </Card>
            ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: 2 },
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  topRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: space.x2 },
  grow: { flexBasis: 100, flexGrow: 1, flexShrink: 1 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.meta, color: colors.neutral600 },
});
