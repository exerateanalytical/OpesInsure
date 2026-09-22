import React, { useEffect } from "react";
import { Alert, StyleSheet, Text, View } from "react-native";
import { CloudOff, RefreshCw, ShieldCheck } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { useTranslation, formatCameroonDate } from "@/i18n";
import { useResilience } from "@/store/resilience";
import { colors, space, type } from "@/theme/tokens";

export default function SyncCentre() {
  const { t, language } = useTranslation();
  const { queue, summary, online, syncing, hydrate, syncNow, retry, discard } =
    useResilience();
  useEffect(() => void hydrate(), [hydrate]);
  return (
    <Screen>
      <AppHeader title={t("syncCentre")} subtitle={t("syncSubtitle")} back />
      <Card feature>
        <View style={styles.summary}>
          {online ? (
            <ShieldCheck size={26} color={colors.successText} />
          ) : (
            <CloudOff size={26} color={colors.warningText} />
          )}
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
        <Card>
          <Text style={styles.title}>{t("allSynced")}</Text>
          <Text style={styles.body}>{t("allSyncedBody")}</Text>
        </Card>
      ) : (
        queue.map((item) => (
          <Card key={item.id}>
            <View style={styles.summary}>
              <View style={styles.flex}>
                <Text style={styles.title}>{item.resource}</Text>
                <Text style={styles.body}>{item.kind} · {item.method}</Text>
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
        ))
      )}
      <Card>
        <Text style={styles.title}>{t("secureDrafts")}</Text>
        <Text style={styles.body}>{t("secureDraftsBody")}</Text>
        <Text style={styles.warning}>{t("transactionsPaused")}</Text>
      </Card>
    </Screen>
  );
}

const styles = StyleSheet.create({
  summary: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  actions: { flexDirection: "row", gap: space.x3 },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
  warning: { ...type.meta, color: colors.warningText },
});
