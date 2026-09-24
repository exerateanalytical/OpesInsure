import React from "react";
import { useLoad } from "@/hooks/useLoad";
import { StatePanel } from "@/components/StatePanel";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  StatusChip,
  TextField,
} from "@/components/ui";
import { AgentApi } from "@/api/client";
import { Step } from "@/components/FlowPrimitives";
export default function AgentOnboarding() {
  const q = useLoad(() => AgentApi.profile(), []);
  const x = q.data;
  const setX = q.setData;
  if (!x)
    return (
      <Screen>
        <AppHeader title="Agent verification" back />
        <StatePanel {...q} onRetry={q.reload} loadingLabel="Loading profile…">
          {() => null}
        </StatePanel>
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
