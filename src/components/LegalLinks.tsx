import React, { useState } from "react";
import { Linking, StyleSheet, Text } from "react-native";
import { ExternalLink, FileText, ShieldCheck, UserX } from "lucide-react-native";
import { Button, Card } from "@/components/ui";
import { useRuntime } from "@/store/runtime";
import { legalLinks } from "@/config/environment";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";

/**
 * Play/App Store required links: privacy policy and account deletion (plus
 * terms). URLs come from the runtime bootstrap `legal` block when the
 * backend exposes it, otherwise the insurance.opesdatacenter.tech defaults.
 * Shown to every role (customer privacy screen and partner account screens).
 */
export function LegalLinks() {
  const { t } = useTranslation();
  const server = useRuntime((s) => s.bootstrap?.legal);
  const links = legalLinks(server);
  const [failed, setFailed] = useState(false);
  const open = async (url: string) => {
    setFailed(false);
    try {
      await Linking.openURL(url);
    } catch {
      setFailed(true);
    }
  };
  return (
    <Card>
      <Text accessibilityRole="header" style={s.title}>{t("legalTitle")}</Text>
      <Button label={t("privacyPolicy")} icon={ShieldCheck} variant="tertiary" onPress={() => void open(links.privacy)} />
      <Button label={t("termsOfUse")} icon={FileText} variant="tertiary" onPress={() => void open(links.terms)} />
      <Button label={t("deleteAccount")} icon={UserX} variant="danger" onPress={() => void open(links.accountDeletion)} />
      <Text style={s.meta}>{t("deleteAccountBody")}</Text>
      {failed ? (
        <Text accessibilityRole="alert" style={s.error}>
          <ExternalLink size={14} color={colors.dangerText} /> {t("openLinkFailed")}
        </Text>
      ) : null}
    </Card>
  );
}
const s = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  error: { ...type.meta, color: colors.dangerText },
});
