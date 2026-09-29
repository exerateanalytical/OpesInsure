import React, { useMemo } from "react";
import { Pressable, StyleSheet, Text, View } from "react-native";
import { router } from "expo-router";
import { ChevronRight } from "lucide-react-native";
import { AppHeader, Card, Screen, StatusChip } from "@/components/ui";
import { SearchBar } from "@/components/SearchBar";
import { FeaturedChip } from "@/components/institutions/InstitutionProfile";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { BrandArt } from "@/components/design/BrandArt";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { useListFilters, type FilterSection } from "@/components/filters";
import { InstitutionsApi, type Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import { REGISTER_SOURCE_KEY, featuredFirst, filterBrokers, isFeaturedBroker } from "@/lib/institutions";
import { colors, space, type } from "@/theme/tokens";

const NO_SECTIONS: FilterSection[] = [];

/** Authorized brokers from the DGTCFM/MINFI 2026 register, in regulator order. */
export default function Brokers() {
  const { t } = useTranslation();
  // Shared list memory (NAV-002): the search survives back / tab switches; filtering is debounced.
  const flt = useListFilters("customer.brokers", NO_SECTIONS);
  const query = flt.text;
  const setQuery = flt.setText;
  const q = useLoad(() => InstitutionsApi.list("broker"), []);
  const filtered = useMemo(() => featuredFirst(filterBrokers(q.data ?? [], flt.query)), [q.data, flt.query]);
  const official = (q.data ?? []).filter((b) => b.is_official_register).length;

  return (
    <Screen>
      <AppHeader
        title={t("authorizedBrokers", { count: official || (q.data?.length ?? 0) })}
        subtitle={t(REGISTER_SOURCE_KEY)}
        back
      />
      {/* Same search field as the insurer list (no filter sheet: brokers have no filter sections). */}
      <SearchBar
        label={t("searchBrokers")}
        value={query}
        onChangeText={setQuery}
        placeholder={t("searchBrokersPlaceholder")}
        clearLabel={t("clearSearch")}
      />
      {q.data && flt.query ? (
        <Text accessibilityLiveRegion="polite" style={styles.note}>
          {t(filtered.length === 1 ? "fltResultsOne" : "fltResults", { count: filtered.length })}
        </Text>
      ) : null}
      <Text style={styles.note}>{t("brokerVerifyNote")}</Text>
      <StatePanel
        {...q}
        onRetry={q.reload}
        loadingLabel={t("loadingBrokers")}
        emptyTitle={t("noBrokersFound")}
        emptyMessage={t("registerEmptyBody")}
      >
        {() =>
          filtered.length ? (
            <>
              {filtered.map((b) => (
                <BrokerRow key={b.id} broker={b} />
              ))}
            </>
          ) : (
            <Card>
              <Text style={styles.name}>{t("noBrokersFound")}</Text>
            </Card>
          )
        }
      </StatePanel>
      <BrandArt name="africa_dots_blue" width={88} opacity={0.8} />
      <Text style={styles.source}>{t(REGISTER_SOURCE_KEY)}</Text>
    </Screen>
  );
}

function BrokerRow({ broker }: { broker: Institution }) {
  const { t } = useTranslation();
  const featured = isFeaturedBroker(broker);
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={featured ? `${broker.name}, ${t("brokerFeatured")}` : broker.name}
      onPress={() => router.push({ pathname: "/institutions/broker/[id]", params: { id: broker.id } })}
    >
      <Card style={featured ? styles.featuredCard : undefined}>
        <View style={styles.row}>
          <InstitutionMark logoUrl={institutionLogo(broker)} initials={broker.initials} size={42} />
          <View style={styles.copy}>
            {featured ? <FeaturedChip label={t("brokerFeatured")} /> : null}
            <Text style={styles.name}>{broker.name}</Text>
            <Text style={styles.meta}>
              {[
                broker.regulator_number ? t("regulatorNumber", { number: broker.regulator_number }) : null,
                broker.city,
                broker.phone,
              ]
                .filter(Boolean)
                .join(" · ")}
            </Text>
            {broker.licensed ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
          </View>
          <ChevronRight size={20} color={colors.neutral500} />
        </View>
      </Card>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  note: { ...type.meta, color: colors.neutral600 },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  copy: { flex: 1, gap: 3 },
  name: { ...type.label, color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
  featuredCard: { borderColor: colors.gold500, borderWidth: 1.5 },
});
