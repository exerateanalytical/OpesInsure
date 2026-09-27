import React from "react";
import { useLocalSearchParams } from "expo-router";
import { AgentWorkspaceApi } from "@/api/partner";
import { PartnerClaimDetail } from "@/components/partner/PartnerClaimDetail";

export default function AgentClaimDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <PartnerClaimDetail id={id} base="/agent" loadClaims={AgentWorkspaceApi.claims} />;
}
