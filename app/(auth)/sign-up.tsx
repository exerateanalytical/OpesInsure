import React, { useCallback, useEffect, useState } from "react";
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router } from "expo-router";
import {
  ArrowRight,
  Building2,
  Check,
  Handshake,
  KeyRound,
  Mail,
  Phone,
  ShieldCheck,
  UserRound,
  UsersRound,
} from "lucide-react-native";
import { AuthCard, AuthHero } from "@/components/auth/AuthHero";
import { AuthFooterBranding } from "@/components/auth/AuthFooter";
import { AuthPrimaryButton, AuthTextField } from "@/components/auth/AuthField";
import { AccountTypeSelector } from "@/components/auth/AccountTypeSelector";
import { TrustStrip } from "@/components/auth/TrustStrip";
import { ChannelPicker } from "@/components/auth/ChannelPicker";
import { Preferences } from "@/store/preferences";
import { LockoutNotice } from "@/components/auth/LockoutNotice";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { isLockout, lockoutSeconds } from "@/lib/customerLogic";
import { finishSignIn, isCameroonMobile, normalizeCameroonPhone } from "@/components/auth/finishSignIn";
import { authColors, authRadius, authSpace, authType } from "@/theme/tokens";
import { AuthApi, hasTokens, type VerificationChannel } from "@/api/client";

const TERMS_VERSION = "2026-01-01";

const accountTypes: { key: string; label: CopyKey; icon: typeof UsersRound }[] = [
  { key: "CUSTOMER", label: "customer", icon: UsersRound },
  { key: "INSURER", label: "insurer", icon: Building2 },
  { key: "BROKER", label: "broker", icon: Handshake },
  { key: "AGENT", label: "agent", icon: UserRound },
];

const trustItems: { icon: typeof Check; label: CopyKey }[] = [
  { icon: ShieldCheck, label: "welcomeLicensed" },
  { icon: Check, label: "welcomeSecurePayments" },
  { icon: Check, label: "welcomeVerifiedProducts" },
];

