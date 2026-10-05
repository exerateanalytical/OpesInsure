import React from "react";
import { AssistedSaleDetail } from "@/components/sales/AssistedSaleDetail";

/** Assisted sale detail (broker portal): server-driven next step, progress, payment and issuance. */
export default function BrokerSaleDetail() {
  return <AssistedSaleDetail portal="broker" />;
}
