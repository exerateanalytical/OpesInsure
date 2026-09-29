import React from "react";
import { Redirect, useLocalSearchParams } from "expo-router";

/** Kept for old links: one service-request screen (app/services/new.tsx) with this policy preselected. */
export default function Service() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <Redirect href={{ pathname: "/services/new", params: { policyId: id } }} />;
}
