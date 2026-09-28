import React, { useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import {
  BadgeCheck,
  Bell,
  BriefcaseBusiness,
  CircleHelp,
  FileLock2,
  History,
  IdCard,
  Languages,
  LogOut,
  Mail,
  MapPin,
  Pencil,
  Phone,
  ShieldCheck,
  UserRound,
  Wallet,
  Building2,
} from "lucide-react-native";
import type { LucideIcon } from "lucide-react-native";
import { AgentApi, AuthApi } from "@/api/client";
import { CustomerApi } from "@/api/customer";
import type { AgentProfile } from "@/api/client";
import {
  AgentAvatar,
  AgentButton,
  AgentCard,
  AgentNavRow,
  AgentSection,
  AgentShell,
  AgentSkeleton,
  AgentStatusChip,
  HeritageAccent,
} from "@/components/agent";
import type { AgentStatusKey } from "@/components/agent";
import { BuildStamp } from "@/components/BuildStamp";
import { useLoad } from "@/hooks/useLoad";
import { kycPhase } from "@/lib/kyc";
import { useSession } from "@/store/session";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentIcon, agentType as T } from "@/theme/agent";

/** Optional fields some backends add to /mobile/agent/profile. */
type AgentProfileExtra = AgentProfile & { region?: string | null; email?: string | null; parent_entity_name?: string | null };

const agentStatusWord = (s?: string | null): AgentStatusKey | null => {
  switch ((s ?? "").toUpperCase()) {
    case "ACTIVE":
      return "Active";
    case "SUSPENDED":
      return "Suspended";
    case "DRAFT":
    case "PENDING_REVIEW":
      return "Pending Verification";
    case "INACTIVE":
      return "Inactive";
    default:
      return null;
  }
};

const verificationWord = (status?: string | null, expiresAt?: string | null): AgentStatusKey => {
  const p = kycPhase(status, expiresAt).phase;
  if (p === "approved") return "Verified";
  if (p === "rejected" || p === "expired") return "Rejected";
  if (p === "in_review" || p === "pending_approval") return "Pending";
  return "Action Required";
};

const mask = (phone?: string | null) => (phone && phone.length > 6 ? `${phone.slice(0, 4)} ••• ${phone.slice(-3)}` : phone ?? "");

/**
 * Screen 01 — Agent Profile / Account Home (visual master, AGENT_UI_SPEC_V2 §9.1).
 * A gateway: the hero summarises the agent, the rows open the real screens.
 * Data: session bootstrap (user, workspace), GET /mobile/agent/profile, GET /mobile/kyc/profile.
 */
