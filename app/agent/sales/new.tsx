import React from "react";
import { AssistedSaleNew } from "@/components/sales/AssistedSaleNew";

/** Assisted sale, step 1 (agent portal): client, product, the client's real risk answers, payment phone. */
export default function AgentSaleNew() {
  return <AssistedSaleNew portal="agent" />;
}
