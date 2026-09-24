import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { DisclosureApi } from "@/api/client";
export default function Terms() {
  const { proposalId = "" } = useLocalSearchParams<{
    proposalId?: string;
  }>();
  const [accepted, setAccepted] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  return (
    <Screen>
      <AppHeader
        title="Terms & declarations"
        subtitle="Review before payment"
        back
      />
      <Card>
        <Text>
          By continuing, you confirm that the information supplied is complete
          and accurate, and authorize its use to quote, issue and service this
          insurance contract.
        </Text>
        <Button
          label={accepted ? "Accepted" : "Accept declarations"}
          variant={accepted ? "secondary" : "primary"}
          onPress={() => setAccepted(true)}
        />
      </Card>
      <Button
        label="Continue to payment"
        disabled={!accepted || !proposalId}
        loading={busy}
        onPress={async () => {
          setBusy(true);
          setError(null);
          try {
            await DisclosureApi.acceptTerms(proposalId, true);
            router.push("/checkout");
          } catch {
            setError("Your acceptance could not be recorded. Try again.");
          } finally {
            setBusy(false);
          }
        }}
      />
      {error ? <Text accessibilityRole="alert">{error}</Text> : null}
    </Screen>
  );
}
