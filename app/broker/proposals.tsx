import React from "react";
import { brokerTabs } from "@/components/portal/tabs";
import { BrokerWorkspaceApi } from "@/api/partner";
import { PartnerProposalsScreen } from "@/components/partner/PartnerProposalsScreen";

export default function BrokerProposals() {
  return <PartnerProposalsScreen portal="broker" tabs={brokerTabs} load={BrokerWorkspaceApi.proposals} />;
}
