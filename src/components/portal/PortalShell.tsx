import React, { ReactNode, useEffect, useState } from "react";
import { Pressable, StyleSheet, Switch, Text, View } from "react-native";
import { router, usePathname } from "expo-router";
import { useSafeAreaInsets } from "react-native-safe-area-context";
import {
  Bell,
  ChevronRight,
  CircleUserRound,
  Fingerprint,
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
  AuthApi,
  NotificationPreferences,
  NotificationsApi,
} from "@/api/client";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { LegalLinks } from "@/components/LegalLinks";
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
  const { t } = useTranslation();
  return (
    <AppHeader
      title={title}
      subtitle={subtitle}
      action={
        <View style={s.headerActions}>
          <HeaderIcon
            icon={Bell}
            label={t("portalNotifTitle")}
            onPress={() => router.push(`/${portal}/notifications` as never)}
          />
          <HeaderIcon
            icon={CircleUserRound}
            label={t("portalAccountSubtitle")}
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
  const { td } = useTranslation();
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
        const tint = selected ? colors.navy900 : colors.neutral500;
        const label = td(`portalTab_${t.label}`, t.label);
        return (
          <Pressable
            key={t.href}
            accessibilityRole="tab"
            accessibilityLabel={label}
            accessibilityState={{ selected }}
            onPress={() => {
              if (pathname !== t.href) router.navigate(t.href as never);
            }}
            style={s.tab}
          >
            <t.icon size={22} color={tint} strokeWidth={2} />
            <Text numberOfLines={1} style={[s.tabLabel, { color: tint }]}>
              {label}
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
  const { t } = useTranslation();
  const q = useLoad(() => NotificationsApi.list());
  return (
    <PortalScreen tabs={tabs}>
      <AppHeader title={t("portalNotifTitle")} subtitle={t("portalNotifSubtitle")} back />
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("notificationsLoading")}
        emptyTitle={t("portalNotifEmpty")}
        emptyMessage={t("portalNotifEmptyBody")}
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

const prefKeys: (keyof NotificationPreferences)[] = ["push", "sms", "email", "renewals", "claims", "payments"];

/** Account and settings for partner portals: profile, security, alerts, language, sign out. */
export function PortalAccount({ tabs }: { tabs: PortalTab[] }) {
  const { t, td } = useTranslation();
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
  const emailUnverified =
    !!user?.email &&
    (user.email_verified_at === null || user.contacts_verified === false);
  const [verifyState, setVerifyState] = useState<"idle" | "busy" | "sent" | "error">("idle");
  const verifyEmail = async () => {
    setVerifyState("busy");
    try {
      const r = await AuthApi.requestEmailVerification();
      setVerifyState(r.sent ? "sent" : "error");
    } catch {
      setVerifyState("error");
    }
  };
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
      setNotice(t("portalPrefSaveFailed"));
    }
  };
  const changeLanguage = async (code: "en" | "fr") => {
    setLanguage(code);
    try {
      await AccountApi.setLocale(code);
    } catch {
      setNotice(t("portalLanguageOffline"));
    }
  };
  return (
    <PortalScreen tabs={tabs}>
      <AppHeader title={t("portalAccountTitle")} subtitle={t("portalAccountSubtitle")} />
      <Card feature>
        <View style={s.profileRow}>
          <View style={s.avatar}>
            <UserRound size={32} color={colors.navy950} />
          </View>
          <View style={s.flex}>
            <Text style={s.name}>{user?.full_name ?? t("portalPartnerUser")}</Text>
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

      <SectionTitle title={t("portalSecurity")} />
      <Card>
        {/* Biometric lock + devices: available to every role, not only customers. */}
        <Pressable
          accessibilityRole="button"
          accessibilityLabel={t("portalBiometric")}
          onPress={() => router.push("/account/security" as never)}
        >
          <Row icon={Fingerprint} label={t("portalBiometric")}>
            <ChevronRight size={20} color={colors.neutral500} />
          </Row>
        </Pressable>
        <Row icon={LockKeyhole} label={t("portalPhoneVerification")}>
          <Text style={s.meta}>
            {user?.phone_verified_at ? t("portalVerified") : t("portalNotVerified")}
          </Text>
        </Row>
        {emailUnverified ? (
          <Pressable
            accessibilityRole="button"
            disabled={verifyState === "busy" || verifyState === "sent"}
            onPress={verifyEmail}
          >
            <Row icon={LockKeyhole} label={t("portalEmailVerification")}>
              <Text style={s.meta}>
                {verifyState === "busy"
                  ? t("portalEmailSending")
                  : verifyState === "sent"
                    ? t("portalEmailSent")
                    : verifyState === "error"
                      ? t("portalEmailRetry")
                      : t("portalEmailVerify")}
              </Text>
            </Row>
          </Pressable>
        ) : null}
        <Text style={s.body}>{t("portalSecurityNote")}</Text>
      </Card>

      <SectionTitle title={t("notificationSettings")} />
      <Card>
        {prefKeys.map((key) => (
          <Row key={key} icon={Bell} label={td(`portalPref_${key}`, key)}>
            <Switch
              accessibilityLabel={td(`portalPref_${key}`, key)}
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

      <SectionTitle title={t("portalLanguage")} />
      <Card>
        <Row icon={Languages} label={t("portalAppLanguage")}>
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
        accessibilityLabel={t("portalSignOut")}
        style={({ pressed }) => [s.logout, pressed && s.pressed]}
        onPress={async () => {
          await signOut();
          router.replace("/(auth)/sign-in");
        }}
      >
        <LogOut size={20} color={colors.dangerText} />
        <Text style={s.logoutText}>{t("portalSignOut")}</Text>
      </Pressable>
      <LegalLinks />
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
  // Mirrors app/(customer)/(tabs)/_layout.tsx so every role gets the same bar.
  tabBar: {
    flexDirection: "row",
    backgroundColor: colors.white,
    borderTopWidth: 2,
    borderTopColor: colors.gold100,
    paddingTop: 7,
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
