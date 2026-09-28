import React, { ReactNode, useCallback, useState } from "react";
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleProp,
  StyleSheet,
  Text,
  View,
  ViewStyle,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router, useFocusEffect } from "expo-router";
import { ArrowLeft, Bell, UserRound } from "lucide-react-native";
import { NotificationsApi } from "@/api/client";
import { PortalTabBar } from "@/components/portal/PortalShell";
import { agentTabs, brokerTabs, carrierTabs } from "@/components/portal/tabs";
import type { PortalTab } from "@/components/portal/PortalShell";
import { BrandMark } from "@/components/BrandMark";
import type { CopyKey } from "@/i18n/strings";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { CONTENT_MAX_WIDTH } from "@/theme/tokens";
import { agentColors as c, agentIcon, agentLayout as L, agentType as T } from "@/theme/agent";

/** Initials for the avatar ("Jean Paul Mbarga" -> "JM"). */
export const agentInitials = (name?: string | null) => {
  const parts = (name ?? "").trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "";
  return ((parts[0]?.[0] ?? "") + (parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? "") : "")).toUpperCase();
};

export function AgentAvatar({ name, size = 40 }: { name?: string | null; size?: number }) {
  const initials = agentInitials(name);
  return (
    <View style={[s.avatar, { width: size, height: size, borderRadius: size / 2 }]}>
      {initials ? (
        <Text style={[s.avatarText, { fontSize: Math.round(size * 0.36) }]}>{initials}</Text>
      ) : (
        <UserRound size={Math.round(size * 0.5)} color={c.navy} strokeWidth={agentIcon.stroke} />
      )}
    </View>
  );
}

/** Unread count for the header bell (GET /mobile/notifications), refreshed on focus. */
function useUnread() {
  const [count, setCount] = useState(0);
  useFocusEffect(
    useCallback(() => {
      let live = true;
      NotificationsApi.list()
        .then((rows) => live && setCount(rows.filter((n) => !n.read).length))
        .catch(() => {});
      return () => {
        live = false;
      };
    }, []),
  );
  return count;
}

/** The three partner portals share this frame; each keeps its own bottom bar and routes. */
export type PartnerPortal = "agent" | "broker" | "carrier";
const PORTAL: Record<PartnerPortal, { name: CopyKey; tabs: PortalTab[] }> = {
  agent: { name: "agentPortalName", tabs: agentTabs },
  broker: { name: "brokerPortalName", tabs: brokerTabs },
  carrier: { name: "carrierPortalName", tabs: carrierTabs },
};

function OperationalHeader({ portal }: { portal: PartnerPortal }) {
  const { t } = useTranslation();
  const name = useSession((st) => st.bootstrap?.user.full_name);
  const unread = useUnread();
  return (
    <View style={s.header}>
      <View style={s.brand} accessibilityRole="header">
        <BrandMark size={36} wordSize={18} caption={t(PORTAL[portal].name)} />
      </View>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={unread ? `${t("portalNotifTitle")}, ${t("unreadCount", { count: unread })}` : t("portalNotifTitle")}
        hitSlop={4}
        onPress={() => router.push(`/${portal}/notifications` as never)}
        style={({ pressed }) => [s.iconBtn, pressed && s.pressed]}
      >
        <Bell size={agentIcon.nav} color={c.navy} strokeWidth={agentIcon.stroke} />
        {unread > 0 ? (
          <View style={s.badge}>
            <Text style={s.badgeText}>{unread > 99 ? "99+" : unread}</Text>
          </View>
        ) : null}
      </Pressable>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t("agentProfileTitle")}
        hitSlop={4}
        onPress={() => router.push(`/${portal}/account` as never)}
        style={({ pressed }) => [s.avatarBtn, pressed && s.pressed]}
      >
        <AgentAvatar name={name} size={40} />
      </Pressable>
    </View>
  );
}

function DrillHeader({ portal, title, onBack, right }: { portal: PartnerPortal; title: string; onBack?: () => void; right?: ReactNode }) {
  const { t } = useTranslation();
  const back = onBack ?? (() => (router.canGoBack() ? router.back() : router.replace(`/${portal}` as never)));
  return (
    <View style={s.header}>
      <Pressable
        accessibilityRole="button"
        accessibilityLabel={t("back")}
        hitSlop={4}
        onPress={back}
        style={({ pressed }) => [s.iconBtn, s.plain, pressed && s.pressed]}
      >
        <ArrowLeft size={agentIcon.nav} color={c.navy} strokeWidth={agentIcon.stroke} />
      </Pressable>
      <Text accessibilityRole="header" style={s.drillTitle} numberOfLines={2}>
        {title}
      </Text>
      <View style={s.drillRight}>{right}</View>
    </View>
  );
}

/**
 * The agent-kit header for a screen that is still built on the shared Screen (broker and
 * insurer pages via AppHeader): operational header + page title, or the drill-down header.
 * Renders inside an already padded body, so it carries no horizontal padding of its own.
 */
