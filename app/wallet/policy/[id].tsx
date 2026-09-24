import React from "react";
import { useLocalSearchParams } from "expo-router";
import { PolicyDetailView } from "@/components/policies/PolicyDetailView";

/** Wallet entry for one policy; same view as /policy/[id]. */
export default function WalletPolicyScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <PolicyDetailView id={id} />;
}
