import React, { ReactNode, useEffect, useState } from "react";
import { Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { router, usePathname } from "expo-router";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import {
  Bell,
  CircleUserRound,
  Languages,
  LockKeyhole,
  LogOut,
  LucideIcon,
  UserRound,
} from "lucide-react-native";
import { AppHeader, Card, Screen, SectionTitle } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import {
  AccountApi,
  NotificationPreferences,
  NotificationsApi,
} from "@/api/client";
import { useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";

export type PortalKey = "agent" | "broker" | "carrier";
export type PortalTab = { label: string; icon: LucideIcon; href: string };

/** Header with notification bell and account access, shared by all portals. */
export function PortalHeader({
  portal,
  title,
  subtitle,
}: {
  portal: PortalKey;
  title: string;
  subtitle?: string;
}) {
  return (
    <AppHeader
      title={title}
      subtitle={subtitle}
      action={
        <View style={s.headerActions}>
          <HeaderIcon
            icon={Bell}
            label="Notifications"
            onPress={() => router.push(`/${portal}/notifications` as never)}
          />
          <HeaderIcon
            icon={CircleUserRound}
            label="Account and settings"
            onPress={() => router.push(`/${portal}/account` as never)}
          />
        </View>
      }
    />
  );
}

function HeaderIcon({
  icon: Icon,
  label,
  onPress,
}: {
  icon: LucideIcon;
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      hitSlop={6}
      onPress={onPress}
      style={({ pressed }) => [s.headerIcon, pressed && s.pressed]}
    >
      <Icon size={21} color={colors.navy950} />
    </Pressable>
  );
}

/** Persistent bottom navigation for a partner portal. */
export function PortalTabBar({ tabs }: { tabs: PortalTab[] }) {
  const pathname = usePathname();
  const insets = useSafeAreaInsets();
  // Longest matching prefix wins, so /agent/clients/123 highlights "Clients".
  const active = tabs.reduce<PortalTab | undefined>(
    (best, t) =>
      (pathname === t.href || pathname.startsWith(`${t.href}/`)) &&
      (!best || t.href.length > best.href.length)
        ? t
        : best,
    undefined,
  );
  return (
    <View
      accessibilityRole="tablist"
      style={[s.tabBar, { paddingBottom: space.x2 + insets.bottom }]}
    >
      {tabs.map((t) => {
        const selected = active?.href === t.href;
        const tint = selected ? colors.blue600 : colors.neutral600;
        return (
          <Pressable
            key={t.href}
            accessibilityRole="tab"
            accessibilityLabel={t.label}
            accessibilityState={{ selected }}
            onPress={() => {
              if (pathname !== t.href) router.navigate(t.href as never);
            }}
            style={s.tab}
          >
            <t.icon size={22} color={tint} strokeWidth={2} />
            <Text numberOfLines={1} style={[s.tabLabel, { color: tint }]}>
              {t.label}
            </Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Screen wrapper for top-level portal sections with the bottom bar pinned. */
export function PortalScreen({
  tabs,
  children,
}: {
  tabs: PortalTab[];
  children: ReactNode;
}) {
  return <Screen footer={<PortalTabBar tabs={tabs} />}>{children}</Screen>;
}

export function PortalNotifications({ tabs }: { tabs: PortalTab[] }) {
  const q = useLoad(() => NotificationsApi.list());
  return (
    <PortalScreen tabs={tabs}>
      <AppHeader title="Notifications" subtitle="Alerts for your workspace" back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel="Loading notifications…"
        emptyTitle="You are all caught up"
        emptyMessage="New alerts about your work will appear here."
      >
        {(items) => (
          <Card>
            {items.map((n) => (
              <View key={n.id} style={s.noticeItem}>
                <Text style={s.noticeTitle}>{n.title}</Text>
                {n.body ? <Text style={s.body}>{n.body}</Text> : null}
              </View>
            ))}
          </Card>
        )}
      </StatePanel>
    </PortalScreen>
  );
}

const prefLabels: [keyof NotificationPreferences, string][] = [
  ["push", "Push notifications"],
  ["sms", "SMS alerts"],
  ["email", "Email"],
  ["renewals", "Renewal reminders"],
  ["claims", "Claim updates"],
  ["payments", "Payments and commissions"],
];

/** Account and settings for partner portals: profile, security, alerts, language, sign out. */
export function PortalAccount({ tabs }: { tabs: PortalTab[] }) {
  const bootstrap = useSession((st) => st.bootstrap);
  const workspace = useSession((st) => st.activeWorkspace);
  const language = useSession((st) => st.language);
  const setLanguage = useSession((st) => st.setLanguage);
  const signOut = useSession((st) => st.signOut);
  const user = bootstrap?.user;
  const [prefs, setPrefs] = useState<NotificationPreferences>({
    push: false,
    sms: true,
    email: false,
    renewals: true,
    claims: true,
    payments: true,
  });
  const [notice, setNotice] = useState<string | null>(null);
  useEffect(() => {
    AccountApi.notificationPreferences()
      .then(setPrefs)
      .catch(() => {});
  }, []);
  const toggle = async (key: keyof NotificationPreferences, next: boolean) => {
    const previous = prefs;
    const updated = { ...prefs, [key]: next };
    setPrefs(updated);
    try {
      setPrefs(await AccountApi.saveNotificationPreferences(updated));
      setNotice(null);
    } catch {
      setPrefs(previous);
      setNotice("Could not save your preference. Try again.");
    }
  };
  const changeLanguage = async (code: "en" | "fr") => {
    setLanguage(code);
    try {
      await AccountApi.setLocale(code);
    } catch {
      setNotice("Language changed on this device; it will sync when online.");
    }
  };
  return (
    <PortalScreen tabs={tabs}>
      <AppHeader title="Account" subtitle="Profile, security and preferences" />
      <Card feature>
        <View style={s.profileRow}>
          <View style={s.avatar}>
            <UserRound size={24} color={colors.white} />
          </View>
          <View style={s.flex}>
            <Text style={s.name}>{user?.full_name ?? "Partner user"}</Text>
            {user?.phone_e164 ? <Text style={s.body}>{user.phone_e164}</Text> : null}
            {user?.email ? <Text style={s.body}>{user.email}</Text> : null}
          </View>
        </View>
        {workspace ? (
          <Text style={s.meta}>
            {workspace.tenant_name} · {workspace.role_code.replaceAll("_", " ")}
          </Text>
        ) : null}
      </Card>

      <SectionTitle title="Security" />
      <Card>
        <Row icon={LockKeyhole} label="Phone verification">
          <Text style={s.meta}>
            {user?.phone_verified_at ? "Verified" : "Not verified"}
          </Text>
        </Row>
        <Text style={s.body}>
          Sign-in uses a one-time code sent to your phone. Never share it; our
          staff will never ask for it.
        </Text>
      </Card>

      <SectionTitle title="Notification settings" />
      <Card>
        {prefLabels.map(([key, label]) => (
          <Row key={key} icon={Bell} label={label}>
            <Switch
              accessibilityLabel={label}
              value={!!prefs[key]}
              onValueChange={(v) => void toggle(key, v)}
              trackColor={{ true: colors.blue600, false: colors.neutral300 }}
            />
          </Row>
        ))}
        {notice ? (
          <Text accessibilityRole="alert" style={s.notice}>
            {notice}
          </Text>
        ) : null}
      </Card>

      <SectionTitle title="Language" />
      <Card>
        <Row icon={Languages} label="App language">
          <View style={s.segment}>
            {(
              [
                ["en", "EN", "English"],
                ["fr", "FR", "Français"],
              ] as const
            ).map(([code, label, name]) => (
              <Pressable
                key={code}
                accessibilityRole="radio"
                accessibilityState={{ selected: language === code }}
                accessibilityLabel={name}
                onPress={() => void changeLanguage(code)}
                style={[s.segmentItem, language === code && s.segmentOn]}
              >
                <Text
                  style={[s.segmentText, language === code && s.segmentTextOn]}
                >
                  {label}
                </Text>
              </Pressable>
            ))}
          </View>
        </Row>
      </Card>

      <Pressable
        accessibilityRole="button"
        style={({ pressed }) => [s.logout, pressed && s.pressed]}
        onPress={async () => {
          await signOut();
          router.replace("/(auth)/sign-in");
        }}
      >
        <LogOut size={20} color={colors.dangerText} />
        <Text style={s.logoutText}>Sign out securely</Text>
      </Pressable>
    </PortalScreen>
  );
}

function Row({
  icon: Icon,
  label,
  children,
}: {
  icon: LucideIcon;
  label: string;
  children?: ReactNode;
}) {
  return (
    <View style={s.row}>
      <Icon size={20} color={colors.navy800} />
      <Text style={s.rowLabel}>{label}</Text>
      {children}
    </View>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.82 },
  headerActions: { flexDirection: "row", gap: space.x2 },
  headerIcon: {
    width: 44,
    height: 44,
    borderRadius: radius.control,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
  },
  tabBar: {
    flexDirection: "row",
    backgroundColor: colors.white,
    borderTopWidth: 1,
    borderTopColor: colors.neutral200,
    paddingTop: space.x2,
  },
  tab: {
    flex: 1,
    minHeight: 48,
    alignItems: "center",
    justifyContent: "center",
    gap: 2,
  },
  tabLabel: { fontFamily: "Inter_600SemiBold", fontSize: 11 },
  profileRow: { flexDirection: "row", gap: space.x3, alignItems: "center" },
  avatar: {
    width: 52,
    height: 52,
    borderRadius: 26,
    backgroundColor: colors.navy950,
    alignItems: "center",
    justifyContent: "center",
  },
  name: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  row: {
    minHeight: 48,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
  },
  rowLabel: { ...type.label, color: colors.navy950, flex: 1 },
  notice: { ...type.meta, color: colors.dangerText, paddingVertical: space.x2 },
  noticeItem: {
    gap: space.x1,
    paddingVertical: space.x2,
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  noticeTitle: { ...type.label, color: colors.navy950 },
  segment: {
    flexDirection: "row",
    borderWidth: 1,
    borderColor: colors.neutral300,
    borderRadius: radius.control,
    overflow: "hidden",
  },
  segmentItem: {
    minWidth: 48,
    minHeight: 44,
    alignItems: "center",
    justifyContent: "center",
    paddingHorizontal: space.x3,
  },
  segmentOn: { backgroundColor: colors.blue600 },
  segmentText: { ...type.label, color: colors.navy950 },
  segmentTextOn: { color: colors.white },
  logout: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: space.x2,
    borderRadius: radius.control,
    borderWidth: 1,
    borderColor: colors.danger,
    backgroundColor: colors.dangerSoft,
  },
  logoutText: { ...type.label, color: colors.dangerText },
});
