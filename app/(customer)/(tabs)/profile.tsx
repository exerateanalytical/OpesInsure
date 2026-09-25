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
} from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { useSession } from "@/store/session";
import { AuthApi } from "@/api/client";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import Constants from "expo-constants";
import { colors, radius, space, type } from "@/theme/tokens";
const groups: { title: CopyKey; links: [CopyKey, LucideIcon, string][] }[] = [
  {
    title: "profileGroupYou",
    links: [
      ["personalInformation", UserRound, "/account/profile"],
      ["identityVerification", ShieldCheck, "/onboarding/kyc"],
      ["myAssets", CarFront, "/assets"],
      ["searchTitle", Search, "/search"],
    ],
  },
  {
    title: "profileGroupInsurance",
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
    links: [
      ["notificationCentre", Bell, "/notifications"],
      ["faqTitle", CircleHelp, "/support/faq"],
      ["helpComplaints", LifeBuoy, "/support"],
    ],
  },
  {
    title: "profileGroupSettings",
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
  return (
    <Screen>
      <AppHeader
        title={t("profile")}
        subtitle={[user?.full_name, workspace?.role_code ? td(`role_${workspace.role_code}`, workspace.role_code) : null]
          .filter(Boolean)
          .join(" · ")}
      />
      <Card>
        <Text style={styles.title}>{t("contactDetails")}</Text>
        <Text style={styles.body}>{user?.phone_e164}</Text>
        <Text style={styles.body}>{user?.email ?? t("noEmail")}</Text>
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
        <Card key={group.title}>
          <Text accessibilityRole="header" style={styles.group}>{t(group.title)}</Text>
          {group.links.map(([label, Icon, path]) => (
            <Pressable
              accessibilityRole="button"
              key={label}
              style={styles.item}
              onPress={() => router.push(path as never)}
            >
              <View style={styles.icon}>
                <Icon size={28} color={colors.navy800} />
              </View>
              <Text style={styles.label}>{t(label)}</Text>
              <ChevronRight size={19} color={colors.neutral500} />
            </Pressable>
          ))}
        </Card>
      ))}
      <Pressable
        accessibilityRole="button"
        style={styles.logout}
        onPress={async () => {
          await signOut();
          router.replace("/(auth)/sign-in");
        }}
      >
        <LogOut size={20} color={colors.danger} />
        <Text style={styles.logoutText}>{t("signOutSecurely")}</Text>
      </Pressable>
      <Text style={styles.version}>
        OpesInsure {Constants.expoConfig?.version ?? ""} · Opesware Technologies
      </Text>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  group: { ...type.caption, color: colors.neutral600, letterSpacing: 1, textTransform: "uppercase" },
  body: { ...type.body, color: colors.neutral600 },
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
  item: {
    minHeight: 58,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral100,
  },
  icon: {
    width: 36,
    height: 36,
    borderRadius: radius.control,
    alignItems: "center",
    justifyContent: "center",
  },
  label: { ...type.body, flex: 1, color: colors.navy950 },
  logout: {
    height: 50,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "center",
    gap: space.x2,
  },
  logoutText: { ...type.label, color: colors.dangerText },
  version: { ...type.meta, color: colors.neutral500, textAlign: "center" },
});
