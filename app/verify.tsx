import React, { useState } from "react";
import { StyleSheet, Text } from "react-native";
import { QrCode, Search, ShieldCheck, ShieldX } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip, TextField } from "@/components/ui";
import { PublicApi, PublicVerification } from "@/api/client";
import { useFormatters } from "@/hooks/useFormatters";
import { errorMessage } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

export default function Verify() {
  const { t, td } = useTranslation();
  const f = useFormatters();
  const [value, setValue] = useState("");
  const [result, setResult] = useState<PublicVerification | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const verify = async () => {
    const reference = value.trim().toUpperCase();
    if (reference.length < 6) {
      setError(t("vfRefShort"));
      return;
    }
    setLoading(true);
    setError(null);
    setResult(null);
    try {
      setResult(await PublicApi.verify(reference));
    } catch (e) {
      setError(errorMessage(e, t("vfFailed")));
    } finally {
      setLoading(false);
    }
  };
  const valid = result?.result === "VALID";
  return (
    <Screen>
      <AppHeader title={t("vfTitle")} subtitle={t("vfSubtitle")} back />
      <Card feature>
        <QrCode size={40} color={colors.blue600} />
        <Text style={styles.title}>{t("vfEnterRef")}</Text>
        <TextField
          label={t("vfRefLabel")}
          value={value}
          onChangeText={setValue}
          placeholder="OI-CM-..."
          autoCapitalize="characters"
          error={error ?? undefined}
        />
        <Button label={t("vfVerify")} icon={Search} loading={loading} disabled={value.trim().length < 6} onPress={() => void verify()} />
      </Card>
      {result ? (
        <Card>
          {valid ? <ShieldCheck size={32} color={colors.success} /> : <ShieldX size={32} color={colors.danger} />}
          <StatusChip
            label={td(`vfResult_${result.result}`, result.result)}
            tone={valid ? "success" : result.result === "NOT_FOUND" ? "danger" : "warning"}
          />
          <Text style={styles.title}>{valid ? t("vfValid") : t("vfInvalid")}</Text>
          {result.carrier_name ? <Text style={styles.body}>{t("vfInsurer", { name: result.carrier_name })}</Text> : null}
          {result.product_class ? <Text style={styles.body}>{t("vfClass", { name: result.product_class })}</Text> : null}
          {result.coverage_starts_at && result.coverage_ends_at ? (
            <Text style={styles.body}>{t("vfCover", { range: f.range(result.coverage_starts_at, result.coverage_ends_at) })}</Text>
          ) : null}
          <Text style={styles.note}>{t("vfChecked", { date: f.dateTime(result.verified_at) })}</Text>
        </Card>
      ) : null}
      <Text style={styles.note}>{t("vfPrivacy")}</Text>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  note: { ...type.meta, color: colors.neutral600 },
});
