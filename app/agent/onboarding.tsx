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
import { useTranslation } from "@/i18n";
export default function AgentOnboarding() {
  const { t } = useTranslation();
  const q = useLoad(() => AgentApi.profile(), []);
  const x = q.data;
  const setX = q.setData;
  if (!x)
    return (
      <Screen>
        <AppHeader title={t("agAgentVerification")} back />
        <StatePanel {...q} onRetry={q.reload} loadingLabel={t("agLoadingProfile")}>
          {() => null}
        </StatePanel>
      </Screen>
    );
  return (
    <Screen>
      <AppHeader title={t("agAgentVerification")} subtitle={x.agent_code} back />
      <Card>
        <StatusChip
          label={x.status}
          tone={x.status === "ACTIVE" ? "success" : "warning"}
        />
        <TextField
          label={t("agFullLegalName")}
          value={x.full_name}
          onChangeText={(full_name) => setX({ ...x, full_name })}
        />
        <TextField
          label={t("agNationalId")}
          value={x.national_id_number}
          onChangeText={(national_id_number) =>
            setX({ ...x, national_id_number })
          }
        />
        <TextField
          label={t("agCommissionMomo")}
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
          label={t("agSaveVerification")}
          onPress={async () => setX(await AgentApi.submitProfile(x))}
        />
      </Card>
    </Screen>
  );
}