export default function SignUp() {
  const [accountType, setAccountType] = useState("CUSTOMER");
  const [fullName, setFullName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [channel, setChannel] = useState<VerificationChannel>("whatsapp");
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const { t } = useTranslation();
  const [locked, setLocked] = useState<number | null>(null);
  const unlock = useCallback(() => setLocked(null), []);

  const hasEmail = /^\S+@\S+\.\S+$/.test(email.trim());
  const channelOptions: { key: VerificationChannel; label: string }[] = [
    { key: "whatsapp", label: "WhatsApp" },
    { key: "sms", label: "SMS" },
    ...(hasEmail ? [{ key: "email" as const, label: "Email" }] : []),
  ];
  // Email stops being a valid channel as soon as the address is cleared.
  useEffect(() => {
    if (!hasEmail && channel === "email") setChannel("whatsapp");
  }, [hasEmail, channel]);

  const submit = async () => {
    setError(undefined);
    setFieldErrors({});

    const normalizedPhone = normalizeCameroonPhone(phone);
    const errors: Record<string, string> = {};
    if (fullName.trim().length < 3) errors.fullName = t("fullNameRequired");
    if (!isCameroonMobile(normalizedPhone)) errors.phone = t("phoneInvalid");
    if (email.trim() && !hasEmail) errors.email = t("emailInvalidOptional");
    if (password.length < 8) errors.password = t("passwordMin");
    else if (password !== confirm) errors.confirm = t("passwordMismatch");
    if (!agreed) errors.agreed = t("requiredToContinue");
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setBusy(true);
    try {
      const result = await AuthApi.register({
        full_name: fullName.trim(),
        phone_e164: normalizedPhone,
        email: email.trim() || undefined,
        password,
        password_confirmation: confirm,
        locale: "en",
        terms_version: TERMS_VERSION,
        verification_channel: channel,
      });
      // New customers get the short profile/KYC step on first landing.
      await Preferences.setPendingOnboarding(true);
      // Verification lifted (server default): the account is active and the
      // response already signed us in.
      if (hasTokens(result)) {
        await finishSignIn(result);
        return;
      }
      // Verification required: continue with the code on the chosen channel.
      let challengeId = result.challenge_id;
      let expiresIn = result.expires_in;
      if (!challengeId && channel !== "email") {
        const challenge = await AuthApi.requestOtp(normalizedPhone, channel);
        challengeId = challenge.challenge_id;
        expiresIn = challenge.expires_in;
      }
      if (!challengeId) {
        // Email verification without a phone challenge: sign in normally.
        const auth = await AuthApi.passwordLogin(normalizedPhone, password);
        await finishSignIn(auth);
        return;
      }
      router.push({
        pathname: "/(auth)/verify",
        params: {
          challengeId,
          phone: normalizedPhone,
          channel: result.verification_channel ?? channel,
          expiresIn: String(expiresIn ?? ""),
        },
      });
    } catch (e) {
      if (isLockout(e)) {
        setLocked(lockoutSeconds(e));
        return;
      }
      if (e && typeof e === "object" && "fields" in e && e.fields) {
        const serverErrors: Record<string, string> = {};
        const fields = e.fields as Record<string, string[]>;
        if (fields.full_name?.[0]) serverErrors.fullName = fields.full_name[0];
        const phoneError = fields.phone_e164?.[0] ?? fields.phone?.[0];
        if (phoneError) serverErrors.phone = phoneError;
        if (fields.email?.[0]) serverErrors.email = fields.email[0];
        if (fields.password?.[0]) serverErrors.password = fields.password[0];
        setFieldErrors(serverErrors);
      }
      setError(e instanceof Error ? e.message : t("signUpFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : undefined}
        style={styles.flex}
      >
        <ScrollView showsVerticalScrollIndicator={false} keyboardShouldPersistTaps="handled">
          <AuthHero
            heading={t("signUpHeading")}
            subheading={t("signUpSubheading")}
          />
          <AuthCard>
            {locked ? <LockoutNotice seconds={locked} onDone={unlock} /> : null}
            <AccountTypeSelector options={accountTypes.map((a) => ({ ...a, label: t(a.label) }))} value={accountType} onChange={setAccountType} />

            {accountType !== "CUSTOMER" ? (
              <View style={styles.comingSoon}>
                <Text style={styles.comingSoonTitle}>{t("partnersByInvitation")}</Text>
                <Text style={styles.comingSoonBody}>
                  {t(accountType === "INSURER" ? "partnersInvitationInsurers" : accountType === "BROKER" ? "partnersInvitationBrokers" : "partnersInvitationAgents")}
                </Text>
                <Pressable accessibilityRole="button" onPress={() => router.push("/(auth)/invitation")}>
                  <Text style={styles.comingSoonLink}>{t("haveInvitationCode")}</Text>
                </Pressable>
                <Pressable accessibilityRole="button" onPress={() => setAccountType("CUSTOMER")}>
                  <Text style={styles.comingSoonLink}>{t("continueAsCustomer")}</Text>
                </Pressable>
              </View>
            ) : (
              <>
                <AuthTextField
                  icon={UserRound}
                  placeholder={t("fullName")}
                  value={fullName}
                  onChangeText={setFullName}
                  autoCapitalize="words"
                  error={fieldErrors.fullName}
                />
                <AuthTextField
                  icon={Phone}
                  placeholder={t("mobileNumber")}
                  value={phone}
                  onChangeText={setPhone}
                  keyboardType="phone-pad"
                  autoComplete="tel"
                  error={fieldErrors.phone}
                />
                <Text style={styles.hint}>{t("signUpPhoneHint")}</Text>
                <AuthTextField
                  icon={KeyRound}
                  placeholder={t("passwordMinPlaceholder")}
                  value={password}
                  onChangeText={setPassword}
                  secureToggle
                  autoCapitalize="none"
                  autoComplete="new-password"
                  error={fieldErrors.password}
                />
                <AuthTextField
                  icon={KeyRound}
                  placeholder={t("confirmPassword")}
                  value={confirm}
                  onChangeText={setConfirm}
                  secureToggle
                  autoCapitalize="none"
                  autoComplete="new-password"
                  error={fieldErrors.confirm}
                />
                <AuthTextField
                  icon={Mail}
                  placeholder={t("emailOptional")}
                  value={email}
                  onChangeText={setEmail}
                  keyboardType="email-address"
                  autoCapitalize="none"
                  autoComplete="email"
                  error={fieldErrors.email}
                />
                <Text style={styles.hint}>
                  <Text style={styles.recommended}>{t("recommended")}</Text> · {t("emailRecommendedHint")}
                </Text>
                <ChannelPicker
                  label={t("sendVerificationBy")}
                  options={channelOptions}
                  value={channel}
                  onChange={setChannel}
                />
                <Pressable
                  accessibilityRole="checkbox"
                  accessibilityState={{ checked: agreed }}
                  style={styles.terms}
                  onPress={() => setAgreed((v) => !v)}
                >
                  <View style={[styles.checkbox, agreed && styles.checkboxChecked]}>
                    {agreed ? <Check size={14} color={authColors.white} /> : null}
                  </View>
                  <Text style={styles.termsText}>
                    {t("agreeTo")}{" "}
                    <Text
                      accessibilityRole="link"
                      style={styles.termsLink}
                      onPress={() => router.push("/terms")}
                    >
                      {t("termsAndPrivacy")}
                    </Text>
                    .
                  </Text>
                </Pressable>
                {fieldErrors.agreed ? <Text style={styles.error}>{fieldErrors.agreed}</Text> : null}
                {error ? <Text style={styles.error}>{error}</Text> : null}
                <AuthPrimaryButton
                  label={busy ? t("creating") : t("createAccount")}
                  icon={ArrowRight}
                  loading={busy}
                  disabled={!!locked}
                  onPress={() => void submit()}
                />
              </>
            )}

            <Pressable
              accessibilityRole="button"
              style={styles.signInRow}
              onPress={() => router.replace("/(auth)/sign-in")}
            >
              <Text style={styles.signInText}>
                {t("alreadyHaveAccount")} <Text style={styles.signInLink}>{t("signIn")}</Text>
              </Text>
            </Pressable>

            <View style={styles.divider} />
            <TrustStrip items={trustItems.map((i) => ({ ...i, label: t(i.label) }))} />
          </AuthCard>
          <AuthFooterBranding />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: authColors.navy950 },
  flex: { flex: 1 },
  hint: { ...authType.label, fontSize: 12, color: authColors.slate500, marginTop: -authSpace[2] },
  recommended: { color: authColors.blue500, fontFamily: "Inter_700Bold" },
  terms: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: authSpace[2],
    paddingVertical: authSpace[2],
  },
  checkbox: {
    width: 22,
    height: 22,
    borderRadius: authRadius.sm,
    borderWidth: 1.5,
    borderColor: authColors.ice200,
    alignItems: "center",
    justifyContent: "center",
    marginTop: 1,
  },
  checkboxChecked: { backgroundColor: authColors.blue500, borderColor: authColors.blue500 },
  termsText: { ...authType.body, fontSize: 14, color: authColors.textSecondary, flex: 1 },
  termsLink: { color: authColors.blue500, fontFamily: "Inter_600SemiBold" },
  error: { ...authType.label, fontSize: 12, color: authColors.dangerText },
  signInRow: { alignItems: "center", paddingVertical: authSpace[2] },
  signInText: { ...authType.body, fontSize: 14, color: authColors.textSecondary },
  signInLink: { color: authColors.blue500, fontFamily: "Inter_700Bold" },
  divider: { height: StyleSheet.hairlineWidth, backgroundColor: authColors.ice200, marginTop: authSpace[2] },
  comingSoon: {
    backgroundColor: authColors.ice50,
    borderRadius: authRadius.lg,
    borderWidth: 1,
    borderColor: authColors.ice200,
    padding: authSpace[4],
    gap: authSpace[2],
  },
  comingSoonTitle: { ...authType.label, color: authColors.navy950, fontSize: 15 },
  comingSoonBody: { ...authType.body, fontSize: 14, color: authColors.textSecondary },
  comingSoonLink: { ...authType.label, color: authColors.blue500 },
});
