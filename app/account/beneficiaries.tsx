import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { Info, UsersRound } from "lucide-react-native";
import { WalletApi } from "@/api/client";
import { Screen } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading } from "@/components/design";
import { BeneficiariesSection } from "@/components/policies/BeneficiariesSection";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { useTranslation } from "@/i18n";
import { colors, space, type } from "@/theme/tokens";

/**
 * Beneficiaries across every policy the customer owns (GET /mobile/wallet),
 * each edited through GET/PUT /policies/{id}/beneficiaries (REQ-CRM-004).
 * Policies whose product has no beneficiaries answer 403/404 and the
 * section hides itself. The backend has no customer dependants endpoint,
 * so the design's "Dependants" list is not shown.
 */
export default function Beneficiaries() {
  const { t } = useTranslation();
  const q = useLoad(() => WalletApi.all(), []);
  const policies = (q.data ?? []).filter((p) => !["CANCELLED", "EXPIRED", "LAPSED"].includes(String(p.status).toUpperCase()));
  return (
    <Screen>
      <BrandHeader title={t("benPageTitle")} subtitle={t("benPageSubtitle")} back />
      {q.loading && !q.data ? <LoadingState /> : null}
      {q.error && !q.data ? <ErrorState error={q.error} onRetry={q.reload} /> : null}
      {q.data && !policies.length ? (
        <EmptyState title={t("benPageEmpty")} message={t("benPageEmptyBody")} action={t("explore")} onPress={() => router.push("/(customer)/(tabs)/explore" as never)} />
      ) : null}
      {policies.map((p) => (
        <View key={p.id} style={styles.block}>
          <SectionHeading title={p.product_name ?? p.policy_number} icon={UsersRound} />
          <Text style={styles.meta}>
            {p.policy_number}
            {p.carrier_name ? ` · ${p.carrier_name}` : ""}
          </Text>
          <BeneficiariesSection policyId={p.id} hideTitle />
        </View>
      ))}
      {policies.length ? <Banner icon={Info} tint="gold" title={t("benAllocationTitle")} body={t("benAllocationBody")} /> : null}
    </Screen>
  );
}
const styles = StyleSheet.create({
  block: { gap: space.x2 },
  meta: { ...type.meta, color: colors.neutral600 },
});
