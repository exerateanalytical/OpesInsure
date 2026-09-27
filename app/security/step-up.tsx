import React, { useEffect, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ShieldCheck } from "lucide-react-native";
import { ApiError, StepUpApi } from "@/api/client";
import { hasPendingStepUp, settleStepUp } from "@/security/step-up";
import { Button, Card, Screen, TextField } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { BrandArt } from "@/components/design/BrandArt";
import { colors, radius, space, type } from "@/theme/tokens";

import { useTranslation } from "@/i18n";
export default function StepUp() {
  const { purpose = "SENSITIVE_ACTION", returnTo = "/", mode } = useLocalSearchParams<{ purpose: string; returnTo: string; mode?: string }>();
  // mode=await: a caller is waiting (withStepUp) and retries its call once we settle.
  const awaiting = mode === "await";
  const { t } = useTranslation();
  const [challenge, setChallenge] = useState<{ challenge_id: string; delivery_hint: string }>();
  const [code, setCode] = useState("");
  const [error, setError] = useState<string>();
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    StepUpApi.request(purpose).then(setChallenge).catch((reason) => {
      // The server does not know this purpose yet (older backend): let the caller go ahead without a grant.
      if (awaiting && hasPendingStepUp(purpose) && reason instanceof ApiError && (reason.status === 422 || reason.status === 400)) {
        settleStepUp("unsupported");
        router.back();
        return;
      }
      setError(reason instanceof Error ? reason.message : t("suStartFailed"));
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [purpose]);
  // Leaving without verifying cancels the waiting action.
  useEffect(() => () => {
    if (awaiting && hasPendingStepUp(purpose)) settleStepUp("cancelled");
  }, [awaiting, purpose]);
  return (
    <Screen>
      <BrandHeader title={t("suTitle")} subtitle={t("suSubtitle")} back right="help" />
      <Card style={styles.card}>
        <View style={styles.headRow}>
          <TintedIcon icon={ShieldCheck} tint="blue" size={56} />
          <View style={styles.flex}>
            <Text style={styles.title}>{t("suHeading")}</Text>
            <Text style={styles.body}>{challenge?.delivery_hint ?? t("suPreparing")}</Text>
          </View>
        </View>
        <TextField label={t("suCode")} value={code} onChangeText={setCode} keyboardType="number-pad" autoComplete="sms-otp" maxLength={6} />
        {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
        <Button
          label={t("suVerify")}
          loading={busy}
          disabled={!challenge || code.length !== 6}
          onPress={async () => {
            if (!challenge) return;
            setBusy(true);
            setError(undefined);
            try {
              await StepUpApi.verify(challenge.challenge_id, purpose, code);
              if (awaiting && hasPendingStepUp(purpose)) {
                settleStepUp("granted");
                router.back();
              } else router.replace(returnTo as never);
            } catch (reason) {
              setError(reason instanceof Error ? reason.message : t("suFailed"));
            } finally {
              setBusy(false);
            }
          }}
        />
      </Card>
      <BrandArt name="logo_wide" width={160} />
      <BrandArt name="wave_lines_icy" width={320} opacity={0.6} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: space.x1 },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
