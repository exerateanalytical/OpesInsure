import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { LifeBuoy, Plus } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { SupportApi, SupportCase } from "@/api/client";
import { SupportContactList } from "@/components/auth/SupportContacts";
export default function Support() {
  const [x, setX] = useState<SupportCase[]>([]);
  useEffect(() => {
    SupportApi.list().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Help & complaints"
        subtitle="Secure assistance with a traceable case history"
        back
      />
      <Button
        label="Open a support case"
        icon={Plus}
        onPress={() => router.push("/support/new")}
      />
      <SupportContactList heading="Talk to us" />
      <Card>
        {x.map((c) => (
          <FlowRow
            key={c.id}
            icon={LifeBuoy}
            title={c.subject}
            subtitle={c.reference}
            status={c.status}
            onPress={() => router.push(`/support/${c.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}
