import React from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { Scale } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

export default function Compare() {
  const { t } = useTranslation();
  return (
    <Screen>
      <AppHeader title={t("cmpTitle")} subtitle={t("cmpSubtitle")} />
      <Card feature>
        <Scale size={36} color={colors.navy800} />
        <Text style={styles.title}>{t("cmpStartTitle")}</Text>
        <Text style={styles.body}>{t("cmpStartBody")}</Text>
        <Button label={t("cmpStart")} onPress={() => router.push("/quote/product")} />
        <Button label={t("quotesTitle")} variant="secondary" onPress={() => router.push("/quotes")} />
        <Button label={t("myApplications")} variant="tertiary" onPress={() => router.push("/proposals")} />
      </Card>
      <Card>
        <Text style={styles.card}>{t("cmpNoRanking")}</Text>
        <Text style={styles.body}>{t("cmpNoRankingBody")}</Text>
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.sectionTitle, color: colors.navy950, marginTop: space.x3 },
  card: { ...type.cardTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
});
