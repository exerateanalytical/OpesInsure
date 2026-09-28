import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { Bell, BellRing, CircleAlert, CircleCheck } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentCard, AgentEmptyState, AgentShell, AgentSkeleton } from "@/components/agent";
import { NotificationsApi, type CustomerNotification } from "@/api/client";
import { shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

const iconFor = (n: CustomerNotification) => (n.severity === "CRITICAL" || n.severity === "WARNING" ? CircleAlert : n.severity === "SUCCESS" ? CircleCheck : n.read ? Bell : BellRing);

/** Agent inbox (AGENT_UI_SPEC_V2 drill-down from the header bell): unread rows carry a blue dot + bold title. */
export default function AgentNotifications() {
  const { t } = useTranslation();
  const q = useLoad(() => NotificationsApi.list());
  return (
    <AgentShell variant="drilldown" title={t("portalNotifTitle")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <Text style={s.subtitle}>{t("portalNotifSubtitle")}</Text>
      {q.loading && !q.data ? (
        <AgentSkeleton rows={5} height={62} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={Bell} title={t("agNotifLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
      ) : !q.data || q.data.length === 0 ? (
        <AgentEmptyState icon={Bell} title={t("portalNotifEmpty")} body={t("portalNotifEmptyBody")} />
      ) : (
        <AgentCard padded={false}>
          {q.data.map((n, i) => {
            const Icon = iconFor(n);
            const alert = n.severity === "CRITICAL";
            return (
              <View key={n.id} style={[s.row, i > 0 && s.divider]} accessible accessibilityLabel={[n.read ? null : t("agNotifUnread"), n.title, n.body, shortDate(n.created_at)].filter(Boolean).join(", ")}>
                <Icon size={agentIcon.row} color={alert ? c.danger : agentIcon.color} strokeWidth={agentIcon.stroke} />
                <View style={s.text}>
                  <Text style={[s.title, !n.read && s.unread]}>{n.title}</Text>
                  {n.body ? <Text style={s.body}>{n.body}</Text> : null}
                  <Text style={s.meta}>{shortDate(n.created_at)}</Text>
                </View>
                {n.read ? null : <View style={s.dot} />}
              </View>
            );
          })}
        </AgentCard>
      )}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  subtitle: { ...T.secondary, color: c.secondary, textAlign: "center", marginTop: -8 },
  row: { minHeight: 62, flexDirection: "row", alignItems: "flex-start", gap: 12, paddingHorizontal: 16, paddingVertical: 14 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  text: { flex: 1, gap: 2 },
  title: { ...T.cardTitle, fontFamily: "Inter_500Medium", color: c.text },
  unread: { fontFamily: "Inter_700Bold" },
  body: { ...T.secondary, color: c.secondary },
  meta: { ...T.caption, color: c.muted, marginTop: 2 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: c.actionBlue, marginTop: 7 },
});
