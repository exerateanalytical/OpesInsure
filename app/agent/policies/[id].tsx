import React from "react";
import { useLocalSearchParams } from "expo-router";
import { AgentWorkspaceApi } from "@/api/partner";
import { PartnerPolicyDetail } from "@/components/partner/PartnerPolicyDetail";

export default function AgentPolicyDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return (
    <PartnerPolicyDetail
      id={id}
      base="/agent"
      loadPolicies={AgentWorkspaceApi.policies}
      loadClaims={AgentWorkspaceApi.claims}
      loadDocuments={AgentWorkspaceApi.clientDocuments}
      canAssist
      variant="agent"
    />
  );
}
