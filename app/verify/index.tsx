import React, { useEffect, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { QrCode, ScanLine, Search } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { parseVerifyInput, verifyRoute } from "@/lib/verifyLink";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/**
 * In-app document verification entry: type a verification code / certificate
 * serial / policy number, or scan the QR. Deep links from printed QR codes
 * (https://insurance.opesdatacenter.tech/verify?code=… or ?ref=…&t=…, and
 * opesinsure://verify?code=…) land here and are forwarded to the result page
 * app/verify/[code].tsx — no browser involved.
 */
export default function Verify() {
  const { t } = useTranslation();
  const params = useLocalSearchParams<{ code?: string; ref?: string; t?: string }>();
  const [value, setValue] = useState("");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const incoming = (params.code ?? params.ref ?? "").trim();
    if (incoming) router.replace(verifyRoute({ reference: incoming, token: params.t?.trim() || null }));
  }, [params.code, params.ref, params.t]);

  const verify = () => {
    const target = parseVerifyInput(value);
    if (!target || target.reference.length < 6) {
      setError(t("vfRefShort"));
      return;
    }
    setError(null);
    router.push(verifyRoute({ ...target, reference: target.token ? target.reference : target.reference.toUpperCase() }));
  };
  return (
    <Screen>
      <AppHeader title={t("vfTitle")} subtitle={t("vfSubtitle")} back />
      <Card feature>
        <QrCode size={40} color={colors.blue600} />
        <Text style={styles.title}>{t("vfScanTitle")}</Text>
        <Text style={styles.body}>{t("vfScanBody")}</Text>
        <Button label={t("vfScan")} icon={ScanLine} onPress={() => router.push("/verify/scan")} />
      </Card>
      <Card>
        <Text style={styles.title}>{t("vfEnterRef")}</Text>
        <TextField
          label={t("vfRefLabel")}
          value={value}
          onChangeText={setValue}
          placeholder="OI-CM-..."
          autoCapitalize="characters"
          error={error ?? undefined}
          returnKeyType="search"
          onSubmitEditing={verify}
        />
        <Button label={t("vfVerify")} icon={Search} variant="secondary" disabled={value.trim().length < 6} onPress={verify} />
      </Card>
      <Text style={styles.note}>{t("vfPrivacy")}</Text>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  note: { ...type.meta, color: colors.neutral600 },
});
