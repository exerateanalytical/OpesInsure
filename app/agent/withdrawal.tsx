import React, { useState } from "react";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { AgentApi, AgentWithdrawal } from "@/api/client";
export default function AgentWithdrawalScreen() {
  const [amount, setAmount] = useState("");
  const [phone, setPhone] = useState("+237690000002");
  const [result, setResult] = useState<AgentWithdrawal>();
  return (
    <Screen>
      <AppHeader
        title="Commission withdrawal"
        subtitle="Destination changes require separate account verification"
        back
      />
      <Card>
        <TextField
          label="Amount in FCFA"
          keyboardType="number-pad"
          value={amount}
          onChangeText={setAmount}
        />
        <TextField
          label="Verified MTN MoMo phone"
          keyboardType="phone-pad"
          value={phone}
          onChangeText={setPhone}
        />
        <Button
          label="Request withdrawal"
          disabled={Number(amount) <= 0 || phone.length < 8}
          onPress={async () =>
            setResult(
              await AgentApi.requestWithdrawal({
                amount_minor: Number(amount) * 100,
                provider: "mtn_momo",
                destination_phone: phone,
              }),
            )
          }
        />
        {result ? (
          <>
            <StatusChip label={result.status} tone="warning" />
            <Text>
              Withdrawal request {result.id} is awaiting server checks. Never
              pay a fee to unlock commission.
            </Text>
          </>
        ) : null}
      </Card>
    </Screen>
  );
}
