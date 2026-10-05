import React from "react";
import { StyleSheet, Text, View } from "react-native";
import { router, useLocalSearchParams } from "expo-router";
import { Handshake, Info, Layers, MapPin, Package } from "lucide-react-native";
import { Button, StatusChip } from "@/components/ui";
import { SectionHeading } from "@/components/design";
import { institutionLogo } from "@/components/InstitutionMark";
import {
  ContactCard,
  EmptyNote,
  InstitutionScreen,
  KeyFacts,
  OfficesSection,
  ProductGroups,
  ProductIcon,
  ProfileCard,
  ProfileFooter,
  ProfileHero,
  VerifiedChip,
  useContactActions,
  useStartQuote,
  type Fact,
} from "@/components/institutions/InstitutionProfile";
import type { Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import type { CopyKey } from "@/i18n/strings";
import { productsByLine, readDirectory, verificationText } from "@/lib/institutions";
import { insurerFacts, insurerKindKey, legalFooterLines, legalNameLine, officesPending } from "@/lib/insurerProfile";
import { productFamilyLabels } from "@/lib/productFamilies";
import { quoteFocusOf } from "@/lib/quoteFocus";
import { colors, radius, space, type } from "@/theme/tokens";

/**
 * Insurer company profile from GET /public/institutions/{id} (InstitutionsApi.show): identity,
 * key facts (register entry, insurer code, licence branch, offices, directory check), products
 * on OpesInsure (grouped by line), published product families, contact and offices, then the
 * letterhead legal footer, register source and directory sources.
 * Same structure as the broker profile (src/components/institutions/InstitutionProfile.tsx).
 */
export default function InsurerDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  return (
    <InstitutionScreen id={id} kind="insurer" loadingLabel={t("loadingInsurers")}>
      {(insurer) => <Profile insurer={insurer} />}
    </InstitutionScreen>
  );
}

function Profile({ insurer }: { insurer: Institution }) {
  const { t, language, date } = useTranslation();
  const d = readDirectory(insurer);
  const actions = useContactActions(d);
  const { entry, start } = useStartQuote();
  const groups = productsByLine(insurer.products);
  const productCount = insurer.products?.length ?? 0;
  const families = productFamilyLabels(insurer.product_families, language);
  // Register entry (insurer.canonical_id), insurer code, licence branch, offices, directory check.
  const facts: Fact[] = insurerFacts(insurer, d).map((f) => ({
    label: t(f.label as CopyKey),
    value: "text" in f.value ? f.value.text : "date" in f.value ? date(f.value.date, false) : t(f.value.key as CopyKey, f.value.vars),
    tone: f.tone,
  }));
  const legalName = legalNameLine(insurer.name, insurer.legal_name);
  return (
    <>
      <ProfileHero
        logoUrl={institutionLogo(insurer)}
        initials={insurer.initials}
        name={insurer.name}
        kindLine={[t(insurerKindKey(insurer.branch)), legalName].filter(Boolean).join(" · ")}
        actions={actions}
        chips={
          <>
            {d.verification ? <VerifiedChip label={verificationText(d.verification, language, t)} tone={d.verification.tone} /> : null}
            {insurer.licensed ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
          </>
        }
      />

      <KeyFacts icon={Info} facts={facts} />

      <SectionHeading title={`${t("instProductsOnPlatform")} (${productCount})`} icon={Package} />
      {groups.length ? (
        <ProductGroups
          groups={groups}
          lead={(p, line) => <ProductIcon name={p.name} line={line} />}
          meta={entry ? () => t(entry === "sign-in" ? "instSignInToQuote" : "propGetQuote") : undefined}
          accessibilityLabel={entry ? (p) => t("instQuoteFor", { product: p.name }) : undefined}
          onPress={entry ? (p, line) => start(line, p.name, quoteFocusOf(insurer)) : undefined}
        />
      ) : (
        <EmptyNote text={t("noPlatformProductsBody")} />
      )}

      {families.length ? (
        <>
          <SectionHeading title={t("instProductFamilies")} icon={Layers} />
          <ProfileCard>
            <Text style={styles.note}>{t("instProductFamiliesNote")}</Text>
            <View style={styles.families}>
              {families.map((f) => (
                <Text key={f} style={styles.family}>{f}</Text>
              ))}
            </View>
          </ProfileCard>
        </>
      ) : null}

      <ContactCard d={d} />
      {officesPending(d) ? (
        <>
          <SectionHeading title={t("insurerStatBranches")} icon={MapPin} />
          <EmptyNote text={t("instOfficesPending")} />
        </>
      ) : (
        <OfficesSection branches={d.branches} />
      )}

      <Button label={t("browseAuthorizedBrokers")} icon={Handshake} variant="secondary" onPress={() => router.push("/institutions/brokers")} />

      <ProfileFooter
        licensed={!!insurer.licensed}
        legalFooter={legalFooterLines(insurer.legal_footer)}
        official={!!insurer.is_official_register}
        notes={[]}
        sources={d.sources}
      />
    </>
  );
}

const styles = StyleSheet.create({
  note: { ...type.meta, color: colors.neutral600 },
  families: { flexDirection: "row", flexWrap: "wrap", gap: space.x2 },
  family: {
    ...type.caption,
    color: colors.navy950,
    backgroundColor: colors.neutral100,
    borderRadius: radius.pill,
    paddingHorizontal: space.x3,
    paddingVertical: space.x1,
    maxWidth: "100%",
    overflow: "hidden",
  },
});
