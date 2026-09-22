import React, { useEffect, useState } from "react";
import { Alert, Text } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  StatusChip,
} from "@/components/ui";
import { ClaimSettlement, ClaimsCompletionApi } from "@/api/client";
import { handleStepUpRequired } from "@/security/step-up";
export default function Settlement() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<ClaimSettlement>();
  useEffect(() => {
    ClaimsCompletionApi.settlement(id).then(setX);
  }, [id]);
  if (!x)
    return (
      <Screen>
        <AppHeader title="Settlement" back />
        <Text>No settlement offer is currently available.</Text>
      </Screen>
    );
  const decide = (decision: "ACCEPT" | "REJECT") =>
    Alert.alert(
      decision === "ACCEPT"
        ? "Accept settlement?"
        : "Reject and request review?",
      decision === "ACCEPT"
        ? "Acceptance is recorded against the settlement terms shown."
        : "The claim remains open for review; no payment will be initiated.",
      [
        { text: "Cancel", style: "cancel" },
        {
          text: decision === "ACCEPT" ? "Accept" : "Reject",
          style: decision === "REJECT" ? "destructive" : "default",
          onPress: async () => {
            try {
              setX(await ClaimsCompletionApi.decideSettlement(id, decision));
            } catch (error) {
              if (!handleStepUpRequired(error, "CLAIM_SETTLEMENT_DECISION", `/claim/${id}/settlement`)) throw error;
            }
          },
        },
      ],
    );
  return (
    <Screen>
      <AppHeader
        title="Settlement offer"
        subtitle={`Decision due ${x.decision_deadline.slice(0, 10)}`}
        back
      />
      <Card feature>
        <StatusChip
          label={x.status}
          tone={x.status === "ACCEPTED" ? "success" : "warning"}
        />
        <Text>Assessed amount</Text>
        <Money amount={x.offered_minor / 100} />
        <Text>
          Deductible:{" "}
          {new Intl.NumberFormat("fr-CM").format(x.deductible_minor / 100)} FCFA
        </Text>
        <Text>Net settlement</Text>
        <Money amount={x.net_minor / 100} size="large" />
        <Text>{x.terms}</Text>
      </Card>
      {x.status === "OFFERED" ? (
        <>
          <Button label="Accept settlement" onPress={() => decide("ACCEPT")} />
          <Button
            label="Reject and request review"
            variant="secondary"
            onPress={() => decide("REJECT")}
          />
        </>
      ) : null}
      <Button
        label="Track settlement payment"
        variant="secondary"
        onPress={() => router.push(`/claim/${id}/settlement-payment`)}
      />
    </Screen>
  );
}
