import React, { ReactNode, useRef, useState } from "react";
import { Animated, LayoutChangeEvent, Platform, StyleSheet, Text, View } from "react-native";
import { InstitutionMark } from "@/components/InstitutionMark";
import { initialsOf } from "@/components/filters/FilteredList";
import { StatusChip } from "@/components/ui";
import type { TableColumn, TableRow } from "@/lib/offerComparison";
import { colors, radius, space, type } from "@/theme/tokens";

/** Side by side needs this much per column (label column + offers); below it the table stacks. */
const LABEL_WIDTH = 112;
const MIN_SIDE_COL = 112;
/** Stacked layout: each offer column keeps at least this width; more offers scroll sideways. */
const MIN_STACK_COL = 92;

export type CompareHeader = TableColumn & { status?: { label: string; tone: "success" | "info" | "warning" | "danger" | "neutral" } | null };

/**
 * The offer comparison table. It measures the width it really gets (onLayout, inside the card
 * padding) and picks a layout:
 *  - side by side (label column + one column per offer) when every column gets MIN_SIDE_COL;
 *  - stacked otherwise (phones, 360 dp): each row's label spans the full width, the offers' values
 *    sit under it in equal columns, so labels are never cut off. With more offers than fit, the
 *    value columns scroll sideways while the labels stay in view.
 * `footer(i)` renders the one choose button under offer column i.
 */
export function CompareTable({ columns, rows, money, footer, headerLabel }: { columns: CompareHeader[]; rows: TableRow[]; money: (minor: number) => string; footer?: (index: number) => ReactNode; headerLabel: string }) {
  const [width, setWidth] = useState(0);
  const onLayout = (e: LayoutChangeEvent) => {
    const w = Math.floor(e.nativeEvent.layout.width);
    if (w && w !== width) setWidth(w);
  };
  const n = Math.max(columns.length, 1);
  const side = width >= LABEL_WIDTH + n * MIN_SIDE_COL;
  const stackCol = Math.max(MIN_STACK_COL, Math.floor(width / n));
  const scrolls = !side && stackCol * n > width;
  const colWidth = side ? Math.floor((width - LABEL_WIDTH) / n) : stackCol;
  // Labels and section titles move with the horizontal scroll offset, so they stay in view (sticky).
  const scrollX = useRef(new Animated.Value(0)).current;
  const sticky = scrolls ? { width, transform: [{ translateX: scrollX }] } : null;

  const header = (
    <View style={[st.values, st.head]}>
      {columns.map((c, i) => (
        <View key={c.offerId} style={[st.headCell, { width: colWidth }, i > 0 && st.divider]} accessibilityLabel={[c.name, c.product, c.status?.label].filter(Boolean).join(", ")}>
          <InstitutionMark logoUrl={c.logoUrl} initials={initialsOf(c.name)} size={40} />
          <Text style={st.headName} numberOfLines={3}>{c.name}</Text>
          {c.product ? <Text style={st.headProduct} numberOfLines={2}>{c.product}</Text> : null}
          {c.status ? <StatusChip label={c.status.label} tone={c.status.tone} /> : null}
        </View>
      ))}
    </View>
  );

  const valueCells = (row: TableRow) =>
    row.cells.map((cell, i) => {
      const hasMoney = cell.minor !== undefined && cell.minor !== null;
      return (
        <View key={`${row.key}-${i}`} style={[st.cell, !side && st.cellTight, { width: colWidth }, i > 0 && st.divider, cell.best && st.best]}>
          {hasMoney ? <Text style={[st.value, !side && st.valueSmall, cell.best && st.bestText]}>{money(cell.minor as number)}</Text> : null}
          {cell.text ? <Text style={[hasMoney ? st.meta : [st.value, !side && st.valueSmall], cell.tone === "danger" && st.danger]}>{cell.text}</Text> : null}
        </View>
      );
    });

  // Zebra striping counts data rows only, restarting under each section heading.
  let stripe = 0;
  const body = rows.map((row) => {
    if (row.heading) {
      stripe = 0;
      return (
        <View key={row.key} style={[st.section, !side && { width: scrolls ? colWidth * n : width }]} accessibilityRole="header">
          <Animated.Text style={[st.sectionText, sticky]}>{row.label}</Animated.Text>
        </View>
      );
    }
    const zebra = stripe++ % 2 === 1;
    if (side)
      return (
        <View key={row.key} style={[st.row, zebra && st.zebra]}>
          <Text style={[st.label, { width: LABEL_WIDTH }]}>{row.label}</Text>
          {valueCells(row)}
        </View>
      );
    return (
      <View key={row.key} style={[st.stack, zebra && st.zebra, { width: scrolls ? colWidth * n : width }]}>
        <Animated.Text style={[st.stackLabel, sticky]}>{row.label}</Animated.Text>
        <View style={st.values}>{valueCells(row)}</View>
      </View>
    );
  });

  const foot = footer ? (
    <View style={st.values}>
      {columns.map((c, i) => (
        <View key={c.offerId} style={[st.footCell, { width: colWidth }, i > 0 && st.divider]}>
          {footer(i)}
        </View>
      ))}
    </View>
  ) : null;

  return (
    <View onLayout={onLayout} style={st.frame}>
      {width === 0 ? null : side ? (
        <View style={st.table}>
          <View style={st.row}>
            <Text style={[st.label, st.headLabel, { width: LABEL_WIDTH }]}>{headerLabel}</Text>
            {header}
          </View>
          {body}
          {foot ? (
            <View style={st.row}>
              <View style={{ width: LABEL_WIDTH }} />
              {foot}
            </View>
          ) : null}
        </View>
      ) : (
        <Animated.ScrollView
          horizontal
          scrollEnabled={scrolls}
          showsHorizontalScrollIndicator={scrolls}
          style={st.table}
          scrollEventThrottle={16}
          onScroll={Animated.event([{ nativeEvent: { contentOffset: { x: scrollX } } }], { useNativeDriver: Platform.OS !== "web" })}
        >
          <View>
            {header}
            {body}
            {foot}
          </View>
        </Animated.ScrollView>
      )}
    </View>
  );
}

