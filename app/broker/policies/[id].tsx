import React from "react";
import { useLocalSearchParams } from "expo-router";
import { BrokerWorkspaceApi } from "@/api/partner";
import { PartnerPolicyDetail } from "@/components/partner/PartnerPolicyDetail";
import { loadBrokerLedger } from "@/components/partner/brokerLedger";
import { usePermission } from "@/components/carrier/CarrierGate";

/** BRK-003 broker policy detail (shared partner layout): commission for this sale and broker-assisted FNOL. */
export default function BrokerPolicyDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const canReportClaim = usePermission("broker.claims.file");
  return (
    <PartnerPolicyDetail
      id={id}
      base="/broker"
      loadPolicies={BrokerWorkspaceApi.policies}
      loadClaims={BrokerWorkspaceApi.claims}
      loadDocuments={BrokerWorkspaceApi.clientDocuments}
      loadCommissions={loadBrokerLedger}
      canReportClaim={canReportClaim}
    />
  );
}
