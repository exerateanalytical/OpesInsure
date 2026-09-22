import React, { useState } from "react";
import { Platform, StyleSheet, Text } from "react-native";
import * as Application from "expo-application";
import { ShieldAlert } from "lucide-react-native";
import { DeviceRiskResult, DeviceSecurityApi } from "@/api/client";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { environmentConfig } from "@/config/environment";
import { colors, type } from "@/theme/tokens";

export default function DeviceStatus() {
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
      setError(reason instanceof Error ? reason.message : "Device assessment failed.");
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen>
      <AppHeader title="Device security" subtitle="Server-assessed mobile integrity and release status" back />
      <Card feature>
        <ShieldAlert size={34} color={colors.blue600} />
        <Text style={styles.title}>{Application.applicationName ?? "OpesInsure"}</Text>
        <Text style={styles.body}>Package: {Application.applicationId ?? "development"}</Text>
        <Text style={styles.body}>Version: {environmentConfig.appVersion} ({environmentConfig.buildVersion})</Text>
        <Text style={styles.body}>Native Play Integrity or App Attest evidence must be supplied by an approved production native module. This screen never claims that a JavaScript check proves device integrity.</Text>
        <Button label="Run server device check" loading={busy} onPress={() => void assess()} />
      </Card>
      {result ? (
        <Card>
          <StatusChip label={result.action} tone={result.action === "ALLOW" ? "success" : result.action === "LIMIT" ? "warning" : "danger"} />
          <Text style={styles.body}>Assessment: {result.assessment_id}</Text>
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
