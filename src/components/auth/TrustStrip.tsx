import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { LucideIcon } from "lucide-react-native";
import { authColors, authIcon, authSpace, authType } from "@/theme/authTokens";

export type TrustItem = { icon: LucideIcon; label: string };

export function TrustStrip({ items }: { items: TrustItem[] }) {
  return (
    <View style={styles.row}>
      {items.map((item, index) => {
        const Icon = item.icon;
        return (
          <React.Fragment key={item.label}>
            {index > 0 ? <View style={styles.divider} /> : null}
            <View style={styles.item}>
              <Icon size={authIcon.feature - 4} strokeWidth={authIcon.strokeWidth} color={authColors.navy800} />
              <Text style={styles.label}>{item.label}</Text>
            </View>
          </React.Fragment>
        );
      })}
    </View>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "flex-start", justifyContent: "center" },
  item: { flex: 1, alignItems: "center", gap: authSpace[1], paddingHorizontal: authSpace[1] },
  divider: { width: StyleSheet.hairlineWidth, backgroundColor: authColors.ice200, marginTop: 4, height: 40 },
  label: {
    ...authType.label,
    fontSize: 12,
    lineHeight: 15,
    color: authColors.navy800,
    textAlign: "center",
  },
});
