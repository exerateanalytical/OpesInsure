import React, { useEffect, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { BadgeCheck, MessageSquareText, ShieldCheck, Smartphone } from "lucide-react-native";
import { Button, Card, Screen, TextField } from "@/components/ui";
import { Banner, BrandHeader } from "@/components/design";
import { ChannelPicker } from "@/components/auth/ChannelPicker";
import { ErrorCard } from "@/components/purchase/PurchaseUi";
import { CustomerApi } from "@/api/customer";
import type { OtpChannel } from "@/api/client";
import { useSession } from "@/store/session";
import { formatCountdown } from "@/lib/customerLogic";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

const RESEND_COOLDOWN = 60;
const CHANNELS: { key: OtpChannel; label: string }[] = [
  { key: "whatsapp", label: "WhatsApp" },
  { key: "sms", label: "SMS" },
];

/**
 * Verify the account's phone number (profile completion step "phone"):
 * POST /me/phone/verification sends a 6-digit code to the number on the account,
 * POST /me/phone/verification/confirm checks it; the session is then re-read so
 * the Profile tab and completion card show the number as verified.
 */
export default function VerifyPhone() {
  const { t } = useTranslation();
  const user = useSession((s) => s.bootstrap?.user);
  const hydrate = useSession((s) => s.hydrate);
  const verified = !!user?.phone_verified_at || (user as { phone_verified?: boolean } | undefined)?.phone_verified === true;
  const [channel, setChannel] = useState<OtpChannel>("whatsapp");
  const [challengeId, setChallengeId] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState<"send" | "confirm" | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [done, setDone] = useState(false);
  const [expiresAt, setExpiresAt] = useState(0);
  const [resendAt, setResendAt] = useState(0);
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    if (!challengeId) return;
    const timer = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(timer);
  }, [challengeId]);
  const expiresIn = Math.max(0, Math.ceil((expiresAt - now) / 1000));
  const resendIn = Math.max(0, Math.ceil((resendAt - now) / 1000));

  const send = async () => {
    setBusy("send");
    setError(null);
    try {
      const r = await CustomerApi.requestPhoneVerification(channel);
      if (r.reason === "already_verified") {
        await hydrate();
        setDone(true);
        return;
      }
      if (!r.challenge_id) throw new Error(t("phoneVerifySendFailed"));
      setChallengeId(r.challenge_id);
      setCode("");
      const start = Date.now();
      setNow(start);
      setExpiresAt(start + (r.expires_in && r.expires_in > 0 ? r.expires_in : 300) * 1000);
      setResendAt(start + RESEND_COOLDOWN * 1000);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  };

  const confirm = async () => {
    if (!challengeId || code.length !== 6) return;
    setBusy("confirm");
    setError(null);
    try {
      await CustomerApi.confirmPhoneVerification(challengeId, code);
      // Re-read the session user: phone_verified_at is what the profile completion reads.
      await hydrate();
      setDone(true);
    } catch (e) {
      setError(e);
    } finally {
      setBusy(null);
    }
  };

  if (done || verified)
    return (
      <Screen>
        <BrandHeader title={t("phoneVerifyTitle")} subtitle={user?.phone_e164 ?? undefined} back right={null} />
        <Banner icon={BadgeCheck} tint="green" title={t("phoneVerifiedTitle")} body={t("phoneVerifiedBody", { phone: user?.phone_e164 ?? "" })} />
        <Button label={t("back")} variant="secondary" onPress={() => (router.canGoBack() ? router.back() : router.replace("/account/profile"))} />
      </Screen>
    );

  return (
    <Screen>
      <BrandHeader title={t("phoneVerifyTitle")} subtitle={t("phoneVerifySubtitle")} back right={null} />
      <Card style={styles.card}>
        <Text style={styles.label}>{t("personalMobile")}</Text>
        <Text style={styles.phone} selectable>{user?.phone_e164 ?? "—"}</Text>
        <Text style={styles.meta}>{t("phoneChangeNote")}</Text>
      </Card>
      {!challengeId ? (
        <>
          <ChannelPicker label={t("sendCodeBy")} options={CHANNELS} value={channel} onChange={setChannel} />
          <Button label={t("phoneVerifySend")} icon={MessageSquareText} loading={busy === "send"} disabled={!!busy || !user?.phone_e164} onPress={() => void send()} />
        </>
      ) : (
        <Card style={styles.card}>
          <Text style={styles.body}>
            {t("otpSentPhone", { via: channel === "sms" ? t("viaSms") : t("viaWhatsapp"), phone: user?.phone_e164 ?? t("yourPhone") })}
          </Text>
          <TextField
            label={t("securityCode")}
            value={code}
            onChangeText={(v) => setCode(v.replace(/\D/g, "").slice(0, 6))}
            keyboardType="number-pad"
            autoComplete="one-time-code"
            textContentType="oneTimeCode"
            maxLength={6}
            hint={expiresIn > 0 ? t("otpExpiresIn", { time: formatCountdown(expiresIn) }) : t("otpExpired")}
          />
          <Button label={t("phoneVerifyConfirm")} icon={ShieldCheck} loading={busy === "confirm"} disabled={!!busy || code.length !== 6 || expiresIn === 0} onPress={() => void confirm()} />
          <Button
            label={busy === "send" ? t("sending") : resendIn > 0 ? t("otpResendIn", { time: formatCountdown(resendIn) }) : t("otpResend")}
            variant="tertiary"
            disabled={!!busy || resendIn > 0}
            onPress={() => void send()}
          />
        </Card>
      )}
      {error ? <ErrorCard error={error} fallback={t("phoneVerifyFailed")} /> : null}
      <Banner icon={Smartphone} tint="blue" body={t("otpWarning")} />
    </Screen>
  );
}

const styles = StyleSheet.create({
  card: { gap: space.x3 },
  label: { ...type.meta, color: colors.neutral600 },
  phone: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
