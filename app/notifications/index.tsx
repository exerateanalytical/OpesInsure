import React, { useCallback, useState } from "react";
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { AlertTriangle, Bell, CheckCircle2, ChevronRight, Info } from "lucide-react-native";
import { AppHeader, Button, Screen } from "@/components/ui";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { NotificationsApi, type CustomerNotification } from "@/api/client";
import { useTranslation } from "@/i18n";
import { resolveNotificationTarget } from "@/lib/customerLogic";
import { colors, radius, space, type } from "@/theme/tokens";

const severityIcon = (severity: CustomerNotification["severity"]) =>
  severity === "CRITICAL" || severity === "WARNING" ? AlertTriangle : severity === "SUCCESS" ? CheckCircle2 : Info;

export default function Notifications() {
  const { t, date } = useTranslation();
  const q = useLoad(() => CustomerApi.notifications());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const reload = q.reload;
  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );
  const items = q.data ?? [];
  const unread = items.filter((n) => !n.read).length;
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
    <AppHeader title={t("notifications")} subtitle={q.data ? t("unreadCount", { count: unread }) : undefined} back />
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
        data={items}
        keyExtractor={(n) => n.id}
        showsVerticalScrollIndicator={false}
        contentContainerStyle={styles.content}
        refreshControl={<RefreshControl refreshing={q.loading} onRefresh={() => void q.reload()} />}
        ListHeaderComponent={
          <View style={styles.header}>
            {header}
            {unread ? <Button label={t("markAllRead")} variant="secondary" loading={busy} onPress={() => void markAll()} /> : null}
            {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
          </View>
        }
        renderItem={({ item: n, index }) => {
          const Icon = severityIcon(n.severity);
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`${n.read ? "" : `${t("unread")}. `}${n.title}. ${n.body}`}
              onPress={() => void open(n)}
              style={({ pressed }) => [styles.row, index === 0 && styles.first, index === items.length - 1 && styles.last, pressed && styles.pressed]}
            >
              <View style={[styles.icon, !n.read && styles.iconUnread]}>
                <Icon size={26} color={n.severity === "CRITICAL" ? colors.dangerText : n.read ? colors.neutral600 : colors.blue600} />
              </View>
              <View style={styles.flex}>
                <Text style={[styles.title, !n.read && styles.bold]} numberOfLines={2}>{n.title}</Text>
                <Text style={styles.body} numberOfLines={2}>{n.body}</Text>
                <Text style={styles.meta}>{date(n.created_at)}</Text>
              </View>
              {!n.read ? <View style={styles.dot} accessibilityElementsHidden /> : null}
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
  first: { borderTopLeftRadius: radius.card, borderTopRightRadius: radius.card },
  last: { borderBottomLeftRadius: radius.card, borderBottomRightRadius: radius.card, borderBottomWidth: 0 },
  row: { backgroundColor: colors.white, paddingHorizontal: space.x4, minHeight: 64, flexDirection: "row", alignItems: "center", gap: space.x3, paddingVertical: space.x2, borderBottomWidth: 1, borderBottomColor: colors.neutral100 },
  pressed: { opacity: 0.82 },
  flex: { flex: 1 },
  icon: { width: 36, height: 36, borderRadius: radius.control, alignItems: "center", justifyContent: "center" },
  iconUnread: {},
  title: { ...type.label, color: colors.navy950, fontFamily: "Inter_500Medium" },
  bold: { fontFamily: "Inter_700Bold" },
  body: { ...type.meta, color: colors.neutral600 },
  meta: { ...type.caption, color: colors.neutral500, marginTop: 2 },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.blue600 },
  error: { ...type.meta, color: colors.dangerText },
});
