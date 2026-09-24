import React, { useState } from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import { Alert, Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import {
  AppHeader,
  Button,
  Card,
  Money,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { CarrierApi, CarrierReferral } from "@/api/client";
export default function ReferralDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => CarrierApi.referral(id), [id]);
  const x: CarrierReferral | undefined = q.data;
  const setX = q.setData;
  const [note, setNote] = useState("");
  const decide = (d: "APPROVE" | "DECLINE" | "MORE_INFORMATION") =>
    Alert.alert(
      "Record underwriting decision?",
      `Decision: ${d.replaceAll("_", " ")}`,
      [
        { text: "Cancel", style: "cancel" },
        {
          text: "Confirm",
          onPress: async () => {
            try {
              setX(await CarrierApi.decideReferral(id, d, note));
            } catch {
              Alert.alert("Decision not recorded", "Check the connection and try again.");
            }
          },
        },
      ],
    );
  return (
    <Screen>
      <AppHeader title="Referral review" subtitle={x?.quote_id} back />
      {!x ? (
        <StatePanel {...q} onRetry={q.reload} loadingLabel="Loading referral…">
          {() => null}
        </StatePanel>
      ) : null}
      {x ? (
      <Card feature>
        <StatusChip label={x?.status ?? "LOADING"} tone="warning" />
        <Text>
          {x?.customer_name} · {x?.product}
        </Text>
        {x ? <Money amount={x.premium_minor / 100} /> : null}
        <Text>{x?.reason}</Text>
        <TextField
          label="Underwriting note"
          multiline
          value={note}
          onChangeText={setNote}
        />
      </Card>
      ) : null}
      {x?.status === "PENDING_REVIEW" ? (
        <>
          <Button
            label="Approve within authority"
            disabled={note.length < 5}
            onPress={() => decide("APPROVE")}
          />
          <Button
            label="Request more information"
            variant="secondary"
            disabled={note.length < 5}
            onPress={() => decide("MORE_INFORMATION")}
          />
          <Button
            label="Decline with reason"
            variant="danger"
            disabled={note.length < 5}
            onPress={() => decide("DECLINE")}
          />
        </>
      ) : null}
    </Screen>
  );
}