export function AgentPageHeader({
  portal,
  title,
  subtitle,
  back = false,
  right,
}: {
  portal: PartnerPortal;
  title: string;
  subtitle?: string;
  back?: boolean;
  right?: ReactNode;
}) {
  if (back) {
    return (
      <View style={s.pageHead}>
        <View style={s.embedded}>
          <DrillHeader portal={portal} title={title} right={right} />
        </View>
        {subtitle ? <Text style={[s.pageSub, s.center]}>{subtitle}</Text> : null}
      </View>
    );
  }
  return (
    <View style={s.pageHead}>
      <View style={s.embedded}>
        <OperationalHeader portal={portal} />
      </View>
      <View style={s.pageTitleRow}>
        <View style={s.flex}>
          <Text accessibilityRole="header" style={s.pageTitle}>{title}</Text>
          {subtitle ? <Text style={s.pageSub}>{subtitle}</Text> : null}
        </View>
        {right}
      </View>
    </View>
  );
}

/**
 * Agent-portal screen frame (spec §7). `variant="operational"` = wordmark +
 * "Commercial Agent Portal" + bell (unread badge) + avatar -> /agent/account.
 * `variant="drilldown"` = back arrow + centred `title`. Bottom navigation is
 * the locked agent bar unless `hideNav`; `footer` (e.g. a sticky Save button)
 * is pinned above it.
 */
export function AgentShell({
  portal = "agent",
  variant = "operational",
  title = "",
  onBack,
  headerRight,
  children,
  footer,
  hideNav = false,
  scroll = true,
  refreshing,
  onRefresh,
  contentStyle,
}: {
  /** Which partner portal this frame serves (header caption, bell/avatar routes, bottom bar). */
  portal?: PartnerPortal;
  variant?: "operational" | "drilldown";
  /** Drill-down title (centred). */
  title?: string;
  onBack?: () => void;
  /** Drill-down only: optional right-side element (e.g. a filter icon). */
  headerRight?: ReactNode;
  children: ReactNode;
  footer?: ReactNode;
  hideNav?: boolean;
  /** false when the body is a FlatList that scrolls itself. */
  scroll?: boolean;
  refreshing?: boolean;
  onRefresh?: () => void;
  contentStyle?: StyleProp<ViewStyle>;
}) {
  const header =
    variant === "operational" ? <OperationalHeader portal={portal} /> : <DrillHeader portal={portal} title={title} onBack={onBack} right={headerRight} />;
  const body = <View style={[s.body, !scroll && s.flex, contentStyle]}>{children}</View>;
  return (
    <SafeAreaView edges={["top"]} style={s.safe}>
      <KeyboardAvoidingView style={s.flex} behavior={Platform.OS === "ios" ? "padding" : undefined}>
        <View style={s.headerWrap}>{header}</View>
        {scroll ? (
          <ScrollView
            style={s.flex}
            contentContainerStyle={s.scroll}
            showsVerticalScrollIndicator={false}
            keyboardShouldPersistTaps="handled"
            refreshControl={
              onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={c.actionBlue} /> : undefined
            }
          >
            {body}
          </ScrollView>
        ) : (
          body
        )}
        {footer ? <View style={s.footer}>{footer}</View> : null}
        {hideNav ? null : <PortalTabBar tabs={PORTAL[portal].tabs} />}
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const s = StyleSheet.create({
  safe: { flex: 1, backgroundColor: c.page },
  flex: { flex: 1 },
  pressed: { opacity: 0.8 },
  headerWrap: { width: "100%", maxWidth: CONTENT_MAX_WIDTH, alignSelf: "center" },
  header: {
    minHeight: 64,
    flexDirection: "row",
    alignItems: "center",
    gap: 10,
    paddingHorizontal: L.screenPadding,
    paddingVertical: 8,
  },
  brand: { flex: 1 },
  iconBtn: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: c.surface,
    borderWidth: 1,
    borderColor: c.border,
    alignItems: "center",
    justifyContent: "center",
  },
  plain: { borderWidth: 0, backgroundColor: "transparent" },
  badge: {
    position: "absolute",
    top: 4,
    right: 3,
    minWidth: 18,
    height: 18,
    borderRadius: 9,
    paddingHorizontal: 4,
    backgroundColor: c.danger,
    alignItems: "center",
    justifyContent: "center",
    borderWidth: 2,
    borderColor: c.surface,
  },
  badgeText: { fontFamily: "Inter_700Bold", fontSize: 10, lineHeight: 12, color: c.surface },
  avatarBtn: { width: 44, height: 44, alignItems: "center", justifyContent: "center" },
  avatar: { backgroundColor: c.blueTint, alignItems: "center", justifyContent: "center", borderWidth: 1, borderColor: c.borderStrong },
  avatarText: { fontFamily: "Inter_700Bold", color: c.navy },
  drillTitle: { ...T.sectionTitle, color: c.heading, flex: 1, textAlign: "center" },
  drillRight: { width: 44, alignItems: "flex-end" },
  scroll: { flexGrow: 1, paddingBottom: 32 },
  body: {
    width: "100%",
    maxWidth: CONTENT_MAX_WIDTH,
    alignSelf: "center",
    paddingHorizontal: L.screenPadding,
    paddingTop: 8,
    gap: L.sectionGap,
  },
  footer: {
    paddingHorizontal: L.screenPadding,
    paddingVertical: 12,
    backgroundColor: c.surface,
    borderTopWidth: 1,
    borderTopColor: c.border,
  },
  pageHead: { gap: 12 },
  embedded: { marginHorizontal: -L.screenPadding },
  pageTitleRow: { flexDirection: "row", alignItems: "center", gap: 12 },
  pageTitle: { ...T.screenTitle, color: c.heading },
  pageSub: { ...T.secondary, color: c.secondary, marginTop: 2 },
  center: { textAlign: "center" },
});
