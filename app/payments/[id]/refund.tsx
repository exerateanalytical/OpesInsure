import React, { useState } from "react";
import { Text } from "react-native";
import { useLocalSearchParams } from "expo-router";
import { AppHeader, Button, Card, Screen, TextField } from "@/components/ui";
import { PaymentsApi } from "@/api/client";
import { handleStepUpRequired } from "@/security/step-up";
export default function Refund() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [reason, setReason] = useState("");
  const [status, setStatus] = useState("");
  return (
    <Screen>
      <AppHeader
        title="Refund review"
        subtitle="Submitting does not cancel active cover automatically"
        back
      />
      <Card>
        <TextField
          label="Reason for request"
          multiline
          value={reason}
          onChangeText={setReason}
        />
        <Button
          label="Submit for review"
          disabled={reason.trim().length < 10}
          onPress={async () => {
            try {
              setStatus((await PaymentsApi.refund(id, reason)).status);
            } catch (error) {
              if (!handleStepUpRequired(error, "PAYMENT_REFUND_REQUEST", `/payments/${id}/refund`)) throw error;
            }
          }}
        />
        {status ? <Text>Request status: {status}</Text> : null}
      </Card>
    </Screen>
  );
}
