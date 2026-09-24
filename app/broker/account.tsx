import React from "react";
import { PortalAccount } from "@/components/portal/PortalShell";
import { brokerTabs } from "@/components/portal/tabs";
export default function BrokerAccount() {
  return <PortalAccount tabs={brokerTabs} />;
}
