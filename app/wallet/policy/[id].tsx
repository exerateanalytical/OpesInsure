import React from "react";
import { Redirect, useLocalSearchParams } from "expo-router";

/** Old wallet link for one policy: the one policy detail route. */
export default function WalletPolicyScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <Redirect href={{ pathname: "/policy/[id]", params: { id: String(id ?? "") } }} />;
}
