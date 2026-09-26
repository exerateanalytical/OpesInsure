import React, { useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import {
  Bell,
  Building2,
  CarFront,
  ChevronRight,
  CreditCard,
  Clock3,
  FileCog,
  Languages,
  LockKeyhole,
  LogOut,
  LifeBuoy,
  LucideIcon,
  CircleHelp,
  Eye,
  BellRing,
  ShieldCheck,
  Search,
  UserRound,
  WalletCards,
  CloudCog,
  Gauge,
  Activity,
  ShieldAlert,
  MailCheck,
  Mail,
  Phone,
} from "lucide-react-native";
import { Card, ripple, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon, type Tint } from "@/components/design";
import { useSession } from "@/store/session";
import { AuthApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { BuildStamp } from "@/components/BuildStamp";
import { colors, radius, space, type } from "@/theme/tokens";
const groups: { title: CopyKey; tint: Tint; links: [CopyKey, LucideIcon, string][] }[] = [
  {
    title: "profileGroupYou",
    tint: "blue",
    links: [
      ["personalInformation", UserRound, "/account/profile"],
      ["identityVerification", ShieldCheck, "/onboarding/kyc"],
      ["myAssets", CarFront, "/assets"],
      ["searchTitle", Search, "/search"],
    ],
  },
  {
    title: "profileGroupInsurance",
    tint: "gold",
    links: [
      ["savedQuotes", Clock3, "/quotes"],
      ["policyWallet", WalletCards, "/wallet"],
      ["paymentsReceipts", CreditCard, "/payments"],
      ["policyServiceRequests", FileCog, "/services"],
      ["insuranceCompanies", Building2, "/institutions/insurers"],
    ],
  },
  {
    title: "profileGroupHelp",
    tint: "green",
    links: [
      ["notificationCentre", Bell, "/notifications"],
      ["faqTitle", CircleHelp, "/support/faq"],
      ["helpComplaints", LifeBuoy, "/support"],
    ],
  },
  {
    title: "profileGroupSettings",
    tint: "neutral",
    links: [
      ["securityDevices", LockKeyhole, "/account/security"],
      ["privacyConsent", Eye, "/account/privacy"],
      ["notificationSettings", BellRing, "/account/notifications"],
      ["language", Languages, "/account/language"],
      ["syncCentre", CloudCog, "/sync"],
      ["dataUsage", Gauge, "/account/data-usage"],
      ["serviceStatus", Activity, "/system/status"],
      ["deviceSecurity", ShieldAlert, "/security/device-status"],
    ],
  },
];
/** "Jude Nshome" -> "JN"; a single word gives its first two letters. */
const initialsOf = (name?: string | null) => {
  const parts = (name ?? "").trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "•";
  return parts.length === 1 ? parts[0]!.slice(0, 2).toUpperCase() : `${parts[0]![0]}${parts[parts.length - 1]![0]}`.toUpperCase();
};
export default function Profile() {
  const user = useSession((s) => s.bootstrap?.user);
  const workspace = useSession((s) => s.activeWorkspace);
  const signOut = useSession((s) => s.signOut);
  const { t, td } = useTranslation();
  // Only when the server reports it (field present and null / flag false);
  // older payloads without the field show nothing.
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
  const role = workspace?.role_code ? td(`role_${workspace.role_code}`, workspace.role_code) : null;
  return (
    <Screen>
      <BrandHeader title={t("profile")} back={false} right="bell" />
      <Card style={styles.hero}>
        <View style={styles.heroRow}>
          <View style={styles.avatar} accessible accessibilityLabel={user?.full_name ?? t("profile")}>
            <Text style={styles.avatarText}>{initialsOf(user?.full_name)}</Text>
          </View>
          <View style={styles.flex}>
            <Text style={styles.name} numberOfLines={2}>{user?.full_name ?? t("profile")}</Text>
            {role ? <StatusChip label={role} tone="info" /> : null}
          </View>
        </View>
        <View style={styles.contactRows}>
          <View style={styles.contactRow}>
            <Phone size={18} color={colors.navy800} />
            <Text style={styles.body} selectable>{user?.phone_e164}</Text>
          </View>
          <View style={styles.contactRow}>
            <Mail size={18} color={colors.navy800} />
            <Text style={styles.body} selectable>{user?.email ?? t("noEmail")}</Text>
          </View>
        </View>
        {emailUnverified ? (
          <Pressable
            accessibilityRole="button"
            disabled={verifyState === "busy" || verifyState === "sent"}
            style={styles.verify}
            onPress={() => void verifyEmail()}
          >
            <MailCheck size={20} color={colors.warningText} />
            <Text style={styles.verifyText}>
              {verifyState === "sent"
                ? t("emailVerifySent")
                : verifyState === "busy"
                  ? t("sending")
                  : verifyState === "error"
                    ? t("emailVerifyError")
                    : t("emailVerify")}
            </Text>
          </Pressable>
        ) : null}
      </Card>
      {groups.map((group) => (
        <Card key={group.title} style={styles.groupCard}>
          <Text accessibilityRole="header" style={styles.group}>{t(group.title)}</Text>
          {group.links.map(([label, Icon, path], i) => (
            <Pressable
              accessibilityRole="button"
              key={label}
              android_ripple={ripple()}
              style={({ pressed }) => [styles.item, i === group.links.length - 1 && styles.itemLast, pressed && styles.pressed]}
              onPress={() => router.push(path as never)}
            >
              <TintedIcon icon={Icon} tint={group.tint} size={40} />
              <Text style={styles.label}>{t(label)}</Text>
              <ChevronRight size={20} color={colors.neutral500} />
            </Pressable>
          ))}
        </Card>
      ))}
      <Pressable
        accessibilityRole="button"
        android_ripple={ripple()}
        style={({ pressed }) => [styles.logout, pressed && styles.pressed]}
        onPress={async () => {
          await signOut();
          router.replace("/(auth)/sign-in");
        }}
      >
        <LogOut size={20} color={colors.danger} />
        <Text style={styles.logoutText}>{t("signOutSecurely")}</Text>
      </Pressable>
      <BuildStamp />
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  hero: { borderRadius: radius.feature, gap: space.x4 },
  heroRow: { flexDirection: "row", alignItems: "center", gap: space.x4 },
  avatar: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.navy900, borderWidth: 3, borderColor: colors.gold500, alignItems: "center", justifyContent: "center" },
  avatarText: { fontFamily: "Inter_700Bold", fontSize: 22, lineHeight: 28, color: colors.white, letterSpacing: 0.5 },
  name: { ...type.cardTitle, fontSize: 20, lineHeight: 26, color: colors.navy950, marginBottom: 6 },
  contactRows: { gap: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral200, paddingTop: space.x3 },
  contactRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  body: { ...type.body, color: colors.neutral700, flex: 1 },
  verify: {
    flexDirection: "row",
    alignItems: "center",
    gap: space.x2,
    minHeight: 44,
    paddingHorizontal: space.x3,
    borderRadius: radius.control,
    backgroundColor: colors.warningSoft,
  },
  verifyText: { ...type.label, color: colors.warningText, flex: 1 },
  groupCard: { borderRadius: radius.feature, gap: 0, paddingVertical: space.x3 },
  group: { ...type.caption, color: colors.neutral600, letterSpacing: 1, textTransform: "uppercase", marginBottom: space.x1 },
  item: {
    minHeight: 60,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  itemLast: { borderBottomWidth: 0 },
  label: { ...type.body, flex: 1, color: colors.navy950 },
  logout: {
    minHeight: 52,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: space.x2,
    borderWidth: 1.5,
    borderColor: colors.danger,
    borderRadius: radius.control,
    backgroundColor: colors.white,
    overflow: "hidden",
  },
  logoutText: { ...type.label, color: colors.dangerText },
});
