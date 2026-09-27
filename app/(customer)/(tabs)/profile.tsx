import React, { useState } from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
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
  ArrowRight,
  Users,
  Headset,
  MessageCircle,
} from "lucide-react-native";
import { Card, ripple, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon, type Tint } from "@/components/design";
import { BrandArt } from "@/components/design/BrandArt";
import { useSession } from "@/store/session";
import { AuthApi, KycApi, SupportContactsApi } from "@/api/client";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { BuildStamp } from "@/components/BuildStamp";
import { colors, radius, space, type } from "@/theme/tokens";
/** Design rotates icon tints row by row (blue, gold, red, green). */
const ROW_TINTS: Tint[] = ["blue", "gold", "red", "green"];

const groups: { title: CopyKey; tint: Tint; links: [CopyKey, LucideIcon, string][] }[] = [
  {
    title: "profileGroupYou",
    tint: "blue",
    links: [
      ["personalInformation", UserRound, "/account/profile"],
      ["identityVerification", ShieldCheck, "/onboarding/kyc"],
      ["myAssets", CarFront, "/assets"],
      ["beneficiaries", Users, "/account/beneficiaries"],
      ["profilePaymentMethods", CreditCard, "/account/payment-methods"],
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
  // KYC status and profile completeness come from GET /mobile/kyc/profile + the session user.
  const kyc = useLoad(() => KycApi.profile());
  const contacts = useLoad(() => SupportContactsApi.get());
  const kycStatus = kyc.data?.status?.toUpperCase() ?? null;
  const kycTone = kycStatus === "VERIFIED" || kycStatus === "APPROVED" ? "success" : kycStatus === "REJECTED" ? "danger" : kycStatus === "SUBMITTED" || kycStatus === "UNDER_REVIEW" || kycStatus === "REVIEWING" ? "info" : "warning";
  const checks = [
    !!user?.full_name,
    !!user?.phone_e164,
    !!user?.email && !emailUnverified,
    !!kyc.data?.legal_name,
    !!kyc.data?.date_of_birth,
    !!kyc.data?.national_id_number,
    !!kyc.data?.city,
    kycTone === "success",
  ];
  const completion = kyc.data ? Math.round((checks.filter(Boolean).length / checks.length) * 100) : null;
  const role = workspace?.role_code ? td(`role_${workspace.role_code}`, workspace.role_code) : null;
  return (
    <Screen>
      <BrandHeader title={t("profile")} subtitle={t("profileHeaderSubtitle")} back={false} right="bell" />
      <Card style={styles.hero}>
        <View style={styles.heroRow}>
          <View style={styles.avatar} accessible accessibilityLabel={user?.full_name ?? t("profile")}>
            <Text style={styles.avatarText}>{initialsOf(user?.full_name)}</Text>
          </View>
          <View style={styles.flex}>
            <Text style={styles.name} numberOfLines={2}>{user?.full_name ?? t("profile")}</Text>
            {role ? <Text style={styles.role}>{role}</Text> : null}
            {kycStatus ? (
              <View style={styles.chipRow}>
                <StatusChip label={`${t("profileKyc")}: ${td(`kycStatus_${kycStatus}`, kycStatus)}`} tone={kycTone} />
              </View>
            ) : null}
          </View>
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={t("personalInformation")}
            onPress={() => router.push("/account/profile")}
            hitSlop={6}
            style={({ pressed }) => [styles.circleBtn, pressed && styles.pressed]}
          >
            <ChevronRight size={20} color={colors.navy900} />
          </Pressable>
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
      {completion !== null && completion < 100 ? (
        <View style={styles.completion}>
          <View style={styles.completionRow}>
          <View style={styles.ring} accessibilityLabel={t("profileCompletionA11y", { percent: completion })}>
            <Text style={styles.ringText}>{completion}%</Text>
          </View>
          <View style={styles.flex}>
            <Text style={styles.completionTitle}>{t("profileCompletion")}</Text>
            <Text style={styles.completionBody}>{t("profileCompletionBody")}</Text>
          </View>
          </View>
          <Pressable
            accessibilityRole="button"
            onPress={() => router.push("/onboarding/kyc")}
            style={({ pressed }) => [styles.completeBtn, pressed && styles.pressed]}
          >
            <Text style={styles.completeText}>{t("profileCompleteCta")}</Text>
            <ArrowRight size={16} color={colors.blue600} />
          </Pressable>
        </View>
      ) : null}
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
              <TintedIcon icon={Icon} tint={ROW_TINTS[i % ROW_TINTS.length]} size={44} />
              <Text style={styles.label}>{t(label)}</Text>
              <View style={styles.circleSm}>
                <ChevronRight size={16} color={colors.navy900} />
              </View>
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
        <TintedIcon icon={LogOut} tint="red" size={44} />
        <Text style={styles.logoutText}>{t("signOutSecurely")}</Text>
        <View style={styles.circleSm}>
          <ChevronRight size={16} color={colors.navy900} />
        </View>
      </Pressable>
      <View style={styles.help}>
        <Headset size={26} color={colors.blue600} />
        <View style={styles.flex}>
          <Text style={styles.completionTitle}>{t("profileMoreHelp")}</Text>
          <Text style={styles.completionBody}>{t("profileMoreHelpBody")}</Text>
        </View>
        <View style={styles.helpActions}>
          <Pressable accessibilityRole="button" accessibilityLabel={t("profileChat")} onPress={() => router.push("/support/new")} style={({ pressed }) => [styles.helpBtn, pressed && styles.pressed]}>
            <MessageCircle size={20} color={colors.white} />
          </Pressable>
          {contacts.data?.phone ? (
            <Pressable accessibilityRole="button" accessibilityLabel={t("profileCall")} onPress={() => void Linking.openURL(`tel:${contacts.data?.phone}`)} style={({ pressed }) => [styles.helpBtn, styles.helpBtnBlue, pressed && styles.pressed]}>
              <Phone size={20} color={colors.white} />
            </Pressable>
          ) : null}
        </View>
      </View>
      <BrandArt name="border_band" width={240} opacity={0.6} />
      <BrandArt name="logo_africa_lockup" width={200} />
      <BuildStamp />
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1 },
  pressed: { opacity: 0.85 },
  hero: { borderRadius: radius.feature, gap: space.x4 },
  heroRow: { flexDirection: "row", alignItems: "center", gap: space.x4 },
  avatar: { width: 56, height: 56, borderRadius: 28, backgroundColor: colors.navy900, borderWidth: 3, borderColor: colors.gold500, alignItems: "center", justifyContent: "center" },
  avatarText: { fontFamily: "Inter_700Bold", fontSize: 20, lineHeight: 28, color: colors.white, letterSpacing: 0.5 },
  name: { ...type.cardTitle, fontSize: 18, lineHeight: 24, color: colors.navy950, marginBottom: 6 },
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
    minHeight: 68,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    paddingHorizontal: space.x4,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.feature,
    backgroundColor: colors.white,
    overflow: "hidden",
  },
  logoutText: { ...type.label, fontSize: 16, color: colors.dangerText, flex: 1 },
  role: { ...type.body, color: colors.neutral600, marginBottom: 6 },
  chipRow: { flexDirection: "row" },
  circleBtn: { width: 44, height: 44, borderRadius: 22, borderWidth: 1, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center" },
  circleSm: { width: 32, height: 32, borderRadius: 16, borderWidth: 1, borderColor: colors.neutral200, alignItems: "center", justifyContent: "center" },
  completion: { gap: space.x3, padding: space.x4, borderRadius: radius.feature, backgroundColor: colors.blue50, borderWidth: 1, borderColor: colors.blue100 },
  completionRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  ring: { width: 64, height: 64, borderRadius: 32, borderWidth: 6, borderColor: colors.blue600, alignItems: "center", justifyContent: "center", backgroundColor: colors.white },
  ringText: { ...type.label, color: colors.navy950 },
  completionTitle: { ...type.label, fontSize: 16, color: colors.navy950 },
  completionBody: { ...type.meta, color: colors.neutral600 },
  completeBtn: { alignSelf: "flex-start", flexDirection: "row", alignItems: "center", gap: 4, minHeight: 44, paddingHorizontal: space.x3, borderRadius: radius.control, backgroundColor: colors.blue100 },
  completeText: { ...type.label, color: colors.blue600 },
  help: { flexDirection: "row", alignItems: "center", gap: space.x3, padding: space.x4, borderRadius: radius.feature, backgroundColor: colors.blue50 },
  helpActions: { flexDirection: "row", gap: space.x2 },
  helpBtn: { width: 48, height: 48, borderRadius: 24, backgroundColor: colors.navy900, alignItems: "center", justifyContent: "center" },
  helpBtnBlue: { backgroundColor: colors.blue600 },
});