const st = StyleSheet.create({
  frame: { width: "100%" },
  table: { borderWidth: 1, borderColor: colors.neutral200, borderRadius: radius.card, overflow: "hidden", backgroundColor: colors.white },
  row: { flexDirection: "row", alignItems: "stretch", borderBottomWidth: 1, borderBottomColor: colors.neutral200 },
  values: { flexDirection: "row", alignItems: "stretch" },
  head: { backgroundColor: colors.blue50, borderBottomWidth: 1, borderBottomColor: colors.neutral200 },
  headCell: { padding: space.x2, gap: 4, alignItems: "flex-start" },
  headLabel: { backgroundColor: colors.blue50 },
  headName: { ...type.label, fontSize: 13, lineHeight: 17, color: colors.navy950 },
  headProduct: { ...type.meta, color: colors.neutral600 },
  stack: { borderBottomWidth: 1, borderBottomColor: colors.neutral200 },
  stackLabel: { ...type.label, color: colors.navy950, paddingHorizontal: space.x2, paddingTop: space.x2 },
  label: { ...type.label, color: colors.navy950, padding: space.x3, paddingHorizontal: space.x2 },
  cell: { paddingVertical: space.x2, paddingHorizontal: space.x2, gap: 2, justifyContent: "center" },
  cellTight: { paddingHorizontal: 5 },
  footCell: { padding: space.x2 },
  divider: { borderLeftWidth: 1, borderLeftColor: colors.neutral200 },
  zebra: { backgroundColor: colors.neutral50 },
  section: { backgroundColor: colors.neutral100, paddingHorizontal: space.x2, paddingVertical: space.x2, borderBottomWidth: 1, borderBottomColor: colors.neutral200 },
  sectionText: { ...type.label, color: colors.navy950 },
  value: { ...type.body, fontSize: 15, lineHeight: 20, color: colors.navy950, fontVariant: ["tabular-nums"] },
  // Stacked columns are ~90 dp wide on a 360 dp phone: "500,000,000 FCFA" must break at the space, not mid-number.
  valueSmall: { fontSize: 12, lineHeight: 17 },
  meta: { ...type.meta, color: colors.neutral600 },
  danger: { color: colors.dangerText },
  best: { backgroundColor: colors.successSoft },
  bestText: { color: colors.successText, fontFamily: "Inter_700Bold" },
});
