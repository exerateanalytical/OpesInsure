import React from "react";
import { Linking, StyleSheet, Text } from "react-native";
import { ExternalLink } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { SupportContactList } from "@/components/auth/SupportContacts";
import { useRuntime } from "@/store/runtime";
import { legalLinks } from "@/config/environment";
import { colors, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";

// Draft terms, bundled in EN and FR (i18n termsS1..S7). They are pending legal review: counsel supplies
// the final text. The backend has no legal-document/CMS endpoint yet; when the runtime bootstrap
// publishes a terms_url, the published version is linked from here. Keep TERMS_VERSION in sign-up.tsx in sync.
const TERMS_VERSION = "2026-01-01";
const SECTIONS: [CopyKey, CopyKey][] = [
  ["termsS1Title", "termsS1Body"],
  ["termsS2Title", "termsS2Body"],
  ["termsS3Title", "termsS3Body"],
  ["termsS4Title", "termsS4Body"],
  ["termsS5Title", "termsS5Body"],
  ["termsS6Title", "termsS6Body"],
  ["termsS7Title", "termsS7Body"],
];

export default function Terms() {
  const { t } = useTranslation();
  const legal = useRuntime((s) => s.bootstrap?.legal);
  const published = legalLinks(legal).terms;
  return (
    <Screen>
      <AppHeader title={t("termsTitle")} subtitle={t("termsVersion", { version: TERMS_VERSION })} back />
      <Card>
        <StatusChip label={t("termsDraft")} tone="warning" />
        <Text style={styles.meta}>{t("termsDraftNotice")}</Text>
        {published ? (
          <Button label={t("termsReadPublished")} icon={ExternalLink} variant="secondary" onPress={() => void Linking.openURL(published).catch(() => undefined)} />
        ) : null}
      </Card>
      {SECTIONS.map(([title, body], i) => (
        <Card key={title}>
          <Text style={styles.title} accessibilityRole="header">{t(title)}</Text>
          <Text style={styles.body}>{t(body)}</Text>
          {i === SECTIONS.length - 1 ? <SupportContactList /> : null}
        </Card>
      ))}
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
