import React from "react";
import { AssistedSaleDetail } from "@/components/sales/AssistedSaleDetail";

/** Assisted sale detail (agent portal): server-driven next step, progress, payment and issuance. */
export default function AgentSaleDetail() {
  return <AssistedSaleDetail portal="agent" />;
}
