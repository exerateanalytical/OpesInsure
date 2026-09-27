import React from "react";
import { agentTabs } from "@/components/portal/tabs";
import { AgentWorkspaceApi } from "@/api/partner";
import { PartnerProposalsScreen } from "@/components/partner/PartnerProposalsScreen";

export default function AgentProposals() {
  return <PartnerProposalsScreen tabs={agentTabs} load={AgentWorkspaceApi.proposals} />;
}
