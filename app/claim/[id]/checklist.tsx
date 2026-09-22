import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Camera } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { ClaimsCompletionApi, EvidenceRequirement } from "@/api/client";
export default function Checklist() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [x, setX] = useState<EvidenceRequirement[]>([]);
  useEffect(() => {
    ClaimsCompletionApi.evidenceRequirements(id).then(setX);
  }, [id]);
  return (
    <Screen>
      <AppHeader
        title="Evidence checklist"
        subtitle="Required items are defined by the insurer"
        back
      />
      <Card>
        {x.map((e) => (
          <FlowRow
            key={e.key}
            icon={Camera}
            title={`${e.required ? "Required: " : ""}${e.label}`}
            subtitle={e.guidance}
            status={e.status}
            onPress={() => router.push(`/claim/${id}/evidence`)}
          />
        ))}
      </Card>
      <Button
        label="Open inspection and repair tracking"
        onPress={() => router.push(`/claim/${id}/inspection`)}
      />
    </Screen>
  );
}
