import React, { useEffect, useState } from "react";
import { useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { Step } from "@/components/FlowPrimitives";
import { PolicyServiceCase, PolicyServicesApi } from "@/api/client";
export default function ServiceDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<PolicyServiceCase>();
  const [m, setM] = useState("");
  useEffect(() => {
    PolicyServicesApi.show(id).then(setX);
  }, [id]);
  return (
    <Screen>
      <AppHeader
        title={x?.type.replaceAll("_", " ") ?? "Policy request"}
        back
      />
      <Card>
        <StatusChip label={x?.status ?? "LOADING"} tone="info" />
        <Text>{x?.reason}</Text>
        {x?.timeline.map((e) => (
          <Step
            key={e.id}
            label={`${e.label}${e.description ? ` — ${e.description}` : ""}`}
            complete
          />
        ))}
      </Card>
      <Card>
        <TextField
          label="Add information"
          multiline
          value={m}
          onChangeText={setM}
        />
        <Button
          label="Send message"
          disabled={!m.trim()}
          onPress={async () => {
            setX(await PolicyServicesApi.addMessage(id, m));
            setM("");
          }}
        />
      </Card>
    </Screen>
  );
}
