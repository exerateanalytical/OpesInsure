import React from "react";
import {
  ScrollView,
  StyleSheet,
  Text,
  useWindowDimensions,
  View,
} from "react-native";
import { useLocalSearchParams } from "expo-router";
import { MoveHorizontal } from "lucide-react-native";
import { AppHeader, Card, Screen } from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { WorkspaceApi } from "@/api/client";
import { colors, space, type } from "@/theme/tokens";

const cellText = (v: unknown) =>
  v === null || v === undefined || v === "" ? "" : String(v);

export default function Module() {
  const { module } = useLocalSearchParams<{ module: string }>();
  const q = useLoad(() => WorkspaceApi.module(module), [module]);
  const { width } = useWindowDimensions();
  const narrow = width < 600;
  return (
    <Screen>
      <AppHeader
        title={q.data?.label ?? q.data?.title ?? "Workspace module"}
        back
      />
      <StatePanel
        {...q}
        onRetry={q.reload}
        isEmpty={(d) => d.rows.length === 0}
        emptyTitle="No records"
        emptyMessage="No authorised records were returned."
      >
        {(data) =>
          narrow ? (
            <>
              {data.rows.map((row, index) => (
                <Card key={index}>
                  {data.columns.map((column) => (
                    <View key={column} style={styles.pair}>
                      <Text style={styles.label}>{column}</Text>
                      <Text style={styles.value}>{cellText(row[column])}</Text>
                    </View>
                  ))}
                </Card>
              ))}
            </>
          ) : (
            <>
              <View style={styles.hint}>
                <MoveHorizontal size={16} color={colors.neutral600} />
                <Text style={styles.hintText}>Scroll sideways to see every column</Text>
              </View>
              <ScrollView horizontal showsHorizontalScrollIndicator>
                <View>
                  <View style={styles.row}>
                    {data.columns.map((column) => (
                      <Text key={column} style={[styles.cell, styles.header]}>
                        {column}
                      </Text>
                    ))}
                  </View>
                  {data.rows.map((row, index) => (
                    <View key={index} style={styles.row}>
                      {data.columns.map((column) => (
                        <Text key={column} style={styles.cell}>
                          {cellText(row[column])}
                        </Text>
                      ))}
                    </View>
                  ))}
                </View>
              </ScrollView>
            </>
          )
        }
      </StatePanel>
    </Screen>
  );
}
const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    borderBottomWidth: 1,
    borderBottomColor: colors.neutral200,
  },
  cell: { width: 150, padding: space.x3, ...type.meta, color: colors.neutral700 },
  header: {
    ...type.label,
    color: colors.navy950,
    backgroundColor: colors.neutral100,
  },
  pair: { gap: 2 },
  label: { ...type.caption, color: colors.neutral600 },
  value: { ...type.body, color: colors.navy950 },
  hint: { flexDirection: "row", alignItems: "center", gap: space.x2 },
  hintText: { ...type.meta, color: colors.neutral600 },
});
