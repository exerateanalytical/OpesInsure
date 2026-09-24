import React from "react";
import { useLocalSearchParams } from "expo-router";
import { PolicyDetailView } from "@/components/policies/PolicyDetailView";

/** Policy detail — owned-policy wallet data (see PolicyDetailView). */
export default function Policy() {
  const { id } = useLocalSearchParams<{ id: string }>();
  return <PolicyDetailView id={id} />;
}
