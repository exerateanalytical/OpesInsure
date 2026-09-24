import React, { useMemo, useState } from "react";
import { StyleSheet, Text } from "react-native";
import { router } from "expo-router";
import { AppHeader, Button, Screen, TextField } from "@/components/ui";
import { InsurerCard } from "@/components/InsuranceCards";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi } from "@/api/extra";
import { colors, type } from "@/theme/tokens";

export default function Insurers() {
  const [query, setQuery] = useState("");
  const q = useLoad(() => InstitutionsApi.list("insurer"), []);
  const filtered = useMemo(
    () =>
      (q.data ?? []).filter((i) =>
        `${i.name} ${i.city ?? ""}`.toLowerCase().includes(query.toLowerCase()),
      ),
    [q.data, query],
  );
  return (
    <Screen>
      <AppHeader
        title="Insurance companies"
        subtitle={q.data ? `${q.data.length} insurers on OpesInsure` : undefined}
        back
      />
      <Button
        label="Browse insurance brokers"
        variant="secondary"
        onPress={() => router.push("/institutions/brokers")}
      />
      <TextField
        label="Search companies"
        value={query}
        onChangeText={setQuery}
        placeholder="Name or city"
      />
      <Text style={styles.note}>
        Insurers selling through OpesInsure. Products listed are the ones you
        can compare and buy in the app.
      </Text>
      <StatePanel
        {...q}
        onRetry={q.reload}
        emptyTitle="No insurers yet"
        emptyMessage="Insurance companies will appear here once they are onboarded."
      >
        {() => (
          <>
            {filtered.map((i) => (
              <InsurerCard
                key={i.id}
                insurer={i}
                onPress={() =>
                  router.push({
                    pathname: "/institutions/insurer/[id]",
                    params: { id: i.id },
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
