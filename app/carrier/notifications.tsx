import React from "react";
import { PortalNotifications } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
export default function CarrierNotifications() {
  return <PortalNotifications tabs={carrierTabs} />;
}
