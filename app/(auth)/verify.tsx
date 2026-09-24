import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { AuthApi, type OtpChannel } from "@/api/client";
import { finishSignIn } from "@/components/auth/finishSignIn";
import { colors, type } from "@/theme/tokens";

const channelLabel: Record<string, string> = {
  whatsapp: "on WhatsApp",
  sms: "by SMS",
  email: "by email",
};

export default function Verify() {
  const params = useLocalSearchParams<{
    challengeId: string;
    phone: string;
    expiresIn: string;
    channel?: string;
    prefill?: string;
    invite?: string;
  }>();
  const [challengeId, setChallengeId] = useState(params.challengeId);
  // A demo sign-in passes the known code through so a reviewer never types it.
  const [code, setCode] = useState(params.prefill ?? "");
  const [busy, setBusy] = useState(false);
  const [resending, setResending] = useState(false);
  const [error, setError] = useState<string>();
  const [notice, setNotice] = useState<string>();
  const otpChannel: OtpChannel | undefined =
    params.channel === "whatsapp" || params.channel === "sms" ? params.channel : undefined;

  const submit = async () => {
    if (!challengeId || !params.phone) {
      setError("This sign-in request is incomplete. Start again.");
      return;
    }
    setBusy(true);
    setError(undefined);
    try {
      const auth = await AuthApi.verifyOtp(challengeId, params.phone, code);
      await finishSignIn(auth, params.invite);
    } catch (e) {
      setError(e instanceof Error ? e.message : "The code could not be verified.");
    } finally {
      setBusy(false);
    }
  };

  // Email codes have no resend endpoint yet: hide resend rather than
  // silently switching the user to a phone channel.
  const isEmail = params.channel === "email";
  const resend = async () => {
    if (!params.phone || isEmail) return;
    setResending(true);
    setError(undefined);
    setNotice(undefined);
    try {
      const next = await AuthApi.requestOtp(params.phone, otpChannel);
      setChallengeId(next.challenge_id);
      setCode("");
      setNotice("A new code is on its way.");
    } catch (e) {
      setError(e instanceof Error ? e.message : "A new code could not be sent.");
    } finally {
      setResending(false);
    }
  };

  const via = params.channel ? channelLabel[params.channel] ?? "" : "";
  return (
    <Screen>
      <AppHeader
        title={isEmail ? "Verify your email" : "Verify your number"}
        subtitle={`Enter the 6-digit code sent ${via ? `${via} ` : ""}${isEmail ? "" : `to ${params.phone ?? "your phone"}`}`.trim()}
        back
      />
      <Card feature>
        <TextField
          label="Security code"
          value={code}
          onChangeText={(v) => setCode(v.replace(/\D/g, ""))}
          keyboardType="number-pad"
          textContentType="oneTimeCode"
          autoComplete="sms-otp"
          maxLength={6}
          placeholder="000000"
          error={error}
        />
        <Button
          label="Verify and continue"
          loading={busy}
          disabled={code.length !== 6}
          onPress={() => void submit()}
        />
        {isEmail ? null : <Button
          label="Send another code"
          loading={resending}
          variant="tertiary"
          onPress={() => void resend()}
        />}
        {notice ? <Text style={styles.help}>{notice}</Text> : null}
        <Text style={styles.help}>
          Codes expire quickly and can be used once. OpesInsure will never ask you to share this code by phone or chat.
        </Text>
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({ help: { ...type.meta, color: colors.neutral600 } });
