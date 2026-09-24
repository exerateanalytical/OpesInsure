import React, { useEffect, useState } from "react";
import { StyleSheet, Switch, Text, View } from "react-native";
import { router } from "expo-router";
import { Download, FileText, Trash2 } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { Preferences } from "@/store/preferences";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * Consent and privacy. Marketing consent is kept on the device (the
 * notification-preference API has no marketing field and the consent API
 * needs a staff permission). Export / deletion requests go through a
 * PRIVACY_REQUEST support case, since customers have no DSR endpoint.
 */
export default function Privacy() {
  const { t } = useTranslation();
  const [marketing, setMarketing] = useState(false);
  const [saved, setSaved] = useState(false);
  useEffect(() => {
    void Preferences.marketingConsent().then(setMarketing);
  }, []);
  const toggle = async (next: boolean) => {
    setMarketing(next);
    await Preferences.setMarketingConsent(next);
    setSaved(true);
  };
  const request = (kind: "export" | "delete") =>
    router.push({
      pathname: "/support/new",
      params: {
        category: "PRIVACY_REQUEST",
        subject: t(kind === "export" ? "privacyExportSubject" : "privacyDeleteSubject"),
        body: t(kind === "export" ? "privacyExportBody" : "privacyDeleteBody"),
      },
    });
  return (
    <Screen>
      <AppHeader title={t("privacyConsent")} subtitle={t("privacySubtitle")} back />
      <Card>
        <View style={styles.row}>
          <View style={styles.flex}>
            <Text style={styles.title}>{t("marketingConsent")}</Text>
            <Text style={styles.body}>{t("marketingConsentBody")}</Text>
          </View>
          <Switch
            accessibilityLabel={t("marketingConsent")}
            value={marketing}
            onValueChange={(v) => void toggle(v)}
            trackColor={{ true: colors.blue600, false: colors.neutral300 }}
          />
        </View>
        {saved ? <Text accessibilityLiveRegion="polite" style={styles.notice}>{t("settingsSaved")}</Text> : null}
      </Card>
      <Card>
        <Text style={styles.title}>{t("privacyYourData")}</Text>
        <Text style={styles.body}>{t("privacyYourDataBody")}</Text>
        <Button label={t("privacyExport")} icon={Download} variant="secondary" onPress={() => request("export")} />
        <Button label={t("privacyDelete")} icon={Trash2} variant="danger" onPress={() => request("delete")} />
        <Text style={styles.meta}>{t("privacyRetentionNote")}</Text>
      </Card>
      <Button label={t("termsAndPrivacy")} icon={FileText} variant="tertiary" onPress={() => router.push("/terms")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1 },
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  meta: { ...type.meta, color: colors.neutral600 },
  notice: { ...type.meta, color: colors.successText },
});
