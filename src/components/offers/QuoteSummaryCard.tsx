import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { CarFront, Pencil, ShieldCheck } from "lucide-react-native";
import { TintedIcon } from "@/components/design";
import { Card, ripple } from "@/components/ui";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import type { Quote } from "@/api/client";
import { riskVehicleLabel } from "@/lib/renewal";
import { colors, radius, space, type } from "@/theme/tokens";
import { useTranslation } from "@/i18n";

/**
 * The quote being priced (vehicle or product, product · quote number) with the one "Edit quote"
 * control, shared by the offers and comparison screens. `onEdit` is omitted once the quote can no
 * longer be changed (accepted, declined, cancelled).
 */
export function QuoteSummaryCard({ quote, product, onEdit }: { quote: Quote; product: string | null; onEdit?: () => void }) {
  const { t, td } = useTranslation();
  const vehicle = riskVehicleLabel(quote.risk_facts);
  const productLabel = product ? td(`qtProd_${product}`, product) : null;
  return (
    <Card>
      <View style={st.row}>
        <TintedIcon icon={String(product ?? "").toLowerCase() === "motor" ? CarFront : ShieldCheck} tint="gold" size={56} />
        <View style={st.text}>
          <Text style={st.title}>{vehicle ?? productLabel ?? t("insuranceOffer")}</Text>
          <Text style={ps.meta}>{[vehicle && productLabel ? productLabel : null, quote.quote_number].filter(Boolean).join(" · ")}</Text>
        </View>
        {onEdit ? (
          <Pressable accessibilityRole="button" accessibilityLabel={t("ofEditQuote")} onPress={onEdit} android_ripple={ripple()} style={({ pressed }) => [st.edit, pressed && st.pressed]}>
            <Text style={st.editText}>{t("ofEditQuote")}</Text>
            <Pencil size={16} color={colors.blue600} />
          </Pressable>
        ) : null}
      </View>
    </Card>
  );
}

const st = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x3, flexWrap: "wrap" },
  text: { flexGrow: 1, flexShrink: 1, flexBasis: 150 },
  title: { ...type.cardTitle, fontSize: 17, lineHeight: 22, color: colors.navy950 },
  edit: { flexDirection: "row", alignItems: "center", gap: 6, minHeight: 48, paddingHorizontal: space.x3, borderRadius: radius.control, backgroundColor: colors.blue50, overflow: "hidden" },
  editText: { ...type.label, color: colors.blue600 },
  pressed: { opacity: 0.85 },
});
