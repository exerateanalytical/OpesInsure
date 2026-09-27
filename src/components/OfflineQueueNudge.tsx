import React, { useEffect } from "react";
import { View } from "react-native";
import { useRouter } from "expo-router";
import { CloudUpload, RefreshCw, TriangleAlert } from "lucide-react-native";
import { Banner } from "@/components/design";
import { Button } from "@/components/ui";
import { useTranslation } from "@/i18n";
import { useResilience } from "@/store/resilience";
import { MAX_QUEUE_OPERATIONS } from "@/offline/vault";
import { queueHealth } from "@/offline/queueCipher";

/** OPS-08: pending offline actions + "Sync now", shown well before the queue cap. */
export function OfflineQueueNudge() {
  const { t } = useTranslation();
  const router = useRouter();
  const { queue, online, syncing, hydrate, syncNow } = useResilience();
  useEffect(() => void hydrate(), [hydrate]);
  const health = queueHealth(queue.length, MAX_QUEUE_OPERATIONS);
  if (health.level === "empty") return null;
  const urgent = health.level !== "pending";
  return (
    <View style={{ gap: 8 }}>
      <Banner
        icon={urgent ? TriangleAlert : CloudUpload}
        tint={urgent ? "gold" : "blue"}
        title={`${queue.length} / ${MAX_QUEUE_OPERATIONS} ${t("pending")}`}
        body={urgent ? t("syncQueueNearCap") : t("syncQueuePendingBody")}
        onPress={() => router.push("/sync")}
      />
      <Button
        label={syncing ? t("syncing") : t("syncNow")}
        icon={RefreshCw}
        variant={urgent ? "primary" : "secondary"}
        loading={syncing}
        disabled={!online}
        onPress={() => void syncNow()}
      />
    </View>
  );
}
