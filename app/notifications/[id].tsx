import React, { useEffect, useState } from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Text } from "react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { CustomerNotification, NotificationsApi } from "@/api/client";
export default function NotificationDetail() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const [n, setN] = useState<CustomerNotification>();
  useEffect(() => {
    NotificationsApi.markRead(id).then(setN);
  }, [id]);
  return (
    <Screen>
      <AppHeader title="Notification" back />
      <Card feature>
        <StatusChip
          label={n?.severity ?? "INFO"}
          tone={
            n?.severity === "WARNING"
              ? "warning"
              : n?.severity === "CRITICAL"
                ? "danger"
                : "info"
          }
        />
        <Text>{n?.title}</Text>
        <Text>{n?.body}</Text>
        <Text>{n?.created_at}</Text>
      </Card>
      {n?.path ? (
        <Button
          label="Open related item"
          onPress={() => router.push(n.path as any)}
        />
      ) : null}
    </Screen>
  );
}
