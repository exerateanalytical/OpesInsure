import React from "react";
import { Linking, StyleSheet, Text, View } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { Phone } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi } from "@/api/extra";
import { formatDisplayDate, useTranslation } from "@/i18n";
import { REGISTER_SOURCE_KEY } from "@/lib/institutions";
import { colors, space, type } from "@/theme/tokens";

export default function BrokerDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => InstitutionsApi.show(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("brokerProfile")} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loadingBrokers")}>
        {(broker) => (
          <>
            <Card feature>
              <Text style={styles.title}>{broker.name}</Text>
              <View style={styles.badges}>
                {broker.licensed ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
                {broker.regulator_number ? (
                  <StatusChip label={t("regulatorNumber", { number: broker.regulator_number })} tone="info" />
                ) : null}
                {broker.licence_number ? (
                  <StatusChip label={`Licence ${broker.licence_number}`} tone="info" />
                ) : null}
              </View>
              {broker.city ? <Text style={styles.body}>{broker.city}, Cameroon</Text> : null}
              {broker.canonical_id ? (
                <Text style={styles.canonical}>{t("canonicalId", { id: broker.canonical_id })}</Text>
              ) : null}
              {broker.licence_expires_on ? (
                <Text style={styles.body}>
                  {formatDisplayDate(broker.licence_expires_on)}
                </Text>
              ) : null}
              {broker.phone ? (
                <Button
                  label={broker.phone}
                  icon={Phone}
                  variant="secondary"
                  onPress={() => void Linking.openURL(`tel:${broker.phone}`)}
                />
              ) : null}
            </Card>
            <Text style={styles.source}>{t("brokerVerifyNote")}</Text>
            {broker.is_official_register ? (
              <Text style={styles.source}>{t(REGISTER_SOURCE_KEY)}</Text>
            ) : null}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.pageTitle, color: colors.navy950 },
  badges: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  body: { ...type.body, color: colors.neutral600 },
  canonical: { ...type.meta, color: colors.neutral500, fontVariant: ["tabular-nums"] },
  source: { ...type.meta, color: colors.neutral500 },
});
