import React from "react";
import { AssistedSaleNew } from "@/components/sales/AssistedSaleNew";

/** Assisted sale, step 1 (broker portal): client, product, the client's real risk answers, payment phone. */
export default function BrokerSaleNew() {
  return <AssistedSaleNew portal="broker" />;
}
