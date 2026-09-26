import React, { useEffect, useMemo, useState } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Info, Siren } from "lucide-react-native";
import { Button, Screen } from "@/components/ui";
import { Banner, BrandHeader, CtaBar } from "@/components/design";
import { SearchBar } from "@/components/SearchBar";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { ClaimWizardSteps } from "@/components/claims/ClaimWizardSteps";
import { PolicyChoiceCard } from "@/components/claims/PolicyChoiceCard";
import { insuredLabel, policyTitle, providerName } from "@/components/claims/claimProduct";
import { usePolicies } from "@/hooks/usePolicies";
import { useInsurerLogo } from "@/components/claims/insurerLogo";
import { useTranslation } from "@/i18n";
import { matchesQuery } from "@/lib/customerLogic";
import { colors, type } from "@/theme/tokens";

/**
 * New claim, step 1 of 4 (design 33): choose the ACTIVE policy the loss
 * relates to. The policy id is handed to step 2, which renders the rest of
 * the server form claim_fnol (GET /forms/claim_fnol → POST /mobile/claims).
 */
export default function NewClaim() {
  const { t } = useTranslation();
  const { policyId } = useLocalSearchParams<{ policyId?: string }>();
  const { policies, loading, error, reload } = usePolicies();
  const logoFor = useInsurerLogo();
  const [query, setQuery] = useState("");
  const [selected, setSelected] = useState<string | null>(typeof policyId === "string" && policyId ? policyId : null);

  const active = useMemo(() => policies.filter((p) => !p.status || p.status.toUpperCase() === "ACTIVE"), [policies]);
  const shown = active.filter((p) => matchesQuery(query, policyTitle(p, ""), p.policy_number, providerName(p), insuredLabel(p)));
  useEffect(() => {
    if (!selected && active.length === 1) setSelected(active[0]!.id);
  }, [active, selected]);

  return (
    <Screen
      footer={
        <CtaBar>
          <Button
            label={t("continue")}
            icon={ArrowRight}
            disabled={!selected || !active.some((p) => p.id === selected)}
            onPress={() => router.push({ pathname: "/claim/new/incident" as never, params: { policyId: selected ?? "" } })}
          />
        </CtaBar>
      }
    >
      <BrandHeader title={t("claimSelectPolicyTitle")} subtitle={t("claimSelectPolicySubtitle")} right="help" />
      <ClaimWizardSteps current={0} />
      <Banner icon={Siren} tint="red" title={t("emergencyTitle")} body={t("emergencyAssistance")} onPress={() => router.push("/claim/emergency")} />
      <SearchBar value={query} onChangeText={setQuery} placeholder={t("claimSearchPolicies")} label={t("claimPolicySearchLabel")} clearLabel={t("clearSearch")} />
      {loading && !policies.length ? (
        <LoadingState label={t("claimLoadingPolicies")} />
      ) : error && !policies.length ? (
        <ErrorState error={error} onRetry={() => void reload()} />
      ) : !active.length ? (
        <EmptyState title={t("claimNoActivePolicy")} message={t("claimNoActivePolicyBody")} />
      ) : (
        <View style={s.list} accessibilityRole="radiogroup">
          {shown.map((p) => (
            <PolicyChoiceCard key={p.id} policy={p} logoUrl={logoFor(p)} selected={selected === p.id} onPress={() => setSelected(p.id)} />
          ))}
          {!shown.length ? <Text style={s.note}>{t("claimNoPolicyMatch")}</Text> : null}
        </View>
      )}
      <Banner icon={Info} tint="blue" title={t("claimOnlyActiveTitle")} body={t("claimOnlyActiveBody")} />
    </Screen>
  );
}
const s = StyleSheet.create({
  list: { gap: 12 },
  note: { ...type.body, color: colors.neutral600, textAlign: "center" },
});
