import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Button, Screen } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { BrokerWorkspaceApi, type ClaimablePolicyRow } from "@/api/partner";
import { AssistedClaimForm } from "@/components/partner/AssistedClaimForm";
import { ClaimablePolicyPicker } from "@/components/partner/ClaimablePolicyPicker";
import { useTranslation } from "@/i18n";

/**
 * Broker-assisted FNOL for a client in the book (POST /mobile/partner/broker/claims). The insurer adjudicates.
 * The policy comes from the whole book through a server-side search (GET mobile/broker/claimable-policies);
 * `?policyId=` opens straight on that policy, `?customerId=` narrows the search to one client.
 */
export default function BrokerReportClaim() {
  const { t } = useTranslation();
  const { policyId, customerId } = useLocalSearchParams<{ policyId?: string; customerId?: string }>();
  const [picked, setPicked] = useState<ClaimablePolicyRow | null>(null);
  // A policy passed in the link is checked against the book (never trusted as-is).
  const linked = useLoad(async () => (policyId ? (await BrokerWorkspaceApi.claimablePolicies({ policy_id: policyId })).items[0] ?? null : null), [policyId]);
  const policy = picked ?? linked.data ?? null;
  const body = policy ? (
    <>
      <AssistedClaimForm
        key={policy.id}
        policies={[policy]}
        initialPolicyId={policy.id}
        submit={BrokerWorkspaceApi.reportClaim}
        onFiled={(id) => router.replace({ pathname: "/broker/claims/[id]", params: { id } })}
      />
      <Button label={t("clmChangePolicy")} variant="tertiary" onPress={() => { setPicked(null); linked.setData(null); }} />
    </>
  ) : (
    <ClaimablePolicyPicker customerId={customerId} onPick={setPicked} />
  );
  return (
    <Screen>
      <AppHeader title={t("pdAssistClaim")} subtitle={t("pdAssistClaimSubtitle")} back />
      {policyId && !picked && linked.data === undefined ? <StatePanel {...linked} onRetry={linked.reload}>{() => null}</StatePanel> : body}
    </Screen>
  );
}
