import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { useLoad } from "@/hooks/useLoad";
import { AppHeader, Screen } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { BrokerApi, type BrokerClient } from "@/api/client";
import { BrokerWorkspaceApi } from "@/api/partner";
import { AssistedClaimForm, type ClaimablePolicy } from "@/components/partner/AssistedClaimForm";
import { useTranslation } from "@/i18n";

type ClientDetail = BrokerClient & { policies_detail?: { id: string; policy_number: string | null; customer_name?: string; status: string }[] };
const MAX_CLIENTS = 30;

/**
 * Claimable policies: active policies of book clients with the client's party
 * id as claimant. Broker policy rows carry no party id, so the policyholder is
 * resolved through the client records (GET /mobile/broker/clients/{id}).
 */
async function claimable(customerId?: string, policyId?: string): Promise<ClaimablePolicy[]> {
  const ids = customerId ? [customerId] : (await BrokerApi.clients()).slice(0, MAX_CLIENTS).map((c) => c.id);
  const details = (await Promise.all(ids.map((id) => (BrokerApi.client(id) as Promise<ClientDetail>).catch(() => null)))).filter((c): c is ClientDetail => !!c?.party_id);
  const out = details.flatMap((c) =>
    (c.policies_detail ?? [])
      .filter((p) => p.status === "ACTIVE")
      .map((p) => ({ id: p.id, policy_number: p.policy_number, customer_name: p.customer_name ?? c.full_name, party_id: c.party_id! })),
  );
  return policyId && out.some((p) => p.id === policyId) ? out.filter((p) => p.id === policyId) : out;
}

/** Broker-assisted FNOL for a client in the book (POST /mobile/partner/broker/claims). The insurer adjudicates. */
export default function BrokerReportClaim() {
  const { t } = useTranslation();
  const { policyId, customerId } = useLocalSearchParams<{ policyId?: string; customerId?: string }>();
  const q = useLoad(() => claimable(customerId, policyId), [customerId, policyId]);
  return (
    <Screen>
      <AppHeader title={t("pdAssistClaim")} subtitle={t("pdAssistClaimSubtitle")} back />
      <StatePanel {...q} onRetry={q.reload}>
        {(policies) => (
          <AssistedClaimForm
            policies={policies}
            initialPolicyId={policyId}
            submit={BrokerWorkspaceApi.reportClaim}
            onFiled={(id) => router.replace({ pathname: "/broker/claims/[id]", params: { id } })}
          />
        )}
      </StatePanel>
    </Screen>
  );
}
