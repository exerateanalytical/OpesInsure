import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Screen } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { AgentWorkspaceApi } from "@/api/partner";
import { AssistedClaimForm, type ClaimablePolicy } from "@/components/partner/AssistedClaimForm";
import { useTranslation } from "@/i18n";

/** AGT-001: agent-assisted FNOL on a book policy. The insurer adjudicates; the agent only files. */
export default function AgentAssistClaim() {
  const { t } = useTranslation();
  const { policyId } = useLocalSearchParams<{ policyId: string }>();
  const q = useLoad(async () => {
    const p = (await AgentWorkspaceApi.policies()).find((x) => x.id === policyId);
    return p?.party_id ? [{ id: p.id, policy_number: p.policy_number, customer_name: p.customer_name, party_id: p.party_id } as ClaimablePolicy] : [];
  }, [policyId]);
  return (
    <Screen>
      <AppHeader title={t("pdAssistClaim")} subtitle={t("pdAssistClaimSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {(policies) => (
          <AssistedClaimForm
            policies={policies}
            initialPolicyId={policyId}
            submit={AgentWorkspaceApi.reportClaim}
            onFiled={(id) => router.replace({ pathname: "/agent/claims/[id]", params: { id } })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
