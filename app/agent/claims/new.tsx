import React from "react";
import { StyleSheet, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ShieldAlert } from "lucide-react-native";
import { useLoad } from "@/hooks/useLoad";
import { AgentEmptyState, AgentShell, AgentSkeleton } from "@/components/agent";
import { AgentWorkspaceApi } from "@/api/partner";
import { AssistedClaimForm, type ClaimablePolicy } from "@/components/partner/AssistedClaimForm";
import { useTranslation } from "@/i18n";
import { agentColors as c, agentType as T } from "@/theme/agent";

/** AGT-001: agent-assisted FNOL on a book policy. The insurer adjudicates; the agent only files. */
export default function AgentAssistClaim() {
  const { t } = useTranslation();
  const { policyId } = useLocalSearchParams<{ policyId: string }>();
  const q = useLoad(async () => {
    const p = (await AgentWorkspaceApi.policies()).find((x) => x.id === policyId);
    return p?.party_id ? [{ id: p.id, policy_number: p.policy_number, customer_name: p.customer_name, party_id: p.party_id } as ClaimablePolicy] : [];
  }, [policyId]);
  const title = t("pdAssistClaim");
  if (!q.data) {
    return (
      <AgentShell variant="drilldown" title={title} hideNav>
        {q.error ? (
          <AgentEmptyState icon={ShieldAlert} title={t("loadErrorTitle")} body={t("loadErrorBody")} actionLabel={t("retry")} onAction={q.reload} />
        ) : (
          <AgentSkeleton rows={4} height={56} />
        )}
      </AgentShell>
    );
  }
  return (
    <AssistedClaimForm
      variant="agent"
      policies={q.data}
      initialPolicyId={policyId}
      submit={AgentWorkspaceApi.reportClaim}
      onFiled={(id) => router.replace({ pathname: "/agent/claims/[id]", params: { id } })}
      shell={(body, footer) => (
        <AgentShell variant="drilldown" title={title} hideNav footer={footer}>
          <Text style={s.sub}>{t("pdAssistClaimSubtitle")}</Text>
          {body}
        </AgentShell>
      )}
    />
  );
}

const s = StyleSheet.create({ sub: { ...T.secondary, color: c.secondary } });
