import React from "react";
import { PortalNotifications } from "@/components/portal/PortalShell";
import { agentTabs } from "@/components/portal/tabs";
export default function AgentNotifications() {
  return <PortalNotifications tabs={agentTabs} />;
}
