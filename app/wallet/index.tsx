import React from "react";
import { Redirect } from "expo-router";

/**
 * The policy wallet was a second list of the same GET /mobile/wallet
 * policies as the Policies tab; old links (notifications, bookmarks) land
 * on the tab.
 */
export default function Wallet() {
  return <Redirect href="/(customer)/(tabs)/policies" />;
}
