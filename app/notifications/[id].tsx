import React from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ExternalLink } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { NotificationsApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import { resolveNotificationTarget } from "@/lib/customerLogic";
import { colors, type } from "@/theme/tokens";

export default function NotificationDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const { t, td, date } = useTranslation();
  // Opening the detail marks it read and returns the fresh record.
  const q = useLoad(() => NotificationsApi.markRead(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("notification")} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loading")}>
        {(n) => {
          const target = resolveNotificationTarget(n);
          return (
            <>
              <Card feature>
                <StatusChip
                  label={td(`severity_${n.severity}`, n.severity)}
                  tone={n.severity === "WARNING" ? "warning" : n.severity === "CRITICAL" ? "danger" : n.severity === "SUCCESS" ? "success" : "info"}
                />
                <Text accessibilityRole="header" style={styles.title}>{n.title}</Text>
                <Text style={styles.body}>{n.body}</Text>
                <Text style={styles.meta}>{date(n.created_at)}</Text>
              </Card>
              {target ? <Button label={t("openRelated")} icon={ExternalLink} onPress={() => router.push(target as never)} /> : null}
            </>
          );
        }}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
