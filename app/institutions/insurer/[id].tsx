import React from "react";
import { Linking, Pressable, StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { ExternalLink, Mail, MapPin, Phone } from "lucide-react-native";
import {
  AppHeader,
  Button,
  Card,
  Screen,
  SectionTitle,
  StatusChip,
} from "@/components/ui";
import { StatePanel } from "@/components/StatePanel";
import { useLoad } from "@/hooks/useLoad";
import { InstitutionsApi } from "@/api/extra";
import { useTranslation } from "@/i18n";
import {
  REGISTER_SOURCE_KEY,
  groupBranchesByCity,
  readDirectory,
  telUrl,
  verificationText,
} from "@/lib/institutions";
import { useInsurance } from "@/store/insurance";
import { useSession } from "@/store/session";
import { colors, radius, space, type } from "@/theme/tokens";

export default function InsurerDetail() {
  const { t, language } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  const q = useLoad(() => InstitutionsApi.show(id), [id]);
  const status = useSession((s) => s.status);
  const setProduct = useInsurance((s) => s.setProduct);
  const compare = (lineCode: string) => {
    if (status !== "authenticated") {
      router.push("/(auth)/sign-in");
      return;
    }
    setProduct(lineCode.toLowerCase());
    router.push("/quote/risk");
  };
  return (
    <Screen>
      <AppHeader title={t("insurerProfile")} back />
      <StatePanel {...q} onRetry={q.reload} isEmpty={() => false} loadingLabel={t("loadingInsurers")}>
        {(insurer) => (
          <>
            <Card feature>
              <View style={styles.between}>
                <View style={styles.logo}>
                  <Text style={styles.logoText}>{insurer.initials}</Text>
                </View>
                <View style={styles.badges}>
                  {insurer.branch ? (
                    <StatusChip
                      label={t(insurer.branch === "LIFE" ? "branchBadgeLIFE" : "branchBadgeIARD")}
                      tone={insurer.branch === "LIFE" ? "success" : "info"}
                    />
                  ) : null}
                  {insurer.is_official_register && insurer.licensed ? (
                    <StatusChip label={t("licensedStatus")} tone="success" />
                  ) : null}
                </View>
              </View>
              <Text style={styles.title}>{insurer.short_name ?? insurer.name}</Text>
              {insurer.legal_name ? <Text style={styles.body}>{insurer.legal_name}</Text> : null}
              {insurer.canonical_id ? (
                <Text style={styles.canonical}>{t("canonicalId", { id: insurer.canonical_id })}</Text>
              ) : null}
              {(() => {
                const v = readDirectory(insurer).verification;
                return v ? (
                  <View style={styles.row}>
                    <StatusChip label={verificationText(v, language, t)} tone={v.tone} />
                  </View>
                ) : null;
              })()}
            </Card>

            <DirectorySections insurer={insurer} />

            {insurer.product_families?.length ? (
              <>
                <SectionTitle title={t("publishedFamiliesUnverified")} />
                <Card>
                  <View style={styles.families}>
                    {insurer.product_families.map((f) => (
                      <Text key={f} style={styles.family}>{f}</Text>
                    ))}
                  </View>
                </Card>
              </>
            ) : null}

            <SectionTitle title={t("productsOnOpesInsure")} />
            {insurer.products?.length ? (
              insurer.products.map((p) => (
                <Card key={p.id}>
                  <View style={styles.between}>
                    <Text style={styles.offer}>{p.name}</Text>
                    <StatusChip label={p.line_code} tone="info" />
                  </View>
                  <Button label={t("compareThisProduct")} onPress={() => compare(p.line_code)} />
                </Card>
              ))
            ) : (
              <Card>
                <Text style={styles.offer}>{t("noPlatformProducts")}</Text>
                <Text style={styles.body}>{t("noPlatformProductsBody")}</Text>
              </Card>
            )}
            {insurer.is_official_register ? (
              <Text style={styles.source}>{t(REGISTER_SOURCE_KEY)}</Text>
            ) : null}
          </>
        )}
      </StatePanel>
    </Screen>
  );
}
const open = (url: string | null) => {
  if (url) void Linking.openURL(url).catch(() => undefined);
};

function DirectorySections({ insurer }: { insurer: Parameters<typeof readDirectory>[0] }) {
  const { t } = useTranslation();
  const d = readDirectory(insurer);
  const groups = groupBranchesByCity(d.branches);
  const hasContacts = d.phones.length || d.emails.length || d.website;
  return (
    <>
      {d.hq ? (
        <>
          <SectionTitle title={t("headOffice")} />
          <Card>
            <View style={styles.row}>
              <MapPin size={18} color={colors.neutral600} />
              <View style={styles.copy}>
                {d.hq.address ? <Text style={styles.offer}>{d.hq.address}</Text> : null}
                {d.hq.city ? <Text style={styles.body}>{d.hq.city}</Text> : null}
                {d.hq.po_box ? <Text style={styles.body}>{t("poBox", { box: d.hq.po_box })}</Text> : null}
              </View>
            </View>
          </Card>
        </>
      ) : null}
      {hasContacts ? (
        <>
          <SectionTitle title={t("contactDetails")} />
          <Card>
            {d.phones.map((p) =>
              telUrl(p) ? (
                <Button
                  key={p}
                  label={t("callNumber", { phone: p })}
                  icon={Phone}
                  variant="secondary"
                  onPress={() => open(telUrl(p))}
                />
              ) : null,
            )}
            {d.emails.map((e) => (
              <Button
                key={e}
                label={t("sendEmail", { email: e })}
                icon={Mail}
                variant="secondary"
                onPress={() => open(`mailto:${e}`)}
              />
            ))}
            {d.website ? (
              <Button label={t("openWebsite")} icon={ExternalLink} variant="tertiary" onPress={() => open(d.website)} />
            ) : null}
          </Card>
        </>
      ) : null}
      {groups.length ? (
        <>
          <SectionTitle title={t("branchNetwork", { count: d.branches.length })} />
          {groups.map((g) => (
            <Card key={g.city ?? "_other"}>
              <Text style={styles.offer}>{g.city ?? t("cityUnknown")}</Text>
              {g.branches.map((b, i) => (
                <View key={`${b.name}-${i}`} style={styles.branch}>
                  {b.name ? <Text style={styles.branchName}>{b.name}</Text> : null}
                  {b.address ? <Text style={styles.body}>{b.address}</Text> : null}
                  {b.phone && telUrl(b.phone) ? (
                    <Pressable
                      accessibilityRole="link"
                      accessibilityLabel={t("callNumber", { phone: b.phone })}
                      onPress={() => open(telUrl(b.phone!))}
                      style={styles.row}
                    >
                      <Phone size={16} color={colors.blue700} />
                      <Text style={styles.link}>{b.phone}</Text>
                    </Pressable>
                  ) : null}
                </View>
              ))}
            </Card>
          ))}
        </>
      ) : null}
      {d.sources.length ? (
        <Text style={styles.source}>{t("directorySources", { sources: d.sources.join(", ") })}</Text>
      ) : null}
    </>
  );
}

const styles = StyleSheet.create({
  row: { flexDirection: "row", alignItems: "center", gap: space.x2, minHeight: 32 },
  copy: { flex: 1, gap: 3 },
  branch: { gap: 2, paddingTop: space.x2, borderTopWidth: 1, borderTopColor: colors.neutral100 },
  branchName: { ...type.label, color: colors.navy950 },
  link: { ...type.label, color: colors.blue700 },
  logo: {
    width: 56,
    height: 56,
    borderRadius: radius.card,
    backgroundColor: colors.navy950,
    alignItems: "center",
    justifyContent: "center",
  },
  logoText: { ...type.label, color: colors.white },
  badges: { flexDirection: "row", gap: space.x2, flexWrap: "wrap", justifyContent: "flex-end", flex: 1 },
  title: { ...type.pageTitle, color: colors.navy950 },
  offer: { ...type.cardTitle, color: colors.navy950, flex: 1 },
  body: { ...type.body, color: colors.neutral600 },
  canonical: { ...type.meta, color: colors.neutral500, fontVariant: ["tabular-nums"] },
  families: { flexDirection: "row", flexWrap: "wrap", gap: 6 },
  family: {
    ...type.meta,
    color: colors.neutral700,
    borderWidth: 1,
    borderColor: colors.neutral200,
    borderRadius: radius.pill,
    paddingHorizontal: space.x2,
    paddingVertical: 2,
  },
  source: { ...type.meta, color: colors.neutral500, textAlign: "center" },
  between: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "flex-start",
    gap: space.x3,
  },
});
