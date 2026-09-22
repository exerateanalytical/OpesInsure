import React, { useEffect, useState } from "react";
import { Text } from "react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { AgentApi, AgentProfile } from "@/api/client";
import { Step } from "@/components/FlowPrimitives";
export default function AgentOnboarding() {
  const [x, setX] = useState<AgentProfile>();
  useEffect(() => {
    AgentApi.profile().then(setX);
  }, []);
  if (!x)
    return (
      <Screen>
        <AppHeader title="Agent verification" back />
        <Text>Loading profile…</Text>
      </Screen>
    );
  return (
    <Screen>
      <AppHeader title="Agent verification" subtitle={x.agent_code} back />
      <Card>
        <StatusChip
          label={x.status}
          tone={x.status === "ACTIVE" ? "success" : "warning"}
        />
        <TextField
          label="Full legal name"
          value={x.full_name}
          onChangeText={(full_name) => setX({ ...x, full_name })}
        />
        <TextField
          label="National ID"
          value={x.national_id_number}
          onChangeText={(national_id_number) =>
            setX({ ...x, national_id_number })
          }
        />
        <TextField
          label="Commission MoMo phone"
          value={x.momo_phone_e164}
          onChangeText={(momo_phone_e164) => setX({ ...x, momo_phone_e164 })}
        />
        {x.compliance_items.map((i) => (
          <Step
            key={i.label}
            label={i.label}
            complete={i.status === "COMPLETE"}
          />
        ))}
        <Button
          label="Save verification profile"
          onPress={async () => setX(await AgentApi.submitProfile(x))}
        />
      </Card>
    </Screen>
  );
}
