import React, { useCallback, useEffect, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AuthApi, type OtpChannel } from "@/api/client";
import { finishSignIn } from "@/components/auth/finishSignIn";
import { LockoutNotice } from "@/components/auth/LockoutNotice";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { formatCountdown, isLockout, lockoutSeconds } from "@/lib/customerLogic";
import { colors, type } from "@/theme/tokens";

const channelKey: Record<string, CopyKey> = {
  whatsapp: "viaWhatsapp",
  sms: "viaSms",
  email: "viaEmail",
};
/** Minimum wait between two "send another code" requests. */
const RESEND_COOLDOWN = 60;

export default function Verify() {
  const { t } = useTranslation();
  const params = useLocalSearchParams<{
    challengeId: string;
    phone: string;
    expiresIn: string;
    channel?: string;
    prefill?: string;
    invite?: string;
  }>();
  const initialExpiry = Number(params.expiresIn) > 0 ? Number(params.expiresIn) : 300;
  const [challengeId, setChallengeId] = useState(params.challengeId);
  // A demo sign-in passes the known code through so a reviewer never types it.
  const [code, setCode] = useState(params.prefill ?? "");
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
    <Screen>
      <AppHeader
        title={isEmail ? t("verifyEmailTitle") : t("verifyNumberTitle")}
        subtitle={
          isEmail
            ? t("otpSentEmail", { via })
            : t("otpSentPhone", { via, phone: params.phone ?? t("yourPhone") })
        }
        back
      />
      {locked ? <LockoutNotice seconds={locked} onDone={unlock} /> : null}
      <Card feature>
        <TextField
          label={t("securityCode")}
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
        <Button
          label={t("verifyContinue")}
          loading={busy}
          disabled={code.length !== 6 || expired || !!locked}
          onPress={() => void submit()}
        />
        {isEmail ? null : (
          <Button
            label={resendIn > 0 ? t("otpResendIn", { time: formatCountdown(resendIn) }) : t("otpResend")}
            loading={resending}
            disabled={resendIn > 0 || !!locked}
            variant={expired ? "secondary" : "tertiary"}
            onPress={() => void resend()}
          />
        )}
        {notice ? <Text accessibilityLiveRegion="polite" style={styles.help}>{notice}</Text> : null}
        <Text style={styles.help}>{t("otpWarning")}</Text>
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  help: { ...type.meta, color: colors.neutral600 },
  timer: { ...type.label, color: colors.neutral700, fontVariant: ["tabular-nums"] },
  expired: { color: colors.dangerText },
});
