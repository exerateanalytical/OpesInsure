import React, { ReactNode } from "react";
import { ScrollView, StyleSheet, Text, useWindowDimensions, View } from "react-native";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { colors, radius, space, type } from "@/theme/tokens";

export type CompareTableRow = { key: string; label: string; heading?: boolean; cells: { minor?: number | null; text?: string; best?: boolean }[] };

/**
 * Side-by-side offer table (first row is the header). Columns never shrink below a readable
 * 140dp; with many insurers it scrolls horizontally instead of squeezing text.
 */
export function CompareTable({ rows, columns, money, footer }: { rows: CompareTableRow[]; columns: number; money: (minor: number) => string; footer?: (width: number, labelWidth: number) => ReactNode }) {
  const { width } = useWindowDimensions();
  const labelWidth = 120;
  const colWidth = Math.max(140, Math.min(200, (width - 40 - labelWidth) / Math.max(columns, 1)));
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator>
      <View style={st.table}>
        {rows.map((row, r) =>
          row.heading ? (
            <View key={row.key} style={[st.row, st.section]}>
              <Text style={[st.label, { width: labelWidth + colWidth * columns }]}>{row.label}</Text>
            </View>
          ) : (
            <View key={row.key} style={[st.row, r % 2 === 1 && st.zebra, r === 0 && st.head]}>
              <Text style={[st.label, { width: labelWidth }]}>{row.label}</Text>
              {row.cells.map((cell, i) => {
                const hasMoney = cell.minor !== undefined && cell.minor !== null;
                return (
                  <View key={`${row.key}-${i}`} style={[st.cell, { width: colWidth }, cell.best && st.best]}>
                    {hasMoney ? <Text style={[st.value, cell.best && st.bestText]}>{money(cell.minor as number)}</Text> : null}
                    {cell.text ? <Text style={hasMoney ? ps.meta : st.value}>{cell.text}</Text> : null}
                  </View>
                );
              })}
            </View>
          ),
        )}
        {footer ? footer(colWidth, labelWidth) : null}
      </View>
    </ScrollView>
  );
}

export const compareTableStyles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "stretch", borderBottomWidth: 1, borderBottomColor: colors.neutral100 },
  cell: { padding: space.x2, gap: 2, borderLeftWidth: 1, borderLeftColor: colors.neutral100 },
});

const st = StyleSheet.create({
  table: { borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, overflow: "hidden", backgroundColor: colors.white },
  row: compareTableStyles.row,
  zebra: { backgroundColor: colors.neutral50 },
  head: { backgroundColor: colors.blue50 },
  section: { backgroundColor: colors.neutral100 },
  label: { ...type.label, color: colors.navy950, padding: space.x2 },
  cell: compareTableStyles.cell,
  value: { ...type.meta, color: colors.navy950 },
  best: { backgroundColor: colors.successSoft },
  bestText: { color: colors.successText, fontFamily: "Inter_700Bold" },
});
