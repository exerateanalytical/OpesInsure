import React, { useEffect, useState } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import {
  Car,
  ChevronRight,
  HeartPulse,
  Home,
  Plane,
  ShieldPlus,
} from "lucide-react-native";
import { AppHeader, Button, Card, Screen } from "@/components/ui";
import { colors, radius, space, type } from "@/theme/tokens";
import { useInsurance } from "@/store/insurance";

const products = [
  ["motor", "Motor insurance", "Car, motorcycle or commercial vehicle", Car],
  ["health", "Health cover", "Individual, family or employee healthcare", HeartPulse],
  ["travel", "Travel insurance", "Medical assistance and trip protection", Plane],
  ["home", "Home insurance", "Building, contents and liability", Home],
  ["life", "Life protection", "Family protection, savings or education", ShieldPlus],
] as const;
type ProductId = (typeof products)[number][0];
const isProduct = (v: unknown): v is ProductId =>
  typeof v === "string" && products.some(([id]) => id === v);

export default function Product() {
  const setProduct = useInsurance((s) => s.setProduct);
  const { product: param } = useLocalSearchParams<{ product?: string }>();
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
    router.push({ pathname: "/quote/risk", params: { product: id } });
  };
  const current = products.find(([id]) => id === selected);
  return (
    <Screen>
      <AppHeader
        title="Choose your cover"
        subtitle="Step 1 of 5 · You can save and return"
        back
      />
      {current ? (
        <Button
          label={`Continue with ${current[1].toLowerCase()}`}
          onPress={() => proceed(current[0])}
        />
      ) : null}
      {products.map(([id, title, subtitle, Icon]) => {
        const on = id === selected;
        return (
          <Pressable
            accessibilityRole="button"
            accessibilityState={{ selected: on }}
            accessibilityLabel={`${title}. ${subtitle}`}
            key={id}
            onPress={() => proceed(id)}
          >
            <Card style={on && styles.selected}>
              <View style={styles.row}>
                <View style={styles.icon}>
                  <Icon size={22} color={colors.blue600} />
                </View>
                <View style={styles.copy}>
                  <Text style={styles.title}>{title}</Text>
                  <Text style={styles.subtitle}>{subtitle}</Text>
                </View>
                <ChevronRight size={20} color={colors.neutral500} />
              </View>
            </Card>
          </Pressable>
        );
      })}
    </Screen>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  icon: {
    width: 44,
    height: 44,
    borderRadius: radius.control,
    backgroundColor: colors.blue50,
    alignItems: "center",
    justifyContent: "center",
  },
  copy: { flex: 1 },
  title: { ...type.label, color: colors.navy950 },
  subtitle: { ...type.meta, color: colors.neutral600 },
  selected: {
    borderColor: colors.blue600,
    borderWidth: 2,
    backgroundColor: colors.blue50,
  },
});
