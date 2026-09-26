import React, { useCallback, useState } from "react";
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, View } from "react-native";
import { router, useFocusEffect } from "expo-router";
import { AlertTriangle, Bell, CheckCircle2, ChevronRight, CreditCard, FileText, Info, LucideIcon, Search, ShieldCheck } from "lucide-react-native";
import { Button, Chip, ChipRow, ripple, Screen } from "@/components/ui";
import { BrandHeader, HeaderIconButton, TintedIcon, type Tint } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { CustomerApi } from "@/api/customer";
import { NotificationsApi, type CustomerNotification } from "@/api/client";
import { useTranslation } from "@/i18n";
import { matchesQuery, resolveNotificationTarget } from "@/lib/customerLogic";
import type { CopyKey } from "@/i18n/strings";
import { colors, radius, space, type } from "@/theme/tokens";

const severityIcon = (severity: CustomerNotification["severity"]) =>
  severity === "CRITICAL" || severity === "WARNING" ? AlertTriangle : severity === "SUCCESS" ? CheckCircle2 : Info;
const severityTint = (severity: CustomerNotification["severity"]): Tint =>
  severity === "CRITICAL" ? "red" : severity === "WARNING" ? "gold" : severity === "SUCCESS" ? "green" : "blue";

type Kind = "policies" | "claims" | "payments";
/** Inbox category from the notification type / deep link (design chips: Policies, Claims, Payments). */
const kindOf = (n: CustomerNotification): Kind | null => {
  const h = `${n.type ?? ""} ${n.path ?? ""}`.toLowerCase();
  if (/claim|sinistre/.test(h)) return "claims";
  if (/pay|premium|receipt|invoice|transaction|refund/.test(h)) return "payments";
  if (/polic|renew|certificate|cover|wallet|document/.test(h)) return "policies";
  return null;
};
const KIND: Record<Kind, { icon: LucideIcon; tint: Tint; label: CopyKey; action: CopyKey }> = {
  policies: { icon: FileText, tint: "blue", label: "notifFilterPolicies", action: "notifActionPolicy" },
  claims: { icon: ShieldCheck, tint: "green", label: "notifFilterClaims", action: "notifActionClaim" },
  payments: { icon: CreditCard, tint: "green", label: "notifFilterPayments", action: "notifActionPayment" },
};

export default function Notifications() {
  const { t, date } = useTranslation();
  const q = useLoad(() => CustomerApi.notifications());
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [filter, setFilter] = useState<"all" | "unread" | Kind>("all");
  const [searching, setSearching] = useState(false);
  const [query, setQuery] = useState("");
  const reload = q.reload;
  useFocusEffect(
    useCallback(() => {
      void reload();
    }, [reload]),
  );
  const items = q.data ?? [];
  const unread = items.filter((n) => !n.read).length;
  const shown = items.filter(
    (n) =>
      (filter === "all" || (filter === "unread" ? !n.read : kindOf(n) === filter)) &&
      matchesQuery(query, n.title, n.body),
  );
  const kindCount = (k: Kind) => items.filter((n) => kindOf(n) === k).length;
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
    <BrandHeader
      title={t("notifications")}
      subtitle={q.data ? `${t("notifSubtitle")} ${t("unreadCount", { count: unread })}` : t("notifSubtitle")}
      back
      right={null}
      titleRow={
        items.length ? (
          <HeaderIconButton
            icon={Search}
            label={t("notifSearch")}
            onPress={() => {
              setSearching((v) => !v);
              setQuery("");
            }}
          />
        ) : undefined
      }
    />
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
            {searching ? (
              <SearchBar value={query} onChangeText={setQuery} label={t("notifSearch")} placeholder={t("notifSearchPlaceholder")} clearLabel={t("clearSearch")} autoFocus />
            ) : null}
            <ChipRow exclusive>
              <Chip role="tab" label={`${t("filterAll")} (${items.length})`} selected={filter === "all"} onPress={() => setFilter("all")} />
              {(Object.keys(KIND) as Kind[]).filter((k) => kindCount(k)).map((k) => (
                <Chip key={k} role="tab" label={`${t(KIND[k].label)} (${kindCount(k)})`} selected={filter === k} onPress={() => setFilter(k)} />
              ))}
              <Chip role="tab" label={`${t("filterUnread")} (${unread})`} selected={filter === "unread"} onPress={() => setFilter("unread")} />
            </ChipRow>
            {unread ? <Button label={t("markAllRead")} variant="tertiary" loading={busy} onPress={() => void markAll()} /> : null}
            {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
          </View>
        }
        ListEmptyComponent={<EmptyState title={t("notificationsEmpty")} message={t("notificationsEmptyBody")} />}
        renderItem={({ item: n }) => {
          const kind = kindOf(n);
          const alert = n.severity === "CRITICAL" || n.severity === "WARNING";
          const Icon = kind && !alert ? KIND[kind].icon : severityIcon(n.severity);
          const tint = kind && !alert ? KIND[kind].tint : severityTint(n.severity);
          const target = resolveNotificationTarget(n);
          return (
            <Pressable
              accessibilityRole="button"
              accessibilityLabel={`${n.read ? "" : `${t("unread")}. `}${n.title}. ${n.body}`}
              onPress={() => void open(n)}
              android_ripple={ripple()}
              style={({ pressed }) => [styles.row, !n.read && styles.rowUnread, !n.read && n.severity === "CRITICAL" && styles.rowCritical, pressed && styles.pressed]}
            >
              <TintedIcon icon={Icon} tint={n.read ? "neutral" : tint} size={52} />
              <View style={styles.flex}>
                <View style={styles.titleRow}>
                  <Text style={[styles.title, !n.read && styles.bold, styles.flex]} numberOfLines={2}>{n.title}</Text>
                  <Text style={styles.meta}>{relative(n.created_at)}</Text>
                  {!n.read ? <View style={[styles.dot, n.severity === "CRITICAL" && styles.dotCritical]} accessibilityElementsHidden /> : null}
                </View>
                <Text style={styles.body} numberOfLines={3}>{n.body}</Text>
                {target && kind && !n.read ? (
                  <View style={[styles.action, alert && styles.actionStrong]} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
                    <Text style={[styles.actionText, alert && styles.actionTextStrong]}>{t(KIND[kind].action)}</Text>
                  </View>
                ) : null}
              </View>
              <ChevronRight size={20} color={colors.neutral500} />
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
    alignItems: "flex-start",
    gap: space.x3,
    overflow: "hidden",
  },
  rowUnread: { borderColor: colors.blue100 },
  rowCritical: { borderColor: colors.dangerSoft, backgroundColor: colors.dangerSoft },
  dotCritical: { backgroundColor: colors.danger },
  action: { marginTop: space.x3, minHeight: 44, borderRadius: radius.control, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center", paddingHorizontal: space.x3 },
  actionStrong: { backgroundColor: colors.blue600 },
  actionText: { ...type.label, color: colors.blue600 },
  actionTextStrong: { color: colors.white },
  pressed: { opacity: 0.85 },
  flex: { flex: 1 },
  titleRow: { flexDirection: "row", alignItems: "flex-start", gap: space.x2 },
  title: { ...type.label, color: colors.navy950, fontFamily: "Inter_500Medium" },
  bold: { fontFamily: "Inter_700Bold" },
  body: { ...type.body, color: colors.neutral600, marginTop: 4 },
  meta: { ...type.caption, color: colors.neutral500, fontFamily: "Inter_400Regular" },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.blue600 },
  error: { ...type.meta, color: colors.dangerText },
});
