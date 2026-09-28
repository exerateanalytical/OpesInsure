import React from "react";
import { SellableCatalogueScreen } from "@/components/offers/SellableCatalogueScreen";

/** "What I can sell" in the Commercial Agent look (the broker keeps the default). */
export default function AgentCatalogue() {
  return <SellableCatalogueScreen variant="agent" />;
}
