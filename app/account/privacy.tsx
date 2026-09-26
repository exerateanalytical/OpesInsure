import React, { useEffect, useState } from "react";
import { StyleSheet, Switch, Text, View } from "react-native";
import { router } from "expo-router";
import { Download, FileText, Megaphone, ShieldCheck, Trash2 } from "lucide-react-native";
import { Button, Card, Screen } from "@/components/ui";
import { BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { Preferences } from "@/store/preferences";
import { LegalLinks } from "@/components/LegalLinks";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

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
      <BrandHeader title={t("privacyConsent")} subtitle={t("privacySubtitle")} back right={null} />
      <Card style={styles.card}>
        <View style={styles.row}>
          <TintedIcon icon={Megaphone} tint={marketing ? "blue" : "neutral"} size={44} />
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
      <Card style={styles.card}>
        <SectionHeading title={t("privacyYourData")} icon={ShieldCheck} />
        <Text style={styles.body}>{t("privacyYourDataBody")}</Text>
        <Button label={t("privacyExport")} icon={Download} variant="secondary" onPress={() => request("export")} />
        <Button label={t("privacyDelete")} icon={Trash2} variant="danger" onPress={() => request("delete")} />
        <Text style={styles.meta}>{t("privacyRetentionNote")}</Text>
      </Card>
      <LegalLinks />
      <Button label={t("termsAndPrivacy")} icon={FileText} variant="tertiary" onPress={() => router.push("/terms")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950 },
  body: { ...type.body, fontSize: 14, lineHeight: 20, color: colors.neutral600, marginTop: 2 },
  meta: { ...type.meta, color: colors.neutral600 },
  notice: { ...type.meta, color: colors.successText },
});
