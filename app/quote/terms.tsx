import React, { useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { DisclosureApi } from "@/api/client";
export default function Terms() {
  const { proposalId = "proposal-001" } = useLocalSearchParams<{
    proposalId: string;
  }>();
  const [accepted, setAccepted] = useState(false);
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
        disabled={!accepted}
        onPress={async () => {
          await DisclosureApi.acceptTerms(proposalId, true);
          router.push("/checkout");
        }}
      />
    </Screen>
  );
}
