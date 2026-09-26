import React, { useEffect } from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { AlertTriangle, CloudOff, CloudUpload, Lock, RefreshCw, ShieldCheck, TriangleAlert } from "lucide-react-native";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { useTranslation, formatCameroonDate } from "@/i18n";
import { useResilience } from "@/store/resilience";
import { colors, radius, space, type } from "@/theme/tokens";

export default function SyncCentre() {
  const { t, language } = useTranslation();
  const { queue, summary, online, syncing, hydrate, syncNow, retry, discard } =
    useResilience();
  useEffect(() => void hydrate(), [hydrate]);
  return (
    <Screen>
      <BrandHeader title={t("syncCentre")} subtitle={t("syncSubtitle")} back right={null} />
      <Card style={styles.card}>
        <View style={styles.summary}>
          <TintedIcon icon={online ? ShieldCheck : CloudOff} tint={online ? "green" : "gold"} size={56} />
          <View style={styles.flex}>
            <Text style={styles.title} accessibilityRole="header">
              {queue.length === 0 ? t("allSynced") : `${queue.length} ${t("pending")}`}
            </Text>
            <Text style={styles.body}>
              {summary.last_synced_at
                ? `${t("lastSync")}: ${formatCameroonDate(summary.last_synced_at, language)}`
                : `${t("lastSync")}: ${t("never")}`}
            </Text>
          </View>
        </View>
        <Button
          label={syncing ? t("syncing") : t("syncNow")}
          icon={RefreshCw}
          loading={syncing}
          disabled={!online}
          onPress={() => void syncNow()}
        />
      </Card>
      {queue.length === 0 ? (
        <Banner icon={ShieldCheck} tint="green" title={t("allSynced")} body={t("allSyncedBody")} />
      ) : (
        <View style={styles.list}>
          <SectionHeading title={t("syncQueueTitle")} icon={CloudUpload} />
          {queue.map((item) => {
            const tint = item.state === "FAILED" ? "red" : item.state === "CONFLICT" ? "gold" : "blue";
            return (
              <Card key={item.id} style={styles.card}>
                <View style={styles.summary}>
                  <TintedIcon icon={item.state === "FAILED" ? AlertTriangle : item.state === "CONFLICT" ? TriangleAlert : CloudUpload} tint={tint} size={44} />
                  <View style={styles.flex}>
                    <Text style={styles.itemTitle}>{item.resource}</Text>
                    <Text style={styles.meta}>{item.kind} · {item.method}</Text>
                  </View>
                  <StatusChip
                    label={
                      item.state === "FAILED"
                        ? t("failed")
                        : item.state === "CONFLICT"
                          ? t("conflict")
                          : t("pending")
                    }
                    tone={item.state === "FAILED" ? "danger" : item.state === "CONFLICT" ? "warning" : "info"}
                  />
                </View>
                {item.error_code ? <Text style={styles.error}>{item.error_code}</Text> : null}
                <View style={styles.actions}>
                  <View style={styles.flex}>
                    <Button label={t("retry")} variant="secondary" onPress={() => void retry(item.id)} />
                  </View>
                  <View style={styles.flex}>
                    <Button
                      label={t("discard")}
                      variant="danger"
                      onPress={() =>
                        Alert.alert(t("discard"), item.resource, [
                          { text: t("back"), style: "cancel" },
                          { text: t("discard"), style: "destructive", onPress: () => void discard(item.id) },
                        ])
                      }
                    />
                  </View>
                </View>
              </Card>
            );
          })}
        </View>
      )}
      <Card style={styles.card}>
        <SectionHeading title={t("secureDrafts")} icon={Lock} />
        <Text style={styles.body}>{t("secureDraftsBody")}</Text>
        <Banner icon={TriangleAlert} tint="gold" body={t("transactionsPaused")} />
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { borderRadius: radius.feature },
  list: { gap: space.x3 },
  summary: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  actions: { flexDirection: "row", gap: space.x3 },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  itemTitle: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  error: { ...type.meta, color: colors.dangerText },
});
