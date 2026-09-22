import React, { useEffect } from "react";
import { StyleSheet, Text, View } from "react-native";
import { Activity, RefreshCw } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { useRuntime } from "@/store/runtime";
import { environmentConfig } from "@/config/environment";
import { colors, space, type } from "@/theme/tokens";

export default function SystemStatus() {
  const runtime = useRuntime((s) => s.bootstrap);
  const check = useRuntime((s) => s.check);
  useEffect(() => void check(), [check]);
  return (
    <Screen>
      <AppHeader title="Service status" subtitle="Server-confirmed availability for connected services" back />
      <Card>
        <View style={styles.row}>
          <Activity size={24} color={colors.blue600} />
          <View style={styles.flex}>
            <Text style={styles.title}>OpesInsure mobile</Text>
            <Text style={styles.body}>Version {environmentConfig.appVersion} · {environmentConfig.releaseChannel}</Text>
          </View>
        </View>
      </Card>
      {runtime?.services.map((service) => (
        <Card key={service.key}>
          <View style={styles.row}>
            <View style={styles.flex}>
              <Text style={styles.title}>{service.key.replaceAll("_", " ")}</Text>
              {service.message ? <Text style={styles.body}>{service.message}</Text> : null}
            </View>
            <StatusChip label={service.status} tone={service.status === "OPERATIONAL" ? "success" : service.status === "DEGRADED" ? "warning" : "danger"} />
          </View>
        </Card>
      ))}
      <Button label="Refresh status" icon={RefreshCw} variant="secondary" onPress={() => void check()} />
      <Card>
        <Text style={styles.body}>A green provider status does not prove that a specific payment, policy, or claim succeeded. Always use the operation’s server-confirmed status.</Text>
      </Card>
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1 },
  title: { ...type.label, color: colors.navy950 },
  body: { ...type.meta, color: colors.neutral600 },
});
