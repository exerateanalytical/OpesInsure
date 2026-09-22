import React, { useEffect, useState } from "react";
import { router } from "expo-router";
import { Bell } from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { FlowRow } from "@/components/FlowPrimitives";
import { CustomerNotification, NotificationsApi } from "@/api/client";
export default function Notifications() {
  const [x, setX] = useState<CustomerNotification[]>([]);
  useEffect(() => {
    NotificationsApi.list().then(setX);
  }, []);
  return (
    <Screen>
      <AppHeader
        title="Notifications"
        subtitle={`${x.filter((n) => !n.read).length} unread`}
        back
      />
      <Button
        label="Mark all as read"
        variant="secondary"
        onPress={async () => {
          await NotificationsApi.markAllRead();
          setX(x.map((n) => ({ ...n, read: true })));
        }}
      />
      <Card>
        {x.map((n) => (
          <FlowRow
            key={n.id}
            icon={Bell}
            title={`${n.read ? "" : "• "}${n.title}`}
            subtitle={n.body}
            status={n.severity}
            onPress={() => router.push(`/notifications/${n.id}`)}
          />
        ))}
      </Card>
    </Screen>
  );
}
