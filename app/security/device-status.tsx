import React, { useState } from "react";
import { Platform, StyleSheet, Text } from "react-native";
import * as Application from "expo-application";
import { ShieldAlert } from "lucide-react-native";
import { DeviceRiskResult, DeviceSecurityApi } from "@/api/client";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { environmentConfig } from "@/config/environment";
import { colors, type } from "@/theme/tokens";

import { useTranslation } from "@/i18n";
export default function DeviceStatus() {
  const { t } = useTranslation();
  const [result, setResult] = useState<DeviceRiskResult>();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string>();
  const assess = async () => {
    setBusy(true);
    setError(undefined);
    try {
      const challenge = await DeviceSecurityApi.nonce();
      setResult(
        await DeviceSecurityApi.assess({
          nonce: challenge.nonce,
          platform: Platform.OS === "ios" ? "IOS" : "ANDROID",
          provider: "UNAVAILABLE_MANAGED_RUNTIME",
          attestation_token: null,
          app_version: environmentConfig.appVersion,
        }),
      );
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : t("devFailed"));
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <AppHeader title={t("devTitle")} subtitle={t("devSubtitle")} back />
      <Card feature>
        <ShieldAlert size={34} color={colors.navy800} />
        <Text style={styles.title}>{Application.applicationName ?? "OpesInsure"}</Text>
        <Text style={styles.body}>{t("devPackage", { id: Application.applicationId ?? "development" })}</Text>
        <Text style={styles.body}>{t("devVersion", { version: environmentConfig.appVersion, build: environmentConfig.buildVersion })}</Text>
        {/* devBody: this screen never claims that a JavaScript check proves device integrity. */}
        <Text style={styles.body}>{t("devBody")}</Text>
        <Button label={t("devRun")} loading={busy} onPress={() => void assess()} />
      </Card>
      {result ? (
        <Card>
          <StatusChip label={result.action} tone={result.action === "ALLOW" ? "success" : result.action === "LIMIT" ? "warning" : "danger"} />
          <Text style={styles.body}>{t("devAssessment", { id: result.assessment_id })}</Text>
          {result.reasons.map((reason) => <Text key={reason} style={styles.reason}>{reason.replaceAll("_", " ")}</Text>)}
        </Card>
      ) : null}
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.sectionTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  reason: { ...type.meta, color: colors.warningText },
  error: { ...type.meta, color: colors.dangerText },
});
