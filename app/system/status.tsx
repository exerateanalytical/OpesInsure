import React, { useEffect } from "react";
import { StyleSheet, Text, View } from "react-native";
import { Activity, Info, RefreshCw, Server } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { useRuntime } from "@/store/runtime";
import { environmentConfig } from "@/config/environment";
import { colors, radius, space, type } from "@/theme/tokens";

import { useTranslation } from "@/i18n";
/** "payment_gateway" -> "Payment gateway" when no copy exists for the service key. */
const humanKey = (k: string) => {
  const w = k.replaceAll("_", " ").toLowerCase();
  return w.charAt(0).toUpperCase() + w.slice(1);
};
export default function SystemStatus() {
  const { t, td } = useTranslation();
  const runtime = useRuntime((s) => s.bootstrap);
  const check = useRuntime((s) => s.check);
  useEffect(() => void check(), [check]);
  const services = runtime?.services ?? [];
  return (
    <Screen>
      <BrandHeader title={t("statusTitle")} subtitle={t("statusSubtitle")} back right={null} />
      <Card style={styles.card}>
        <View style={styles.row}>
          <TintedIcon icon={Activity} tint="blue" size={48} />
          <View style={styles.flex}>
            <Text style={styles.title}>{t("statusAppName")}</Text>
            <Text style={styles.body}>{t("statusVersion", { version: environmentConfig.appVersion, channel: environmentConfig.releaseChannel })}</Text>
          </View>
        </View>
      </Card>
      {services.length ? (
        <Card style={styles.card}>
          <SectionHeading title={t("statusTitle")} icon={Server} />
          {services.map((service, i) => {
            const tone = service.status === "OPERATIONAL" ? "success" : service.status === "DEGRADED" ? "warning" : "danger";
            return (
              <View key={service.key} style={[styles.row, styles.serviceRow, i === services.length - 1 && styles.serviceRowLast]}>
                <TintedIcon icon={Server} tint={tone === "success" ? "green" : tone === "warning" ? "gold" : "red"} size={40} />
                <View style={styles.flex}>
                  <Text style={styles.title}>{td(`statusService_${service.key}`, humanKey(service.key))}</Text>
                  {service.message ? <Text style={styles.body}>{service.message}</Text> : null}
                </View>
                <StatusChip label={td(`statusState_${service.status}`, service.status)} tone={tone} />
              </View>
            );
          })}
        </Card>
      ) : null}
      <Button label={t("statusRefresh")} icon={RefreshCw} variant="secondary" onPress={() => void check()} />
      <Banner icon={Info} tint="neutral" body={t("statusDisclaimer")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  serviceRow: { paddingVertical: space.x2, borderBottomWidth: 1, borderBottomColor: colors.neutral100 },
  serviceRowLast: { borderBottomWidth: 0 },
  flex: { flex: 1 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.meta, color: colors.neutral600, textTransform: "none" },
});
