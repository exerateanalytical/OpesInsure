import React, { useMemo } from "react";
import { StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { CreditCard, ShieldCheck } from "lucide-react-native";
import { Payment, PaymentsApi } from "@/api/client";
import { Card, Screen, StatusChip } from "@/components/ui";
import { Banner, BrandHeader, SectionHeading, TintedIcon } from "@/components/design";
import { EmptyState, ErrorState, LoadingState } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { networkName } from "@/lib/purchase";
import { useTranslation } from "@/i18n";
import { colors, radius, space, type } from "@/theme/tokens";

type Method = { key: string; provider: string; phone: string; count: number; last: string | null; succeeded: boolean };

const mask = (e164: string) => (e164.startsWith("+237") && e164.length > 8 ? `+237 ${e164.slice(4, 5)}•• ••• ${e164.slice(-3)}` : e164.length > 7 ? `${e164.slice(0, 4)} •• ••• ${e164.slice(-3)}` : e164);

/** Payer numbers the customer actually used, newest first (from GET /mobile/payments). */
function methodsFromPayments(payments: Payment[]): Method[] {
  const map = new Map<string, Method>();
  for (const p of payments) {
    if (!p.payer_phone_e164) continue;
    const key = `${p.provider}|${p.payer_phone_e164}`;
    const at = p.created_at ?? p.updated_at ?? null;
    const ok = ["SUCCEEDED", "SUCCESSFUL", "SUCCESS", "PAID", "COMPLETED"].includes(String(p.status).toUpperCase());
    const m = map.get(key);
    if (!m) map.set(key, { key, provider: p.provider, phone: p.payer_phone_e164, count: 1, last: at, succeeded: ok });
    else {
      m.count += 1;
      m.succeeded ||= ok;
      if (at && (!m.last || at > m.last)) m.last = at;
    }
  }
  return [...map.values()].sort((a, b) => String(b.last ?? "").localeCompare(String(a.last ?? "")));
}

/**
 * Payment methods. The backend keeps no saved payment methods or cards
 * (payments take a payer number per transaction), so this lists — read
 * only — the mobile-money networks and numbers used in past payments.
 */
export default function PaymentMethods() {
  const { t, date } = useTranslation();
  const q = useLoad(async () => {
    const items: Payment[] = [];
    for (let page = 1; page <= 5; page++) {
      const r = await PaymentsApi.list(page);
      items.push(...r.items);
      if (!r.info.hasMore) break;
    }
    return items;
  }, []);
  const methods = useMemo(() => methodsFromPayments(q.data ?? []), [q.data]);
  return (
    <Screen>
      <BrandHeader title={t("payMethodsTitle")} subtitle={t("payMethodsSubtitle")} back right={null} />
      {q.loading && !q.data ? <LoadingState /> : null}
      {q.error && !q.data ? <ErrorState error={q.error} onRetry={q.reload} /> : null}
      {q.data ? <SectionHeading title={t("payMethodsMobileMoney")} /> : null}
      {q.data && !methods.length ? (
        <EmptyState title={t("payMethodsEmpty")} message={t("payMethodsEmptyBody")} action={t("payMethodsHistory")} onPress={() => router.push("/payments" as never)} />
      ) : null}
      {methods.map((m, i) => (
        <Card key={m.key} style={styles.card} accessibilityLabel={`${networkName(m.provider)} ${mask(m.phone)}`}>
          <View style={styles.row}>
            <TintedIcon icon={CreditCard} tint="blue" size={44} />
            <View style={styles.flex}>
              <View style={styles.topRow}>
                <Text style={styles.title}>{networkName(m.provider)}</Text>
                {i === 0 ? <StatusChip label={t("payMethodsLastUsed")} tone="success" /> : m.succeeded ? <StatusChip label={t("payMethodsVerified")} tone="info" /> : null}
              </View>
              <Text style={styles.body}>{mask(m.phone)}</Text>
              <Text style={styles.meta}>
                {t("payMethodsUsed", { n: m.count })}
                {m.last ? ` · ${date(m.last)}` : ""}
              </Text>
            </View>
          </View>
        </Card>
      ))}
      <Banner icon={ShieldCheck} tint="neutral" title={t("payMethodsNoteTitle")} body={t("payMethodsNoteBody")} />
    </Screen>
  );
}
const styles = StyleSheet.create({
  card: { borderRadius: radius.feature },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1, gap: 2 },
  topRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: space.x2 },
  title: { ...type.label, fontSize: 16, lineHeight: 21, color: colors.navy950, flexBasis: 100, flexGrow: 1, flexShrink: 1 },
  body: { ...type.body, color: colors.neutral700 },
  meta: { ...type.meta, color: colors.neutral600 },
});
