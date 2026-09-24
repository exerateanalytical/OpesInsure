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
import { useTranslation } from "@/i18n";
import { REGISTER_SOURCE_KEY } from "@/lib/institutions";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";

export default function InsurerDetail() {
  const { t } = useTranslation();
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
      <AppHeader title={t("insurerProfile")} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loadingInsurers")}>
        {(insurer) => (
          <>
            <Card feature>
              <View style={styles.between}>
                <View style={styles.logo}>
                  <Text style={styles.logoText}>{insurer.initials}</Text>
                </View>
                <View style={styles.badges}>
                  {insurer.branch ? (
                    <StatusChip
                      label={t(insurer.branch === "LIFE" ? "branchBadgeLIFE" : "branchBadgeIARD")}
                      tone={insurer.branch === "LIFE" ? "success" : "info"}
                    />
                  ) : null}
                  {insurer.is_official_register && insurer.licensed ? (
                    <StatusChip label={t("licensedStatus")} tone="success" />
                  ) : null}
                </View>
              </View>
              <Text style={styles.title}>{insurer.short_name ?? insurer.name}</Text>
              {insurer.legal_name ? <Text style={styles.body}>{insurer.legal_name}</Text> : null}
              {insurer.city || insurer.phone ? (
                <Text style={styles.body}>
                  {[insurer.city, insurer.phone].filter(Boolean).join(" · ")}
                </Text>
              ) : null}
              {insurer.canonical_id ? (
                <Text style={styles.canonical}>{t("canonicalId", { id: insurer.canonical_id })}</Text>
              ) : null}
              {insurer.phone ? (
                <Button
                  label={t("callCompany")}
                  icon={Phone}
                  variant="secondary"
                  onPress={() => void Linking.openURL(`tel:${insurer.phone}`)}
                />
              ) : null}
              {insurer.website ? (
                <Button
                  label={t("openWebsite")}
                  icon={ExternalLink}
                  variant="tertiary"
                  onPress={() => void Linking.openURL(insurer.website!)}
                />
              ) : null}
            </Card>

            {insurer.product_families?.length ? (
              <>
                <SectionTitle title={t("publishedFamiliesUnverified")} />
                <Card>
                  <View style={styles.families}>
                    {insurer.product_families.map((f) => (
                      <Text key={f} style={styles.family}>{f}</Text>
                    ))}
                  </View>
                </Card>
              </>
            ) : null}

            <SectionTitle title={t("productsOnOpesInsure")} />
            {insurer.products?.length ? (
              insurer.products.map((p) => (
                <Card key={p.id}>
                  <View style={styles.between}>
                    <Text style={styles.offer}>{p.name}</Text>
                    <StatusChip label={p.line_code} tone="info" />
                  </View>
                  <Button label={t("compareThisProduct")} onPress={() => compare(p.line_code)} />
                </Card>
              ))
            ) : (
              <Card>
                <Text style={styles.offer}>{t("noPlatformProducts")}</Text>
                <Text style={styles.body}>{t("noPlatformProductsBody")}</Text>
              </Card>
            )}
            {insurer.is_official_register ? (
              <Text style={styles.source}>{t(REGISTER_SOURCE_KEY)}</Text>
            ) : null}
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
  badges: { flexDirection: "row", gap: space.x2, flexWrap: "wrap", justifyContent: "flex-end", flex: 1 },
  title: { ...type.pageTitle, color: colors.navy950 },
  offer: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  body: { ...type.body, color: colors.neutral600 },
  canonical: { ...type.meta, color: colors.neutral500, fontVariant: ["tabular-nums"] },
  families: { flexDirection: "row", flexWrap: "wrap", gap: 6 },
  family: {
    ...type.meta,
    color: colors.neutral700,
    backgroundColor: colors.neutral100,
    borderRadius: radius.pill,
    paddingHorizontal: space.x2,
    paddingVertical: 2,
  },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
  between: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "flex-start",
    gap: space.x3,
  },
});
