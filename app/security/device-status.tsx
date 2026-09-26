import React, { useState } from "react";
import { Platform, StyleSheet, Text, View } from "react-native";
import * as Application from "expo-application";
import { CircleAlert, ShieldAlert, ShieldCheck } from "lucide-react-native";
import { DeviceRiskResult, DeviceSecurityApi } from "@/api/client";
import { Button, Card, Screen, StatusChip } from "@/components/ui";
import { BrandHeader, TintedIcon } from "@/components/design";
import { environmentConfig } from "@/config/environment";
import { colors, radius, space, type } from "@/theme/tokens";

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
      <BrandHeader title={t("devTitle")} subtitle={t("devSubtitle")} back right={null} />
      <Card style={styles.card}>
        <View style={styles.headRow}>
          <TintedIcon icon={ShieldAlert} tint="blue" size={56} />
          <View style={styles.flex}>
            <Text style={styles.title}>{Application.applicationName ?? "OpesInsure"}</Text>
            <Text style={styles.meta}>{t("devPackage", { id: Application.applicationId ?? "development" })}</Text>
            <Text style={styles.meta}>{t("devVersion", { version: environmentConfig.appVersion, build: environmentConfig.buildVersion })}</Text>
          </View>
        </View>
        {/* devBody: this screen never claims that a JavaScript check proves device integrity. */}
        <Text style={styles.body}>{t("devBody")}</Text>
        <Button label={t("devRun")} loading={busy} onPress={() => void assess()} />
      </Card>
      {result ? (
        <Card style={styles.card}>
          <View style={styles.headRow}>
            <TintedIcon icon={result.action === "ALLOW" ? ShieldCheck : ShieldAlert} tint={result.action === "ALLOW" ? "green" : result.action === "LIMIT" ? "gold" : "red"} size={48} />
            <View style={styles.flex}>
              <StatusChip label={result.action} tone={result.action === "ALLOW" ? "success" : result.action === "LIMIT" ? "warning" : "danger"} />
              <Text style={styles.meta}>{t("devAssessment", { id: result.assessment_id })}</Text>
            </View>
          </View>
          {result.reasons.map((reason) => (
            <View key={reason} style={styles.reasonRow}>
              <CircleAlert size={16} color={colors.warningText} />
              <Text style={styles.reason}>{reason.replaceAll("_", " ")}</Text>
            </View>
          ))}
        </Card>
      ) : null}
      {error ? <Text accessibilityRole="alert" style={styles.error}>{error}</Text> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  flex: { flex: 1, gap: space.x1 },
  card: { borderRadius: radius.feature },
  headRow: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  reasonRow: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  reason: { ...type.meta, color: colors.warningText, flex: 1 },
  error: { ...type.meta, color: colors.dangerText },
});
