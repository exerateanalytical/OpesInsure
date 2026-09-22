import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { ClaimInspection, ClaimsCompletionApi } from "@/api/client";
export default function Inspection() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<ClaimInspection>();
  const [date, setDate] = useState("");
  useEffect(() => {
    ClaimsCompletionApi.inspection(id).then((v) => {
      setX(v);
      setDate(v?.appointment_at ?? "");
    });
  }, [id]);
  return (
    <Screen>
      <AppHeader
        title="Vehicle inspection"
        subtitle="Surveyor appointment and assessment"
        back
      />
      <Card>
        <StatusChip label={x?.status ?? "NOT SCHEDULED"} tone="info" />
        <Text>{x?.appointment_at}</Text>
        <Text>{x?.location}</Text>
        <Text>
          {x?.surveyor_name} · {x?.contact_phone}
        </Text>
        <Text>{x?.notes}</Text>
      </Card>
      {x ? (
        <Card>
          <TextField
            label="Request another appointment time"
            value={date}
            onChangeText={setDate}
          />
          <Button
            label="Request reschedule"
            variant="secondary"
            onPress={async () =>
              setX(await ClaimsCompletionApi.rescheduleInspection(id, date))
            }
          />
        </Card>
      ) : null}
      <Button
        label="View repair assessment"
        onPress={() => router.push(`/claim/${id}/repair`)}
      />
    </Screen>
  );
}
