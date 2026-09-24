import React from "react";
import { StyleSheet, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Card, Screen, StatusChip } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi } from "@/api/extra";
import { colors, type } from "@/theme/tokens";

export default function BrokerDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => InstitutionsApi.show(id), [id]);
  return (
    <Screen>
      <AppHeader title="Broker profile" back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false}>
        {(broker) => (
          <>
            <Card feature>
              <Text style={styles.title}>{broker.name}</Text>
              {broker.city ? (
                <Text style={styles.body}>{broker.city}, Cameroon</Text>
              ) : null}
              {broker.licence_number ? (
                <StatusChip label={`Licence ${broker.licence_number}`} tone="info" />
              ) : null}
              {broker.licence_expires_on ? (
                <Text style={styles.body}>
                  Licence valid until{" "}
                  {new Date(broker.licence_expires_on).toLocaleDateString()}
                </Text>
              ) : null}
            </Card>
            <Text style={styles.source}>
              Verify a broker&apos;s current licence with MINFI before
              transacting.
            </Text>
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  title: { ...type.pageTitle, color: colors.navy950 },
  body: { ...type.body, color: colors.neutral600 },
  source: { ...type.meta, color: colors.neutral500 },
});
