import React from "react";
import { Linking, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ExternalLink, Phone } from "lucide-react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  SectionTitle,
  StatusChip,
} from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi } from "@/api/extra";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";

export default function InsurerDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => InstitutionsApi.show(id), [id]);
  const status = useSession((s) => s.status);
  const setProduct = useInsurance((s) => s.setProduct);
  const compare = (lineCode: string) => {
    if (status !== "authenticated") {
      router.push("/(auth)/sign-in");
      return;
    }
    setProduct(lineCode.toLowerCase());
    router.push("/quote/risk");
  };
  return (
    <Screen>
      <AppHeader title="Company profile" back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false}>
        {(insurer) => (
          <>
            <Card feature>
              <View style={styles.logo}>
                <Text style={styles.logoText}>{insurer.initials}</Text>
              </View>
              <Text style={styles.title}>{insurer.name}</Text>
              {insurer.city || insurer.phone ? (
                <Text style={styles.body}>
                  {[insurer.city, insurer.phone].filter(Boolean).join(" · ")}
                </Text>
              ) : null}
              {insurer.phone ? (
                <Button
                  label="Call company"
                  icon={Phone}
                  variant="secondary"
                  onPress={() => void Linking.openURL(`tel:${insurer.phone}`)}
                />
              ) : null}
              {insurer.website ? (
                <Button
                  label="Open official website"
                  icon={ExternalLink}
                  variant="tertiary"
                  onPress={() => void Linking.openURL(insurer.website!)}
                />
              ) : null}
            </Card>
            <SectionTitle title="Products on OpesInsure" />
            {insurer.products?.length ? (
              insurer.products.map((p) => (
                <Card key={p.id}>
                  <View style={styles.between}>
                    <Text style={styles.offer}>{p.name}</Text>
                    <StatusChip label={p.line_code} tone="info" />
                  </View>
                  <Button
                    label="Compare this product"
                    onPress={() => compare(p.line_code)}
                  />
                </Card>
              ))
            ) : (
              <Card>
                <Text style={styles.offer}>No products available yet</Text>
                <Text style={styles.body}>
                  This company has not published a product on OpesInsure yet.
                </Text>
              </Card>
            )}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  logo: {
    width: 56,
    height: 56,
    borderRadius: radius.card,
    backgroundColor: colors.navy950,
    alignItems: "center",
    justifyContent: "center",
  },
  logoText: { ...type.label, color: colors.white },
  title: { ...type.pageTitle, color: colors.navy950 },
  offer: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  body: { ...type.body, color: colors.neutral600 },
  between: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "flex-start",
    gap: space.x3,
  },
});