export default function AgentAccount() {
  const { t, language } = useTranslation();
  const user = useSession((st) => st.bootstrap?.user);
  const workspace = useSession((st) => st.activeWorkspace);
  const signOut = useSession((st) => st.signOut);
  const profile = useLoad(() => AgentApi.profile() as Promise<AgentProfileExtra>, []);
  // GET /mobile/kyc/profile answers {identifiers, submission}; the badge follows the latest submission.
  const kyc = useLoad(() => CustomerApi.kyc(), []);
  const p = profile.data;
  const notProvided = t("agentNotProvided");

  const [verifyState, setVerifyState] = useState<"idle" | "busy" | "sent" | "error">("idle");
  const emailUnverified = !!user?.email && (user.email_verified_at === null || user.contacts_verified === false);
  const verifyEmail = async () => {
    setVerifyState("busy");
    try {
      const r = await AuthApi.requestEmailVerification();
      setVerifyState(r.sent ? "sent" : "error");
    } catch {
      setVerifyState("error");
    }
  };
  const [signingOut, setSigningOut] = useState(false);
  const doSignOut = async () => {
    setSigningOut(true);
    try {
      await signOut();
    } finally {
      router.replace("/(auth)/sign-in");
    }
  };

  const status = agentStatusWord(p?.status ?? user?.status);
  const verification = kyc.data ? verificationWord(kyc.data.submission?.status, kyc.data.submission?.expires_at) : null;
  const fullName = p?.full_name || user?.full_name || notProvided;
  const facts: { icon: LucideIcon; label: string; value: string | null | undefined }[] = [
    { icon: IdCard, label: t("agentIdLabel"), value: p?.agent_code },
    { icon: Phone, label: t("agentPhoneLabel"), value: user?.phone_e164 },
    { icon: Mail, label: t("agentEmailLabel"), value: user?.email ?? p?.email },
    { icon: Building2, label: t("agentParentLabel"), value: p?.parent_entity_name ?? workspace?.tenant_name },
    { icon: MapPin, label: t("agentRegionLabel"), value: p?.region },
  ];

  return (
    <AgentShell refreshing={profile.loading && !!p} onRefresh={() => { void profile.reload(); void kyc.reload(); }}>
      <Text accessibilityRole="header" style={s.title}>
        {t("agentProfileTitle")}
      </Text>

      {/* Profile hero — the screen's one heritage moment. */}
      <AgentCard style={s.hero}>
        <HeritageAccent variant="africa" size={150} opacity={0.07} style={{ right: -10, top: -6 }} />
        <View style={s.heroTop}>
          <AgentAvatar name={fullName === notProvided ? null : fullName} size={64} />
          <View style={s.flex}>
            <Text style={s.name}>{fullName}</Text>
            <Text style={s.role}>{t("agentRoleName")}</Text>
            <View style={s.chips}>
              {verification ? <AgentStatusChip status={verification} icon={verification === "Verified" ? BadgeCheck : undefined} /> : null}
              {status ? <AgentStatusChip status={status} /> : null}
            </View>
          </View>
        </View>
        {profile.loading && !p ? <AgentSkeleton rows={2} height={36} /> : null}
        {profile.error && !p ? (
          <View style={s.inlineError}>
            <Text accessibilityRole="alert" style={s.errorText}>{t("agentProfileLoadFailed")}</Text>
            <AgentButton label={t("retry")} variant="secondary" onPress={() => void profile.reload()} />
          </View>
        ) : null}
        <View style={s.facts}>
          {facts.map((f) => (
            <View key={f.label} style={s.fact}>
              <f.icon size={agentIcon.small} color={c.secondary} strokeWidth={agentIcon.stroke} />
              <Text style={s.factLabel}>{f.label}</Text>
              <Text style={[s.factValue, !f.value && s.factMissing]} selectable>
                {f.value || notProvided}
              </Text>
            </View>
          ))}
        </View>
        <AgentButton label={t("agentEditProfile")} icon={Pencil} variant="secondary" onPress={() => router.push("/account/profile")} />
      </AgentCard>

      <AgentSection title={t("agentSecAccount")}>
        <AgentCard padded={false}>
          <AgentNavRow divider={false} icon={UserRound} title={t("agentPersonalInfo")} subtitle={t("agentPersonalInfoSub")} onPress={() => router.push("/account/profile")} />
          <AgentNavRow icon={BriefcaseBusiness} title={t("agentAgentInfo")} subtitle={p?.agent_code ?? t("agentAgentInfoSub")} onPress={() => router.push("/agent/onboarding")} />
          <AgentNavRow icon={ShieldCheck} title={t("agentKyc")} subtitle={t("agentKycSub")} status={verification ?? undefined} onPress={() => router.push("/onboarding/kyc")} />
          <AgentNavRow icon={Wallet} title={t("agentPayout")} subtitle={p?.momo_phone_e164 ? `${t("agentMomo")} · ${mask(p.momo_phone_e164)}` : notProvided} onPress={() => router.push("/agent/onboarding")} />
          {emailUnverified ? (
            <AgentNavRow
              icon={Mail}
              title={t("portalEmailVerification")}
              subtitle={
                verifyState === "busy"
                  ? t("portalEmailSending")
                  : verifyState === "sent"
                    ? t("portalEmailSent")
                    : verifyState === "error"
                      ? t("portalEmailRetry")
                      : t("portalEmailVerify")
              }
              status="Action Required"
              busy={verifyState === "busy"}
              onPress={verifyState === "sent" ? undefined : () => void verifyEmail()}
            />
          ) : null}
        </AgentCard>
      </AgentSection>

      <AgentSection title={t("agentSecPreferences")}>
        <AgentCard padded={false}>
          <AgentNavRow divider={false} icon={Bell} title={t("agentNotifSettings")} subtitle={t("agentNotifSettingsSub")} onPress={() => router.push("/account/notifications")} />
          <AgentNavRow icon={Languages} title={t("agentLangRegion")} subtitle={language === "fr" ? "Français" : "English"} onPress={() => router.push("/account/language")} />
        </AgentCard>
      </AgentSection>

      <AgentSection title={t("agentSecSecurity")}>
        <AgentCard padded={false}>
          <AgentNavRow
            divider={false}
            icon={ShieldCheck}
            title={t("agentSecuritySessions")}
            subtitle={user?.phone_verified_at ? `${t("portalPhoneVerification")} · ${t("portalVerified")}` : `${t("portalPhoneVerification")} · ${t("portalNotVerified")}`}
            onPress={() => router.push("/account/security")}
          />
          <AgentNavRow icon={History} title={t("agentLoginActivity")} subtitle={t("agentLoginActivitySub")} onPress={() => router.push("/account/login-activity")} />
        </AgentCard>
      </AgentSection>

      <AgentSection title={t("agentSecSupport")}>
        <AgentCard padded={false}>
          <AgentNavRow divider={false} icon={CircleHelp} title={t("agentHelp")} subtitle={t("agentHelpSub")} onPress={() => router.push("/support/faq")} />
          <AgentNavRow icon={FileLock2} title={t("agentPrivacyLegal")} subtitle={t("agentPrivacyLegalSub")} onPress={() => router.push("/account/privacy")} />
        </AgentCard>
      </AgentSection>

      <AgentButton label={t("signOutSecurely")} icon={LogOut} variant="danger" loading={signingOut} onPress={() => void doSignOut()} />
      <BuildStamp />
    </AgentShell>
  );
}

const s = StyleSheet.create({
  flex: { flex: 1 },
  title: { ...T.screenTitle, color: c.heading },
  hero: { gap: 16 },
  heroTop: { flexDirection: "row", gap: 14, alignItems: "center" },
  name: { ...T.sectionTitle, color: c.heading },
  role: { ...T.secondary, color: c.secondary, marginTop: 2 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: 6, marginTop: 8 },
  facts: { gap: 10, borderTopWidth: 1, borderTopColor: c.border, paddingTop: 14 },
  fact: { flexDirection: "row", alignItems: "center", gap: 10 },
  factLabel: { ...T.secondary, color: c.secondary, width: 104 },
  factValue: { ...T.body, color: c.text, flex: 1, fontFamily: "Inter_500Medium" },
  factMissing: { color: c.muted, fontFamily: "Inter_400Regular" },
  inlineError: { gap: 8 },
  errorText: { ...T.secondary, color: c.danger },
});
