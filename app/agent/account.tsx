import React from "react";
import { PortalAccount } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
export default function AgentAccount() {
  return <PortalAccount tabs={agentTabs} />;
}
