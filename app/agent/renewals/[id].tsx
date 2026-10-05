import React from "react";
import { RenewalDeskScreen } from "@/components/renewals/RenewalDeskScreen";

/** Agent renewal (id = policy id): re-quote, send the renewal offer, record the client's decision. */
export default function AgentRenewalDetail() {
  return <RenewalDeskScreen portal="agent" />;
}
