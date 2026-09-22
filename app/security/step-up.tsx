import React, { useEffect, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ShieldCheck } from "lucide-react-native";
import { StepUpApi } from "@/api/client";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { colors, type } from "@/theme/tokens";

export default function StepUp() {
  const { purpose = "SENSITIVE_ACTION", returnTo = "/" } = useLocalSearchParams<{ purpose: string; returnTo: string }>();
  const [challenge, setChallenge] = useState<{ challenge_id: string; delivery_hint: string }>();
  const [code, setCode] = useState("");
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    StepUpApi.request(purpose).then(setChallenge).catch((reason) => setError(reason instanceof Error ? reason.message : "Verification could not start."));
  }, [purpose]);
  return (
    <Screen>
      <AppHeader title="Verify sensitive action" subtitle="This authorization is short-lived and valid for one purpose" back />
      <Card feature>
        <ShieldCheck size={34} color={colors.blue600} />
        <Text style={styles.title}>Additional verification required</Text>
        <Text style={styles.body}>{challenge?.delivery_hint ?? "Preparing a secure verification challenge…"}</Text>
        <TextField label="Verification code" value={code} onChangeText={setCode} keyboardType="number-pad" autoComplete="sms-otp" maxLength={6} />
        {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
        <Button
          label="Verify and return"
          loading={busy}
          disabled={!challenge || code.length !== 6}
          onPress={async () => {
            if (!challenge) return;
            setBusy(true);
            setError(undefined);
            try {
              await StepUpApi.verify(challenge.challenge_id, purpose, code);
              router.replace(returnTo as never);
            } catch (reason) {
              setError(reason instanceof Error ? reason.message : "Verification failed.");
            } finally {
              setBusy(false);
            }
          }}
        />
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.sectionTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
