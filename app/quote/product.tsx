import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ArrowRight, Info } from "lucide-react-native";
import { BrandHeader, CtaBar, RadioCard, Tint } from "@/components/design";
import { CATEGORIES } from "@/components/customer/categories";
import { CATEGORY_TINT } from "@/components/customer/CategoryTiles";
import { QuoteSteps } from "@/components/purchase/PurchaseUi";
import { Button, ripple, Screen } from "@/components/ui";
import { colors, radius } from "@/theme/tokens";
import { useInsurance } from "@/store/insurance";
import { useTranslation } from "@/i18n";
import { focusFromParams, focusParams } from "@/lib/quoteFocus";

/** Purchasable lines, in display order; icons come from the shared category list. */
const PRODUCT_IDS = ["motor", "health", "travel", "home", "life", "business", "accident"] as const;
type ProductId = (typeof PRODUCT_IDS)[number];
const products = PRODUCT_IDS.map((id) => [id, CATEGORIES.find((c) => c.id === id)!.icon] as const);
const isProduct = (v: unknown): v is ProductId =>
  typeof v === "string" && PRODUCT_IDS.some((id) => id === v);

/** RadioCard tint per line, derived from the shared category tints (motor gold, health red, travel blue, home green). */
const tintOf = (id: ProductId): Tint => {
  const c = CATEGORY_TINT[id];
  if (c.fg === colors.danger) return "red";
  if (c.bg === colors.gold50) return "gold";
  if (c.bg === colors.successSoft) return "green";
  return "blue";
};

export default function Product() {
  const { t } = useTranslation();
  const setProduct = useInsurance((s) => s.setProduct);
  // carriers/from: "Get a quote" from an insurer or broker profile keeps that choice through to the offers.
  const { product: param, carriers, from } = useLocalSearchParams<{ product?: string; carriers?: string; from?: string }>();
  // Preselect when arriving from a Home tile (/quote/product?product=motor).
  const [selected, setSelected] = useState<ProductId | null>(
    isProduct(param) ? param : null,
  );
  useEffect(() => {
    if (isProduct(param)) {
      setSelected(param);
      setProduct(param);
    }
  }, [param, setProduct]);
  const proceed = (id: ProductId) => {
    setSelected(id);
    setProduct(id);
    router.push({ pathname: "/quote/risk", params: { product: id, ...focusParams(focusFromParams(carriers, from)) } });
  };
  const current = products.find(([id]) => id === selected);
  return (
    <Screen
      footer={
        current ? (
          <CtaBar>
            <Button
              label={t("qtContinueWith", { product: t(`qtProd_${current[0]}`).toLowerCase() })}
              icon={ArrowRight}
              onPress={() => proceed(current[0])}
            />
          </CtaBar>
        ) : null
      }
    >
      <BrandHeader title={t("qtTitle")} subtitle={t("qtChooseCoverSub")} />
      <QuoteSteps current={0} />
      <View style={styles.list}>
        {products.map(([id, Icon]) => {
          const on = id === selected;
          const title = t(`qtProd_${id}`);
          const subtitle = t(`qtProdSub_${id}`);
          return (
            <RadioCard
              key={id}
              selected={on}
              onPress={() => proceed(id)}
              title={title}
              subtitle={subtitle}
              icon={Icon}
              tint={tintOf(id)}
              right={
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel={`${t("searchViewDetails")}: ${title}`}
                  hitSlop={8}
                  android_ripple={ripple()}
                  onPress={() => router.push({ pathname: "/quote/product/[id]", params: { id } })}
                  style={styles.details}
                >
                  <Info size={20} color={colors.blue600} />
                </Pressable>
              }
            />
          );
        })}
      </View>
    </Screen>
  );
}

const styles = StyleSheet.create({
  list: { gap: 12 },
  details: { width: 36, height: 36, borderRadius: radius.control, backgroundColor: colors.blue50, alignItems: "center", justifyContent: "center", overflow: "hidden" },
});
