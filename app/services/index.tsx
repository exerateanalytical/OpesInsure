import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { FileCog, Plus } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { PolicyServiceCase, PolicyServicesApi } from "@/api/client";
export default function Services() {
  const [x, setX] = useState<PolicyServiceCase[]>([]);
  useEffect(() => {
    PolicyServicesApi.list().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Policy service requests"
        subtitle="Track every change from submission to completion"
        back
      />
      <Button
        label="New policy request"
        icon={Plus}
        onPress={() => router.push("/services/new")}
      />
      <Card>
        {x.map((s) => (
          <FlowRow
            key={s.id}
            icon={FileCog}
            title={s.type.replaceAll("_", " ")}
            subtitle={s.reason}
            status={s.status}
            onPress={() => router.push(`/services/${s.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}
