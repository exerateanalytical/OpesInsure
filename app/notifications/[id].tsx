import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { AlertTriangle, CalendarDays, CheckCircle2, ExternalLink, Info, ShieldAlert } from "lucide-react-native";
import { Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, TintedIcon, type Tint } from "@/components/design";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { NotificationsApi, type CustomerNotification } from "@/api/client";
import { useTranslation } from "@/i18n";
import { isSecurityNotification, resolveNotificationTarget } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

const severityIcon = (severity: CustomerNotification["severity"]) =>
  severity === "CRITICAL" || severity === "WARNING" ? AlertTriangle : severity === "SUCCESS" ? CheckCircle2 : Info;
const severityTint = (severity: CustomerNotification["severity"]): Tint =>
  severity === "CRITICAL" ? "red" : severity === "WARNING" ? "gold" : severity === "SUCCESS" ? "green" : "blue";

export default function NotificationDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  // Opening the detail marks it read and returns the fresh record.
  const q = useLoad(() => NotificationsApi.markRead(id), [id]);
  return (
    <Screen>
      <BrandHeader title={t("notification")} back right={null} />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(n) => {
          const target = resolveNotificationTarget(n);
          return (
            <>
              <Card style={styles.card}>
                <View style={styles.headRow}>
                  <TintedIcon icon={isSecurityNotification(n) ? ShieldAlert : severityIcon(n.severity)} tint={severityTint(n.severity)} size={56} />
                  <View style={styles.flex}>
                    <StatusChip
                      label={td(`severity_${n.severity}`, n.severity)}
                      tone={n.severity === "WARNING" ? "warning" : n.severity === "CRITICAL" ? "danger" : n.severity === "SUCCESS" ? "success" : "info"}
                    />
                    <Text accessibilityRole="header" style={styles.title}>{n.title}</Text>
                  </View>
                </View>
                <View style={styles.dateRow}>
                  <CalendarDays size={16} color={colors.neutral500} />
                  <Text style={styles.meta}>{date(n.created_at, true)}</Text>
                </View>
              </Card>
              <Card style={styles.card}>
                <Text style={styles.body}>{n.body}</Text>
              </Card>
              {target ? (
                <Banner icon={ExternalLink} tint="blue" title={t("openRelated")} body={t("notifRelatedBody")} onPress={() => router.push(target as never)} />
              ) : null}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: space.x2 },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x3 },
  dateRow: { flexDirection: "row", alignItems: "center", gap: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
