import React from "react";
import { useLocalSearchParams } from "expo-router";
import { BrokerWorkspaceApi } from "@/api/partner";
import { PartnerPolicyDetail } from "@/components/partner/PartnerPolicyDetail";

/** BRK-003 broker policy detail (shared partner layout). No sales/FNOL assist routes exist for brokers yet. */
export default function BrokerPolicyDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return (
    <PartnerPolicyDetail
      id={id}
      base="/broker"
      loadPolicies={BrokerWorkspaceApi.policies}
      loadClaims={BrokerWorkspaceApi.claims}
      loadDocuments={BrokerWorkspaceApi.clientDocuments}
    />
  );
}
