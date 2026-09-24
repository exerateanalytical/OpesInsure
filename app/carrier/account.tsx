import React from "react";
import { PortalAccount } from "@/components/portal/PortalShell";
import { carrierTabs } from "@/components/portal/tabs";
export default function CarrierAccount() {
  return <PortalAccount tabs={carrierTabs} />;
}
