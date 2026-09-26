import React, { useCallback, useState } from "react";
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { AlertTriangle, Bell, CheckCircle2, ChevronRight, Info } from "lucide-react-native";
import { Button, Chip, ChipRow, ripple, Screen } from "@/components/ui";
import { BrandHeader, TintedIcon, type Tint } from "@/components/design";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { NotificationsApi, type CustomerNotification } from "@/api/client";
import { useTranslation } from "@/i18n";
import { resolveNotificationTarget } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

const severityIcon = (severity: CustomerNotification["severity"]) =>
  severity === "CRITICAL" || severity === "WARNING" ? AlertTriangle : severity === "SUCCESS" ? CheckCircle2 : Info;
const severityTint = (severity: CustomerNotification["severity"]): Tint =>
  severity === "CRITICAL" ? "red" : severity === "WARNING" ? "gold" : severity === "SUCCESS" ? "green" : "blue";

export default function Notifications() {
  const { t, date } = useTranslation();
  const q = useLoad(() => CustomerApi.notifications());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<"all" | "unread">("all");
  const reload = q.reload;
  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );
  const items = q.data ?? [];
  const unread = items.filter((n) => !n.read).length;
  const shown = filter === "unread" ? items.filter((n) => !n.read) : items;
  /** "Just now / 5 min ago / 3 h ago / 2 d ago", then the formatted date after a week. */
  const relative = (iso: string) => {
    const ms = Date.now() - new Date(iso).getTime();
    if (!Number.isFinite(ms) || ms < 0) return date(iso);
    const min = Math.floor(ms / 60000);
    if (min < 1) return t("relJustNow");
    if (min < 60) return t("relMinutesAgo", { count: min });
    const h = Math.floor(min / 60);
    if (h < 24) return t("relHoursAgo", { count: h });
    const d = Math.floor(h / 24);
    if (d < 7) return t("relDaysAgo", { count: d });
    return date(iso);
  };
  const open = async (n: CustomerNotification) => {
    // Opening marks it read; an item with a valid target goes straight there.
    if (!n.read) {
      q.setData(items.map((x) => (x.id === n.id ? { ...x, read: true } : x)));
      void NotificationsApi.markRead(n.id).catch(() => undefined);
    }
    const target = resolveNotificationTarget(n);
    if (target) router.push(target as never);
    else router.push({ pathname: "/notifications/[id]", params: { id: n.id } });
  };
  const markAll = async () => {
    setBusy(true);
    setError(null);
    try {
      await NotificationsApi.markAllRead();
      q.setData(items.map((n) => ({ ...n, read: true })));
    } catch (e) {
      setError(e instanceof Error ? e.message : t("actionFailed"));
    } finally {
      setBusy(false);
    }
  };
  const header = (
    <BrandHeader title={t("notifications")} subtitle={q.data ? t("unreadCount", { count: unread }) : undefined} back right={null} />
  );
  const settings = (
    <Button label={t("notificationSettings")} icon={Bell} variant="tertiary" onPress={() => router.push("/account/notifications")} />
  );
  if (!items.length)
    return (
      <Screen>
        {header}
        {q.loading && !q.data ? (
          <LoadingState label={t("notificationsLoading")} />
        ) : q.error && !q.data ? (
          <ErrorState error={q.error} onRetry={() => void q.reload()} />
        ) : (
          <EmptyState title={t("notificationsEmpty")} message={t("notificationsEmptyBody")} />
        )}
        {settings}
      </Screen>
    );
  // Virtualized inbox.
  return (
    <Screen scroll={false}>
      <FlatList
        data={shown}
        keyExtractor={(n) => n.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={q.loading} onRefresh={() => void q.reload()} />}
        ListHeaderComponent={
          <View style={styles.header}>
            {header}
            <ChipRow exclusive>
              <Chip role="tab" label={`${t("filterAll")} (${items.length})`} selected={filter === "all"} onPress={() => setFilter("all")} />
              <Chip role="tab" label={`${t("filterUnread")} (${unread})`} selected={filter === "unread"} onPress={() => setFilter("unread")} />
            </ChipRow>
            {unread ? <Button label={t("markAllRead")} variant="secondary" loading={busy} onPress={() => void markAll()} /> : null}
            {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
          </View>
        }
        ListEmptyComponent={<EmptyState title={t("notificationsEmpty")} message={t("notificationsEmptyBody")} />}
        renderItem={({ item: n }) => {
          const Icon = severityIcon(n.severity);
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`${n.read ? "" : `${t("unread")}. `}${n.title}. ${n.body}`}
              onPress={() => void open(n)}
              android_ripple={ripple()}
              style={({ pressed }) => [styles.row, !n.read && styles.rowUnread, pressed && styles.pressed]}
            >
              <TintedIcon icon={Icon} tint={n.read ? "neutral" : severityTint(n.severity)} size={44} />
              <View style={styles.flex}>
                <View style={styles.titleRow}>
                  <Text style={[styles.title, !n.read && styles.bold, styles.flex]} numberOfLines={2}>{n.title}</Text>
                  {!n.read ? <View style={styles.dot} accessibilityElementsHidden /> : null}
                </View>
                <Text style={styles.body} numberOfLines={2}>{n.body}</Text>
                <Text style={styles.meta}>{relative(n.created_at)}</Text>
              </View>
              <ChevronRight size={18} color={colors.neutral500} />
            </Pressable>
          );
        }}
        ListFooterComponent={<View style={styles.footer}>{settings}</View>}
      />
    </Screen>
  );
}
const styles = StyleSheet.create({
  content: { paddingBottom: space.x16 },
  header: { gap: space.x4, marginBottom: space.x4 },
  footer: { marginTop: space.x4 },
  row: {
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.feature,
    padding: space.x3,
    marginBottom: space.x3,
    minHeight: 72,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    overflow: "hidden",
  },
  rowUnread: { borderColor: colors.blue100 },
  pressed: { opacity: 0.85 },
  flex: { flex: 1 },
  titleRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  title: { ...type.label, color: colors.navy950, fontFamily: "Inter_500Medium" },
  bold: { fontFamily: "Inter_700Bold" },
  body: { ...type.meta, color: colors.neutral600, marginTop: 2 },
  meta: { ...type.caption, color: colors.neutral500, marginTop: 4 },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.blue600 },
  error: { ...type.meta, color: colors.dangerText },
});
