import React from "react";
import { SellableCatalogueScreen } from "@/components/offers/SellableCatalogueScreen";

/** Broker "What I can sell": a sellable product starts the broker's assisted sale (/broker/sales/new?product=...). */
export default function BrokerCatalogue() {
  return <SellableCatalogueScreen portal="broker" />;
}
