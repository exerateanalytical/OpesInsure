import React, { useCallback, useState } from "react";
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text } from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import { useLocalSearchParams } from "expo-router";
import { KeyRound, Phone, ShieldCheck } from "lucide-react-native";
import { AuthCard, AuthHero } from "@/components/auth/AuthHero";
import { AuthFooterBranding } from "@/components/auth/AuthFooter";
import { AuthPrimaryButton, AuthSecondaryButton, AuthTextField } from "@/components/auth/AuthField";
import { ChannelPicker } from "@/components/auth/ChannelPicker";
import { finishSignIn, isCameroonMobile, normalizeCameroonPhone } from "@/components/auth/finishSignIn";
import { AuthApi, type OtpChannel } from "@/api/client";
import { authColors, authSpace, colors, type } from "@/theme/tokens";
import { LockoutNotice } from "@/components/auth/LockoutNotice";
import { useTranslation } from "@/i18n";
import { isLockout, lockoutSeconds } from "@/lib/customerLogic";

const channels: { key: OtpChannel; label: string }[] = [
  { key: "whatsapp", label: "WhatsApp" },
  { key: "sms", label: "SMS" },
];

/** Forgot password: code to the phone (WhatsApp or SMS), then a new password.
 * A successful reset returns the sign-in payload, so the user lands signed in. */
export default function ForgotPassword() {
  const params = useLocalSearchParams<{ phone?: string }>();
  const [step, setStep] = useState<"request" | "reset">("request");
  const [phone, setPhone] = useState(params.phone ?? "");
  const [channel, setChannel] = useState<OtpChannel>("whatsapp");
  const [challengeId, setChallengeId] = useState<string>();
  const [code, setCode] = useState("");
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const normalized = normalizeCameroonPhone(phone);
  const { t } = useTranslation();
  const insets = useSafeAreaInsets();
  const [locked, setLocked] = useState<number | null>(null);
  const unlock = useCallback(() => setLocked(null), []);
  const fail = (e: unknown, fallback: string) => {
    if (isLockout(e)) setLocked(lockoutSeconds(e));
    else setError(e instanceof Error ? e.message : fallback);
  };

  const request = async () => {
    if (!isCameroonMobile(normalized)) {
      setError(t("phoneInvalid"));
      return;
    }
    setBusy(true);
    setError(undefined);
    try {
      const challenge = await AuthApi.forgotPassword(normalized, channel);
      setChallengeId(challenge.challenge_id);
      setCode("");
      setStep("reset");
    } catch (e) {
      fail(e, t("otpRequestFailed"));
    } finally {
      setBusy(false);
    }
  };

  const reset = async () => {
    if (!challengeId) return setError(t("resetRequestFirst"));
    if (code.length !== 6) return setError(t("resetEnterCode"));
    if (password.length < 8) return setError(t("passwordMin"));
    if (password !== confirm) return setError(t("passwordMismatch"));
    setBusy(true);
    setError(undefined);
    try {
      const auth = await AuthApi.resetPassword(normalized, challengeId, code, password);
      await finishSignIn(auth);
    } catch (e) {
      fail(e, t("resetFailed"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : "height"} style={styles.flex}>
        <ScrollView
          showsVerticalScrollIndicator={false}
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={{ paddingBottom: insets.bottom + authSpace[3] }}
        >
      <AuthHero compact back heading={t("resetTitle")} subheading={t("resetSubtitle")} />
      <AuthCard>
        {locked ? <LockoutNotice seconds={locked} onDone={unlock} /> : null}
        {step === "request" ? (
          <>
            <AuthTextField
              icon={Phone}
              placeholder={t("mobileNumber")}
              value={phone}
              onChangeText={setPhone}
              keyboardType="phone-pad"
              error={error}
            />
            <ChannelPicker label={t("sendCodeBy")} options={channels} value={channel} onChange={setChannel} />
            <AuthPrimaryButton label={t("sendCode")} loading={busy} disabled={!!locked} onPress={() => void request()} />
          </>
        ) : (
          <>
            <Text style={styles.body}>{t("resetEnterBody", { phone: normalized })}</Text>
            <AuthTextField
              icon={ShieldCheck}
              placeholder={t("sixDigitCode")}
              value={code}
              onChangeText={(v) => setCode(v.replace(/\D/g, ""))}
              keyboardType="number-pad"
              textContentType="oneTimeCode"
              autoComplete="sms-otp"
              maxLength={6}
            />
            <AuthTextField
              icon={KeyRound}
              placeholder={t("newPassword")}
              value={password}
              onChangeText={setPassword}
              secureToggle
              autoCapitalize="none"
              autoComplete="new-password"
            />
            <AuthTextField
              icon={KeyRound}
              placeholder={t("confirmNewPassword")}
              value={confirm}
              onChangeText={setConfirm}
              secureToggle
              autoCapitalize="none"
              autoComplete="new-password"
              error={error}
            />
            <AuthPrimaryButton label={t("saveAndSignIn")} loading={busy} disabled={!!locked} onPress={() => void reset()} />
            <AuthSecondaryButton label={t("sendNewCode")} disabled={busy || !!locked} onPress={() => void request()} />
          </>
        )}
      </AuthCard>
      <AuthFooterBranding tone="light" />
        </ScrollView>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}
const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: authColors.canvas },
  flex: { flex: 1 },
  body: { ...type.body, color: colors.neutral600 },
});
