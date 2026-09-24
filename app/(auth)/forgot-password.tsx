import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { KeyRound, Phone, ShieldCheck } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { AuthPrimaryButton, AuthSecondaryButton, AuthTextField } from "@/components/auth/AuthField";
import { ChannelPicker } from "@/components/auth/ChannelPicker";
import { finishSignIn, isCameroonMobile, normalizeCameroonPhone } from "@/components/auth/finishSignIn";
import { AuthApi, type OtpChannel } from "@/api/client";
import { colors, type } from "@/theme/tokens";

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

  const request = async () => {
    if (!isCameroonMobile(normalized)) {
      setError("Enter a valid Cameroon mobile number.");
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
      setError(e instanceof Error ? e.message : "The code could not be sent.");
    } finally {
      setBusy(false);
    }
  };

  const reset = async () => {
    if (!challengeId) return setError("Request a new code first.");
    if (code.length !== 6) return setError("Enter the 6-digit code.");
    if (password.length < 8) return setError("Use at least 8 characters.");
    if (password !== confirm) return setError("The passwords do not match.");
    setBusy(true);
    setError(undefined);
    try {
      const auth = await AuthApi.resetPassword(normalized, challengeId, code, password);
      await finishSignIn(auth);
    } catch (e) {
      setError(e instanceof Error ? e.message : "The password could not be reset.");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Screen>
      <AppHeader title="Reset your password" subtitle="We will send a code to your phone" back />
      <Card feature>
        {step === "request" ? (
          <>
            <AuthTextField
              icon={Phone}
              placeholder="Mobile number"
              value={phone}
              onChangeText={setPhone}
              keyboardType="phone-pad"
              error={error}
            />
            <ChannelPicker label="Send my code by" options={channels} value={channel} onChange={setChannel} />
            <AuthPrimaryButton label="Send code" loading={busy} onPress={() => void request()} />
          </>
        ) : (
          <>
            <Text style={styles.body}>Enter the code sent to {normalized} and choose a new password.</Text>
            <AuthTextField
              icon={ShieldCheck}
              placeholder="6-digit code"
              value={code}
              onChangeText={(v) => setCode(v.replace(/\D/g, ""))}
              keyboardType="number-pad"
              textContentType="oneTimeCode"
              autoComplete="sms-otp"
              maxLength={6}
            />
            <AuthTextField
              icon={KeyRound}
              placeholder="New password (min 8 characters)"
              value={password}
              onChangeText={setPassword}
              secureToggle
              autoCapitalize="none"
              autoComplete="new-password"
            />
            <AuthTextField
              icon={KeyRound}
              placeholder="Confirm new password"
              value={confirm}
              onChangeText={setConfirm}
              secureToggle
              autoCapitalize="none"
              autoComplete="new-password"
              error={error}
            />
            <AuthPrimaryButton label="Save and sign in" loading={busy} onPress={() => void reset()} />
            <AuthSecondaryButton label="Send a new code" disabled={busy} onPress={() => void request()} />
          </>
        )}
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({ body: { ...type.body, color: colors.neutral600 } });
