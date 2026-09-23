import React, { useState } from "react";
import { KeyboardAvoidingView, Platform, Pressable, ScrollView, StyleSheet, Text, View } from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";
import { router } from "expo-router";
import {
  ArrowRight,
  Building2,
  Check,
  Handshake,
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
import { authColors, authRadius, authSpace, authType } from "@/theme/authTokens";
import { AuthApi } from "@/api/client";

const TERMS_VERSION = "2026-01-01";

const accountTypes = [
  { key: "CUSTOMER", label: "Customer", icon: UsersRound },
  { key: "INSURER", label: "Insurer", icon: Building2 },
  { key: "BROKER", label: "Broker", icon: Handshake },
  { key: "AGENT", label: "Agent", icon: UserRound },
];

const trustItems = [
  { icon: ShieldCheck, label: "Licensed providers" },
  { icon: Check, label: "Secure payments" },
  { icon: Check, label: "Verified products" },
];

export default function SignUp() {
  const [accountType, setAccountType] = useState("CUSTOMER");
  const [fullName, setFullName] = useState("");
  const [phone, setPhone] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [agreed, setAgreed] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = async () => {
    setError(undefined);
    setFieldErrors({});

    const normalizedPhone = phone.replace(/\s/g, "").replace(/^6/, "+2376");
    const errors: Record<string, string> = {};
    if (fullName.trim().length < 3)
      errors.fullName = "Enter your full name.";
    if (!/^\+2376\d{8}$/.test(normalizedPhone))
      errors.phone = "Enter a valid Cameroon mobile number.";
    if (password.length < 12)
      errors.password = "At least 12 characters.";
    if (password !== confirmPassword)
      errors.confirmPassword = "Passwords do not match.";
    if (!agreed) errors.agreed = "Required to continue.";
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors);
      return;
    }

    setBusy(true);
    try {
      await AuthApi.register({
        full_name: fullName.trim(),
        phone_e164: normalizedPhone,
        email: email.trim() || undefined,
        password,
        password_confirmation: confirmPassword,
        locale: "en",
        terms_version: TERMS_VERSION,
      });
      // Registration alone leaves the account unverified. Requesting a code
      // immediately and handing off to the same verify screen sign-in uses
      // means completing that one OTP is the only extra step — it doubles
      // as the account's activation, not a separate email/SMS confirmation.
      const challenge = await AuthApi.requestOtp(normalizedPhone);
      router.push({
        pathname: "/(auth)/verify",
        params: {
          challengeId: challenge.challenge_id,
          phone: normalizedPhone,
          expiresIn: String(challenge.expires_in),
        },
      });
    } catch (e) {
      if (e && typeof e === "object" && "fields" in e && e.fields) {
        const serverErrors: Record<string, string> = {};
        const fields = e.fields as Record<string, string[]>;
        if (fields.full_name?.[0]) serverErrors.fullName = fields.full_name[0];
        if (fields.phone_e164?.[0]) serverErrors.phone = fields.phone_e164[0];
        if (fields.email?.[0]) serverErrors.email = fields.email[0];
        if (fields.password?.[0]) serverErrors.password = fields.password[0];
        setFieldErrors(serverErrors);
      }
      setError(e instanceof Error ? e.message : "Could not create your account.");
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
            heading="Create your account"
            subheading="Join the marketplace to compare, buy and manage insurance from trusted insurers, brokers and agents."
          />
          <AuthCard>
            <AccountTypeSelector options={accountTypes} value={accountType} onChange={setAccountType} />

            {accountType !== "CUSTOMER" ? (
              <View style={styles.comingSoon}>
                <Text style={styles.comingSoonTitle}>
                  {accountType === "INSURER"
                    ? "Insurer onboarding"
                    : accountType === "BROKER"
                      ? "Broker onboarding"
                      : "Agent onboarding"}{" "}
                  isn't open here yet
                </Text>
                <Text style={styles.comingSoonBody}>
                  This marketplace role needs a licence and verification step
                  we haven't wired into self-signup yet. Create a customer
                  account for now, or reach out to our team to be onboarded
                  as {accountType === "INSURER" ? "an insurer" : accountType === "BROKER" ? "a broker" : "an agent"}.
                </Text>
                <Pressable onPress={() => setAccountType("CUSTOMER")}>
                  <Text style={styles.comingSoonLink}>Continue as a customer instead</Text>
                </Pressable>
              </View>
            ) : (
              <>
                <AuthTextField
                  icon={UserRound}
                  placeholder="Full name"
                  value={fullName}
                  onChangeText={setFullName}
                  autoCapitalize="words"
                  error={fieldErrors.fullName}
                />
                <AuthTextField
                  icon={Mail}
                  placeholder="Email address (optional)"
                  value={email}
                  onChangeText={setEmail}
                  keyboardType="email-address"
                  autoCapitalize="none"
                  error={fieldErrors.email}
                />
                <AuthTextField
                  icon={Phone}
                  placeholder="Phone number"
                  value={phone}
                  onChangeText={setPhone}
                  keyboardType="phone-pad"
                  error={fieldErrors.phone}
                />
                <Text style={styles.hint}>Country code +237 · e.g. 6 70 00 00 00</Text>
                <AuthTextField
                  icon={ShieldCheck}
                  placeholder="Password"
                  value={password}
                  onChangeText={setPassword}
                  secureToggle
                  textContentType="newPassword"
                  error={fieldErrors.password}
                />
                <AuthTextField
                  icon={ShieldCheck}
                  placeholder="Confirm password"
                  value={confirmPassword}
                  onChangeText={setConfirmPassword}
                  secureToggle
                  textContentType="newPassword"
                  error={fieldErrors.confirmPassword}
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
                    I agree to the <Text style={styles.termsLink}>Terms & Privacy Policy</Text>.
                  </Text>
                </Pressable>
                {fieldErrors.agreed ? <Text style={styles.error}>{fieldErrors.agreed}</Text> : null}
                {error ? <Text style={styles.error}>{error}</Text> : null}
                <AuthPrimaryButton
                  label={busy ? "Creating…" : "Create Account"}
                  icon={ArrowRight}
                  loading={busy}
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
                Already have an account? <Text style={styles.signInLink}>Sign In</Text>
              </Text>
            </Pressable>

            <View style={styles.divider} />
            <TrustStrip items={trustItems} />
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
  termsLink: { color: authColors.blue500, fontFamily: "Manrope_600SemiBold" },
  error: { ...authType.label, fontSize: 12, color: "#C9363E" },
  signInRow: { alignItems: "center", paddingVertical: authSpace[2] },
  signInText: { ...authType.body, fontSize: 14, color: authColors.textSecondary },
  signInLink: { color: authColors.blue500, fontFamily: "Manrope_700Bold" },
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
