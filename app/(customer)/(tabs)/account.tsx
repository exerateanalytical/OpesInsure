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
  ShieldCheck,
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
import { colors, radius, space, type } from "@/theme/tokens";
const links = [
  ["Saved quotes", Clock3, "/quotes"],
  ["Identity verification", ShieldCheck, "/onboarding/kyc"],
  ["My vehicles & assets", CarFront, "/assets"],
  ["Policy wallet", WalletCards, "/wallet"],
  ["Payments & receipts", CreditCard, "/payments"],
  ["Policy service requests", FileCog, "/services"],
  ["Notification centre", Bell, "/notifications"],
  ["Help & complaints", LifeBuoy, "/support"],
  ["Personal information", UserRound, "/account/profile"],
  ["Security and devices", LockKeyhole, "/account/security"],
  ["Notifications", Bell, "/account/notifications"],
  ["Language", Languages, "/account/language"],
  ["Synchronization", CloudCog, "/sync"],
  ["Data and uploads", Gauge, "/account/data-usage"],
  ["Service status", Activity, "/system/status"],
  ["Device security", ShieldAlert, "/security/device-status"],
  ["Insurance companies", Building2, "/institutions/insurers"],
] as const;
export default function Account() {
  const user = useSession((s) => s.bootstrap?.user);
  const workspace = useSession((s) => s.activeWorkspace);
  const signOut = useSession((s) => s.signOut);
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
        title="Account"
        subtitle={`${user?.full_name ?? "Account"} · ${workspace?.role_code ?? ""}`}
      />
      <Card>
        <Text style={styles.title}>Contact details</Text>
        <Text style={styles.body}>{user?.phone_e164}</Text>
        <Text style={styles.body}>{user?.email ?? "No email supplied"}</Text>
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
                ? "Verification email sent. Check your inbox."
                : verifyState === "busy"
                  ? "Sending…"
                  : verifyState === "error"
                    ? "Could not send. Tap to try again."
                    : "Verify your email"}
            </Text>
          </Pressable>
        ) : null}
      </Card>
      <Card>
        {links.map(([label, Icon, path]) => (
          <Pressable
            accessibilityRole="button"
            key={label}
            style={styles.item}
            onPress={() => router.push(path)}
          >
            <View style={styles.icon}>
              <Icon size={20} color={colors.navy800} />
            </View>
            <Text style={styles.label}>{label}</Text>
            <ChevronRight size={19} color={colors.neutral500} />
          </Pressable>
        ))}
      </Card>
      <Pressable
        accessibilityRole="button"
        style={styles.logout}
        onPress={async () => {
          await signOut();
          router.replace("/(auth)/sign-in");
        }}
      >
        <LogOut size={20} color={colors.danger} />
        <Text style={styles.logoutText}>Sign out securely</Text>
      </Pressable>
      <Text style={styles.version}>
        OpesInsure 1.2.0 · Opesware Technologies
      </Text>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
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
    backgroundColor: colors.neutral50,
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
