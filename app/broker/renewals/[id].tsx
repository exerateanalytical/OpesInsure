import React from "react";
import { RenewalDeskScreen } from "@/components/renewals/RenewalDeskScreen";

/** BRK-005 renewal work item (id = policy id): re-quote, send the renewal offer, record the client's decision. */
export default function BrokerRenewalDetail() {
  return <RenewalDeskScreen portal="broker" />;
}
