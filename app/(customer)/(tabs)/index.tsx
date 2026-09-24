import React from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import {
  Bell,
  Car,
  CreditCard,
  HeartPulse,
  Home as HomeIcon,
  LucideIcon,
  Plane,
  Search,
  WalletCards,
} from "lucide-react-native";
import { BrandMark } from "@/components/BrandMark";
import { Button, Card, Screen, SectionTitle, StatusChip } from "@/components/ui";
import { PolicyCard } from "@/components/InsuranceCards";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { useColumns } from "@/components/responsive";
import { usePolicies } from "@/hooks/usePolicies";
import { useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";

const products = [
  ["motor", "Motor", "Drive protected", Car],
  ["health", "Health", "Care made accessible", HeartPulse],
  ["travel", "Travel", "Go with confidence", Plane],
  ["home", "Home", "Protect your space", HomeIcon],
] as const;

const shortcuts: [string, string, LucideIcon, string][] = [
  ["Payments", "Receipts and refunds", CreditCard, "/payments"],
  ["Wallet", "Policy cards offline", WalletCards, "/wallet"],
];

export default function CustomerHome() {
  const user = useSession((s) => s.bootstrap?.user);
  const { policies, loading, error, reload } = usePolicies();
  const grid = useColumns();
  const shortcutGrid = useColumns({ max: 2 });
  const first = policies[0];
  return (
    <Screen>
      <View style={styles.top}>
        <BrandMark />
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Notifications"
          hitSlop={6}
          onPress={() => router.push("/notifications")}
          style={({ pressed }) => [styles.bell, pressed && styles.pressed]}
        >
          <Bell size={21} color={colors.navy950} />
        </Pressable>
      </View>
      <View>
        <Text style={styles.eyebrow}>
          WELCOME{user?.full_name ? `, ${user.full_name.toUpperCase()}` : ""}
        </Text>
        <Text style={styles.title}>Protection, without the confusion.</Text>
      </View>
      <Card feature style={styles.hero}>
        <StatusChip label="Simple · Secure · Cameroon-ready" tone="info" />
        <Text style={styles.heroTitle}>Compare trusted insurance in minutes.</Text>
        <Text style={styles.heroText}>
          Every price and policy state comes directly from the platform.
        </Text>
        <Button
          label="Compare offers"
          icon={Search}
          onPress={() => router.push("/quote/product")}
        />
      </Card>

      <View style={shortcutGrid.row}>
        {shortcuts.map(([title, subtitle, Icon, path]) => (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${title}. ${subtitle}`}
            key={title}
            style={({ pressed }) => [
              styles.shortcut,
              shortcutGrid.item,
              pressed && styles.pressed,
            ]}
            onPress={() => router.push(path as never)}
          >
            <View style={styles.productIcon}>
              <Icon size={21} color={colors.blue600} />
            </View>
            <View style={styles.flex}>
              <Text style={styles.productTitle}>{title}</Text>
              <Text style={styles.meta}>{subtitle}</Text>
            </View>
          </Pressable>
        ))}
      </View>

      <SectionTitle title="What would you like to protect?" />
      <View style={grid.row}>
        {products.map(([id, title, subtitle, Icon]) => (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={`${title} insurance. ${subtitle}`}
            key={id}
            style={({ pressed }) => [
              styles.product,
              grid.item,
              pressed && styles.pressed,
            ]}
            onPress={() =>
              router.push({ pathname: "/quote/product", params: { product: id } })
            }
          >
            <View style={styles.productIcon}>
              <Icon size={23} color={colors.blue600} />
            </View>
            <Text style={styles.productTitle}>{title}</Text>
            <Text style={styles.meta}>{subtitle}</Text>
          </Pressable>
        ))}
      </View>

      <SectionTitle title="Your protection" />
      {loading ? (
        <LoadingState label="Loading your policies…" />
      ) : error ? (
        <ErrorState onRetry={() => void reload()} />
      ) : first ? (
        <PolicyCard
          policy={first}
          onPress={() =>
            router.push({ pathname: "/policy/[id]", params: { id: first.id } })
          }
        />
      ) : (
        <EmptyState
          title="No active policy yet"
          message="Issued policies will appear here."
          action="Compare offers"
          onPress={() => router.push("/quote/product")}
        />
      )}
    </Screen>
  );
}

const styles = StyleSheet.create({
  top: {
    height: 52,
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
  },
  bell: {
    width: 44,
    height: 44,
    borderRadius: radius.control,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
  },
  pressed: { opacity: 0.82 },
  flex: { flex: 1 },
  eyebrow: { ...type.caption, color: colors.neutral600, letterSpacing: 1.2 },
  title: { ...type.pageTitle, color: colors.navy950, marginTop: 5 },
  hero: { backgroundColor: colors.navy950, borderColor: colors.navy950 },
  heroTitle: { ...type.sectionTitle, color: colors.white },
  heroText: { ...type.body, color: colors.neutral200 },
  shortcut: {
    minHeight: 72,
    flexDirection: "row",
    alignItems: "center",
    gap: space.x3,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x3,
  },
  product: {
    minHeight: 134,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.card,
    padding: space.x4,
    gap: space.x2,
  },
  productIcon: {
    width: 40,
    height: 40,
    borderRadius: radius.control,
    backgroundColor: colors.blue50,
    alignItems: "center",
    justifyContent: "center",
  },
  productTitle: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
});
