import React from "react";
import { useLocalSearchParams } from "expo-router";
import { BrokerWorkspaceApi } from "@/api/partner";
import { PartnerClaimDetail } from "@/components/partner/PartnerClaimDetail";

/** BRK-004 broker claim detail: track and assist; adjudication stays insurer-only (server-enforced). */
export default function BrokerClaimDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <PartnerClaimDetail id={id} base="/broker" loadClaims={BrokerWorkspaceApi.claims} />;
}
