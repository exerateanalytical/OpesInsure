import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Bell, BellRing, CheckCheck, ChevronRight, CircleAlert, CircleCheck } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentButton, AgentCard, AgentEmptyState, AgentShell, AgentSkeleton } from "@/components/agent";
import { NotificationsApi, type CustomerNotification } from "@/api/client";
import { shortDate } from "@/api/partner";
import { markedRead, portalNotificationTarget } from "@/lib/portalNotifications";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

const iconFor = (n: CustomerNotification) => (n.severity === "CRITICAL" || n.severity === "WARNING" ? CircleAlert : n.severity === "SUCCESS" ? CircleCheck : n.read ? Bell : BellRing);

/** Agent inbox (AGENT_UI_SPEC_V2 drill-down from the header bell): unread rows carry a blue dot + bold title.
 * Opening a row marks it read (the bell badge clears on return) and follows its link when it is an agent route. */
export default function AgentNotifications() {
  const { t, language } = useTranslation();
  const q = useLoad(() => NotificationsApi.list(), [language]);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);
  const items = q.data ?? [];
  const unread = items.filter((n) => !n.read).length;
  const open = (n: CustomerNotification) => {
    if (!n.read) {
      q.setData(markedRead(items, [n.id]));
      NotificationsApi.markRead(n.id).catch(() => void q.reload());
    }
    const target = portalNotificationTarget(n.path, "agent");
    if (target) router.push(target as never);
  };
  const markAll = async () => {
    setBusy(true);
    setNotice(null);
    try {
      await NotificationsApi.markAllRead();
      q.setData(markedRead(items, "all"));
    } catch {
      setNotice(t("portalNotifMarkFailed"));
    } finally {
      setBusy(false);
    }
  };
  return (
    <AgentShell variant="drilldown" title={t("portalNotifTitle")} refreshing={q.loading && !!q.data} onRefresh={q.reload}>
      <Text style={s.subtitle}>{t("portalNotifSubtitle")}</Text>
      {unread > 0 ? <AgentButton label={t("portalNotifMarkAll")} icon={CheckCheck} variant="secondary" loading={busy} onPress={() => void markAll()} /> : null}
      {notice ? (
        <Text accessibilityRole="alert" style={s.error}>
          {notice}
        </Text>
      ) : null}
      {q.loading && !q.data ? (
        <AgentSkeleton rows={5} height={62} />
      ) : q.error && !q.data ? (
        <AgentEmptyState icon={Bell} title={t("agNotifLoadError")} body={t("loadErrorBody")} actionLabel={t("agHomeRetry")} onAction={q.reload} />
      ) : items.length === 0 ? (
        <AgentEmptyState icon={Bell} title={t("portalNotifEmpty")} body={t("portalNotifEmptyBody")} />
      ) : (
        <AgentCard padded={false}>
          {items.map((n, i) => {
            const Icon = iconFor(n);
            const alert = n.severity === "CRITICAL";
            const linked = !!portalNotificationTarget(n.path, "agent");
            return (
              <Pressable
                key={n.id}
                onPress={() => open(n)}
                style={({ pressed }) => [s.row, i > 0 && s.divider, pressed && s.pressed]}
                accessibilityRole={linked ? "link" : "button"}
                accessibilityLabel={[n.read ? null : t("agNotifUnread"), n.title, n.body, shortDate(n.created_at)].filter(Boolean).join(", ")}
              >
                <Icon size={agentIcon.row} color={alert ? c.danger : agentIcon.color} strokeWidth={agentIcon.stroke} />
                <View style={s.text}>
                  <Text style={[s.title, !n.read && s.unread]}>{n.title}</Text>
                  {n.body ? <Text style={s.body}>{n.body}</Text> : null}
                  <Text style={s.meta}>{shortDate(n.created_at)}</Text>
                </View>
                {n.read ? null : <View style={s.dot} />}
                {linked ? <ChevronRight size={18} color={c.muted} /> : null}
              </Pressable>
            );
          })}
        </AgentCard>
      )}
    </AgentShell>
  );
}

const s = StyleSheet.create({
  subtitle: { ...T.secondary, color: c.secondary, textAlign: "center", marginTop: -8 },
  error: { ...T.secondary, color: c.danger },
  row: { minHeight: 62, flexDirection: "row", alignItems: "flex-start", gap: 12, paddingHorizontal: 16, paddingVertical: 14 },
  divider: { borderTopWidth: 1, borderTopColor: c.border },
  pressed: { opacity: 0.82 },
  text: { flex: 1, gap: 2 },
  title: { ...T.cardTitle, fontFamily: "Inter_500Medium", color: c.text },
  unread: { fontFamily: "Inter_700Bold" },
  body: { ...T.secondary, color: c.secondary },
  meta: { ...T.caption, color: c.muted, marginTop: 2 },
  dot: { width: 8, height: 8, borderRadius: 4, backgroundColor: c.actionBlue, marginTop: 7 },
});
