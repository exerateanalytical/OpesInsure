import React from "react";
import { Slot } from "expo-router";

/**
 * The customer group is guarded once, in app/_layout.tsx
 * (`<Stack.Protected guard={customer}>` registers "(customer)"), like every
 * other customer route. A second redirect here used to disagree with it
 * (access-denied vs. back to index), so this layout only renders its slot.
 */
export default function CustomerLayout() {
  return <Slot />;
}
