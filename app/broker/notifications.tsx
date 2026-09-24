import React from "react";
import { PortalNotifications } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
export default function BrokerNotifications() {
  return <PortalNotifications tabs={brokerTabs} />;
}
