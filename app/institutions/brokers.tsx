import React, { useMemo, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { AppHeader, Screen, TextField } from "@/components/ui";
import { BrokerCard } from "@/components/InsuranceCards";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi } from "@/api/extra";
import { colors, type } from "@/theme/tokens";

export default function Brokers() {
  const [query, setQuery] = useState("");
  const q = useLoad(() => InstitutionsApi.list("broker"), []);
  const filtered = useMemo(
    () =>
      (q.data ?? []).filter((b) =>
        `${b.name} ${b.city ?? ""}`.toLowerCase().includes(query.toLowerCase()),
      ),
    [q.data, query],
  );
  return (
    <Screen>
      <AppHeader
        title="Insurance brokers"
        subtitle={q.data ? `${q.data.length} brokers on OpesInsure` : undefined}
        back
      />
      <TextField
        label="Search broker directory"
        value={query}
        onChangeText={setQuery}
        placeholder="Broker name or city"
      />
      <Text style={styles.note}>
        Brokers with an active partnership on OpesInsure. Always confirm a
        broker&apos;s current licence with MINFI before transacting.
      </Text>
      <StatePanel
        {...q}
        onRetry={q.reload}
        emptyTitle="No brokers yet"
        emptyMessage="Brokers will appear here once they are onboarded."
      >
        {() => (
          <>
            {filtered.map((b) => (
              <BrokerCard
                key={b.id}
                broker={b}
                onPress={() =>
                  router.push({
                    pathname: "/institutions/broker/[id]",
                    params: { id: b.id },
                  })
                }
              />
            ))}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  note: { ...type.meta, color: colors.neutral600 },
});
