import React, { useCallback, useEffect, useState } from "react";
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, Text } from "react-native";
import { SafeAreaView, useSafeAreaInsets } from "react-native-safe-area-context";
import { useLocalSearchParams } from "expo-router";
import { Button } from "@/components/ui";
import { ShieldCheck } from "lucide-react-native";
import { AuthCard, AuthHero } from "@/components/auth/AuthHero";
import { AuthTextField } from "@/components/auth/AuthField";
import { AuthFooterBranding } from "@/components/auth/AuthFooter";
import { AuthApi, type OtpChannel } from "@/api/client";
import { finishSignIn } from "@/components/auth/finishSignIn";
import { LockoutNotice } from "@/components/auth/LockoutNotice";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { formatCountdown, isLockout, lockoutSeconds } from "@/lib/customerLogic";
import { authColors, authSpace, colors, type } from "@/theme/tokens";

const channelKey: Record<string, CopyKey> = {
  whatsapp: "viaWhatsapp",
  sms: "viaSms",
  email: "viaEmail",
};
/** Minimum wait between two "send another code" requests. */
const RESEND_COOLDOWN = 60;

export default function Verify() {
  const { t } = useTranslation();
  const insets = useSafeAreaInsets();
  const params = useLocalSearchParams<{
    challengeId: string;
    phone: string;
    expiresIn: string;
    channel?: string;
    invite?: string;
  }>();
  const initialExpiry = Number(params.expiresIn) > 0 ? Number(params.expiresIn) : 300;
  const [challengeId, setChallengeId] = useState(params.challengeId);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [resending, setResending] = useState(false);
  const [error, setError] = useState<string>();
  const [notice, setNotice] = useState<string>();
  const [expiresAt, setExpiresAt] = useState(() => Date.now() + initialExpiry * 1000);
  const [resendAt, setResendAt] = useState(() => Date.now() + RESEND_COOLDOWN * 1000);
  const [now, setNow] = useState(Date.now());
  const [locked, setLocked] = useState<number | null>(null);
  const otpChannel: OtpChannel | undefined =
    params.channel === "whatsapp" || params.channel === "sms" ? params.channel : undefined;

  useEffect(() => {
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, []);
  const expiresIn = Math.max(0, Math.ceil((expiresAt - now) / 1000));
  const resendIn = Math.max(0, Math.ceil((resendAt - now) / 1000));
  const expired = expiresIn === 0;
  const unlock = useCallback(() => setLocked(null), []);

  const submit = async () => {
    if (!challengeId || !params.phone) {
      setError(t("otpIncomplete"));
      return;
    }
    setBusy(true);
    setError(undefined);
    try {
      const auth = await AuthApi.verifyOtp(challengeId, params.phone, code);
      await finishSignIn(auth, params.invite);
    } catch (e) {
      if (isLockout(e)) setLocked(lockoutSeconds(e));
      else setError(e instanceof Error ? e.message : t("otpVerifyFailed"));
    } finally {
      setBusy(false);
    }
  };

  // Email codes have no resend endpoint yet: hide resend rather than
  // silently switching the user to a phone channel.
  const isEmail = params.channel === "email";
  const resend = async () => {
    if (!params.phone || isEmail || resendIn > 0) return;
    setResending(true);
    setError(undefined);
    setNotice(undefined);
    try {
      const next = await AuthApi.requestOtp(params.phone, otpChannel);
      setChallengeId(next.challenge_id);
      setCode("");
      setExpiresAt(Date.now() + (next.expires_in > 0 ? next.expires_in : 300) * 1000);
      setResendAt(Date.now() + RESEND_COOLDOWN * 1000);
      setNotice(t("otpResent"));
    } catch (e) {
      if (isLockout(e)) {
        setLocked(lockoutSeconds(e));
        setResendAt(Date.now() + lockoutSeconds(e) * 1000);
      } else setError(e instanceof Error ? e.message : t("otpResendFailed"));
    } finally {
      setResending(false);
    }
  };

  const via = params.channel && channelKey[params.channel] ? t(channelKey[params.channel]!) : "";
  return (
    <SafeAreaView edges={["top"]} style={styles.safe}>
      <KeyboardAvoidingView behavior={Platform.OS === "ios" ? "padding" : "height"} style={styles.flex}>
        <ScrollView
          showsVerticalScrollIndicator={false}
          keyboardShouldPersistTaps="handled"
          contentContainerStyle={{ paddingBottom: insets.bottom + authSpace[3] }}
        >
      <AuthHero
        compact
        back
        heading={isEmail ? t("verifyEmailTitle") : t("verifyNumberTitle")}
        subheading={
          isEmail
            ? t("otpSentEmail", { via })
            : t("otpSentPhone", { via, phone: params.phone ?? t("yourPhone") })
        }
      />
      <AuthCard>
        {locked ? <LockoutNotice seconds={locked} onDone={unlock} /> : null}
        <Text style={styles.label}>{t("securityCode")}</Text>
        <AuthTextField
          icon={ShieldCheck}
          value={code}
          onChangeText={(v) => setCode(v.replace(/\D/g, ""))}
          keyboardType="number-pad"
          textContentType="oneTimeCode"
          autoComplete="sms-otp"
          maxLength={6}
          placeholder="000000"
          error={error}
          editable={!locked}
        />
        <Text
          style={[styles.timer, expired && styles.expired]}
          accessibilityLiveRegion={expiresIn % 30 === 0 || expired ? "polite" : "none"}
        >
          {expired ? t("otpExpired") : t("otpExpiresIn", { time: formatCountdown(expiresIn) })}
        </Text>
        <Button variant="brand"
          label={t("verifyContinue")}
          loading={busy}
          disabled={code.length !== 6 || expired || !!locked}
          onPress={() => void submit()}
        />
        {isEmail ? null : (
          <Button variant="brandOutline"
            label={resending ? t("sending") : resendIn > 0 ? t("otpResendIn", { time: formatCountdown(resendIn) }) : t("otpResend")}
            disabled={resendIn > 0 || resending || !!locked}
            onPress={() => void resend()}
          />
        )}
        {notice ? <Text accessibilityLiveRegion="polite" style={styles.help}>{notice}</Text> : null}
        <Text style={styles.help}>{t("otpWarning")}</Text>
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
  label: { ...type.label, color: colors.navy950, marginBottom: -authSpace[1] },
  help: { ...type.meta, color: colors.neutral600 },
  timer: { ...type.label, color: colors.neutral700, fontVariant: ["tabular-nums"] },
  expired: { color: colors.dangerText },
});
