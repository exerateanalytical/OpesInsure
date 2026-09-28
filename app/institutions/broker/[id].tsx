import React from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Building2, ChevronRight, FileText, Globe, Mail, MapPin, Phone, Star } from "lucide-react-native";
import { AppHeader, Button, Card, Screen, StatusChip } from "@/components/ui";
import { SectionHeading } from "@/components/design";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import { BrandArt } from "@/components/design/BrandArt";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi, type Institution } from "@/api/extra";
import { formatDisplayDate, useTranslation } from "@/i18n";
import { REGISTER_SOURCE_KEY, isFeaturedBroker, productsByLine } from "@/lib/institutions";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Broker profile: identity and licence, the insurance companies the broker is appointed by,
 * the policies it offers (grouped by line) and how to reach it. Affiliations and products come
 * from the broker's ACTIVE carrier agreements (GET /public/institutions/{id}).
 */
export default function BrokerDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => InstitutionsApi.show(id), [id]);
  return (
    <Screen>
      <AppHeader title={t("brokerProfile")} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loadingBrokers")}>
        {(broker) => (
          <>
            <Identity broker={broker} />
            <Insurers broker={broker} />
            <Policies broker={broker} />
            <Contact broker={broker} />
            <Button label={t("propGetQuote")} onPress={() => router.push("/quote/product")} />
            <BrandArt name="map_neon" width={88} opacity={0.85} />
            <Text style={styles.source}>{t("brokerVerifyNote")}</Text>
            {broker.is_official_register ? <Text style={styles.source}>{t(REGISTER_SOURCE_KEY)}</Text> : null}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}

function Identity({ broker }: { broker: Institution }) {
  const { t } = useTranslation();
  const featured = isFeaturedBroker(broker);
  return (
    <Card feature>
      <View style={styles.head}>
        <InstitutionMark logoUrl={institutionLogo(broker)} initials={broker.initials} size={64} />
        <View style={styles.flex}>
          <Text style={styles.title}>{broker.name}</Text>
          {broker.city ? (
            <View style={styles.inline}>
              <MapPin size={14} color={colors.neutral600} />
              <Text style={styles.meta}>{t("brokerCityCountry", { city: broker.city })}</Text>
            </View>
          ) : null}
        </View>
      </View>
      <View style={styles.badges}>
        {featured ? (
          <View style={styles.featured}>
            <Star size={13} color={colors.navy950} fill={colors.navy950} />
            <Text style={styles.featuredText}>{t("brokerFeatured")}</Text>
          </View>
        ) : null}
        {broker.licensed ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
        {broker.regulator_number ? <StatusChip label={t("regulatorNumber", { number: broker.regulator_number })} tone="info" /> : null}
        {broker.licence_number ? <StatusChip label={t("brokerLicenceNumber", { number: broker.licence_number })} tone="info" /> : null}
      </View>
      {broker.licence_expires_on ? (
        <Text style={styles.meta}>{t("brokerLicenceExpires", { date: formatDisplayDate(broker.licence_expires_on) })}</Text>
      ) : null}
      {broker.canonical_id ? <Text style={styles.canonical}>{t("canonicalId", { id: broker.canonical_id })}</Text> : null}
    </Card>
  );
}

function Insurers({ broker }: { broker: Institution }) {
  const { t, td } = useTranslation();
  const rows = broker.affiliated_insurers ?? [];
  return (
    <>
      <SectionHeading title={rows.length ? t("brokerInsurersCount", { count: rows.length }) : t("brokerInsurers")} icon={Building2} />
      <Card>
        {rows.length ? (
          rows.map((c, i) => (
            <Pressable
              key={c.id}
              accessibilityRole="button"
              accessibilityLabel={c.name}
              onPress={() => router.push({ pathname: "/institutions/insurer/[id]", params: { id: c.id } })}
              style={({ pressed }) => [styles.row, i > 0 && styles.divider, pressed && styles.pressed]}
            >
              <InstitutionMark logoUrl={c.logo_url ?? null} initials={c.initials} size={40} />
              <View style={styles.flex}>
                <Text style={styles.rowTitle}>{c.name}</Text>
                {c.lines?.length ? (
                  <Text style={styles.meta} numberOfLines={2}>
                    {c.lines.map((l) => td(`line_${l}`, l)).join(" · ")}
                  </Text>
                ) : null}
              </View>
              <ChevronRight size={18} color={colors.neutral500} />
            </Pressable>
          ))
        ) : (
          <Text style={styles.meta}>{t("brokerNoInsurers")}</Text>
        )}
      </Card>
    </>
  );
}

function Policies({ broker }: { broker: Institution }) {
  const { t, td } = useTranslation();
  const groups = productsByLine(broker.products);
  return (
    <>
      <SectionHeading title={t("brokerPolicies")} icon={FileText} />
      {groups.length ? (
        groups.map((g) => (
          <Card key={g.line}>
            <Text style={styles.lineTitle}>{td(`line_${g.line}`, g.line)}</Text>
            {g.products.map((p, i) => (
              <Pressable
                key={p.id}
                accessibilityRole="button"
                accessibilityLabel={[p.name, p.carrier_name].filter(Boolean).join(", ")}
                onPress={() =>
                  p.carrier_id
                    ? router.push({ pathname: "/institutions/insurer/[id]", params: { id: p.carrier_id } })
                    : router.push("/quote/product")
                }
                style={({ pressed }) => [styles.row, i > 0 && styles.divider, pressed && styles.pressed]}
              >
                <View style={styles.flex}>
                  <Text style={styles.rowTitle}>{p.name}</Text>
                  {p.carrier_name ? <Text style={styles.meta}>{p.carrier_name}</Text> : null}
                </View>
                <ChevronRight size={18} color={colors.neutral500} />
              </Pressable>
            ))}
          </Card>
        ))
      ) : (
        <Card>
          <Text style={styles.meta}>{t("brokerNoPolicies")}</Text>
        </Card>
      )}
    </>
  );
}

function Contact({ broker }: { broker: Institution }) {
  const { t } = useTranslation();
  const phones = [broker.phone, ...(broker.contacts?.phones ?? [])].filter((p, i, all): p is string => !!p && all.indexOf(p) === i);
  const emails = broker.contacts?.emails ?? [];
  const website = broker.website || broker.contacts?.website;
  return (
    <>
      <SectionHeading title={t("brokerContact")} icon={Phone} />
      <Card>
        {phones.map((p) => (
          <Button key={p} label={p} icon={Phone} variant="secondary" onPress={() => void Linking.openURL(`tel:${p}`)} />
        ))}
        {emails.map((e) => (
          <Button key={e} label={e} icon={Mail} variant="secondary" onPress={() => void Linking.openURL(`mailto:${e}`)} />
        ))}
        {website ? (
          <Button
            label={t("openWebsite")}
            icon={Globe}
            variant="secondary"
            onPress={() => void Linking.openURL(/^https?:/.test(website) ? website : `https://${website}`)}
          />
        ) : null}
        {!phones.length && !emails.length && !website ? <Text style={styles.meta}>{t("brokerNoContact")}</Text> : null}
      </Card>
    </>
  );
}

const styles = StyleSheet.create({
  head: { flexDirection: "row", alignItems: "center", gap: space.x3 },
  flex: { flex: 1, gap: 2 },
  inline: { flexDirection: "row", alignItems: "center", gap: 4 },
  title: { ...type.pageTitle, color: colors.navy950 },
  badges: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  featured: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    backgroundColor: colors.gold500,
    borderRadius: radius.pill,
    paddingHorizontal: space.x3,
    paddingVertical: 4,
  },
  featuredText: { ...type.caption, fontFamily: "Inter_700Bold", color: colors.navy950 },
  meta: { ...type.meta, color: colors.neutral600 },
  canonical: { ...type.meta, color: colors.neutral500, fontVariant: ["tabular-nums"] },
  row: { flexDirection: "row", alignItems: "center", gap: space.x3, minHeight: 56, paddingVertical: space.x2 },
  divider: { borderTopWidth: 1, borderTopColor: colors.neutral100 },
  pressed: { opacity: 0.8 },
  rowTitle: { ...type.label, color: colors.navy950 },
  lineTitle: { ...type.caption, color: colors.blue700, textTransform: "uppercase", letterSpacing: 0.6 },
  source: { ...type.meta, color: colors.neutral500 },
});
