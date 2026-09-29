import React from "react";
import { router, useLocalSearchParams } from "expo-router";
import { Building2, FileText, IdCard } from "lucide-react-native";
import { Button, StatusChip } from "@/components/ui";
import { SectionHeading } from "@/components/design";
import { InstitutionMark, institutionLogo } from "@/components/InstitutionMark";
import {
  ContactCard,
  EmptyNote,
  FeaturedChip,
  InstitutionScreen,
  KeyFacts,
  ProductGroups,
  ProfileCard,
  ProfileFooter,
  ProfileHero,
  ProfileRow,
  useContactActions,
  useLineLabel,
  type Fact,
} from "@/components/institutions/InstitutionProfile";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
import type { Institution } from "@/api/extra";
import { useTranslation } from "@/i18n";
import { isFeaturedBroker, licenceState, productsByLine, readDirectory } from "@/lib/institutions";

/** Today in Douala (UTC+1, no DST) as YYYY-MM-DD, for the licence expiry check. */
const doualaToday = () => new Date(Date.now() + 3_600_000).toISOString().slice(0, 10);

/**
 * Broker profile from GET /public/institutions/{id}: identity (featured, licensed), key facts
 * (register entry, licence and expiry, city), the insurance companies the broker is appointed by
 * and the policies it offers (both from its ACTIVE carrier agreements), contact, then the register
 * source and the MINFI verification note. Same structure as the insurer profile
 * (src/components/institutions/InstitutionProfile.tsx).
 */
export default function BrokerDetail() {
  const { t } = useTranslation();
  const { id } = useLocalSearchParams<{ id: string }>();
  return (
    <InstitutionScreen id={id} kind="broker" loadingLabel={t("loadingBrokers")}>
      {(broker) => <Profile broker={broker} />}
    </InstitutionScreen>
  );
}

function Profile({ broker }: { broker: Institution }) {
  const { t, date } = useTranslation();
  const d = readDirectory(broker);
  const actions = useContactActions(d);
  const lineLabel = useLineLabel();
  const logoFor = useInsurerLogos();
  const insurers = broker.affiliated_insurers ?? [];
  const groups = productsByLine(broker.products);
  const insurerOf = (carrierId?: string | null) => insurers.find((c) => c.id === carrierId);
  const openInsurer = (carrierId: string) => router.push({ pathname: "/institutions/insurer/[id]", params: { id: carrierId } });
  const licence = licenceState(broker.licence_expires_on, doualaToday());
  const facts: Fact[] = [
    ...(broker.canonical_id
      ? [{ label: t("instRegisterEntry"), value: broker.regulator_number ? t("instRegisterEntryValue", { number: broker.regulator_number, id: broker.canonical_id }) : broker.canonical_id }]
      : []),
    ...(broker.licence_number ? [{ label: t("instLicenceNumber"), value: broker.licence_number }] : []),
    ...(licence
      ? [
          licence === "expired"
            ? { label: t("instLicenceValidUntil"), value: t("instLicenceExpired", { date: date(broker.licence_expires_on, false) }), tone: "warning" as const }
            : { label: t("instLicenceValidUntil"), value: date(broker.licence_expires_on, false) },
        ]
      : []),
    ...(broker.city ? [{ label: t("instCity"), value: t("brokerCityCountry", { city: broker.city }) }] : []),
  ];
  return (
    <>
      <ProfileHero
        logoUrl={institutionLogo(broker)}
        initials={broker.initials}
        name={broker.name}
        kindLine={[t("instKindBroker"), broker.city].filter(Boolean).join(" · ")}
        actions={actions}
        chips={
          <>
            {isFeaturedBroker(broker) ? <FeaturedChip label={t("brokerFeatured")} /> : null}
            {broker.licensed && licence !== "expired" ? <StatusChip label={t("licensedStatus")} tone="success" /> : null}
          </>
        }
      />

      <KeyFacts icon={IdCard} facts={facts} />

      {!insurers.length && !groups.length ? (
        <>
          <SectionHeading title={t("brokerInsurers")} icon={Building2} />
          <EmptyNote text={t("instNoOffering")} />
        </>
      ) : (
        <>
          <SectionHeading title={insurers.length ? t("brokerInsurersCount", { count: insurers.length }) : t("brokerInsurers")} icon={Building2} />
          {insurers.length ? (
            <ProfileCard>
              {insurers.map((c, i) => (
                <ProfileRow
                  key={c.id}
                  first={i === 0}
                  lead={<InstitutionMark logoUrl={logoFor(c.id, c.name, c.logo_url)} initials={c.initials} size={40} />}
                  title={c.name}
                  meta={c.lines?.length ? c.lines.map(lineLabel).join(" · ") : null}
                  onPress={() => openInsurer(c.id)}
                />
              ))}
            </ProfileCard>
          ) : (
            <EmptyNote text={t("brokerNoInsurers")} />
          )}
          <SectionHeading title={t("brokerPolicies")} icon={FileText} />
          {groups.length ? (
            <ProductGroups
              groups={groups}
              lead={(p) => (
                <InstitutionMark
                  logoUrl={logoFor(p.carrier_id, p.carrier_name, insurerOf(p.carrier_id)?.logo_url)}
                  initials={insurerOf(p.carrier_id)?.initials ?? (p.carrier_name ?? "").slice(0, 2).toUpperCase()}
                  size={40}
                />
              )}
              meta={(p) => p.carrier_name ?? null}
              onPress={(p) => (p.carrier_id ? openInsurer(p.carrier_id) : undefined)}
            />
          ) : (
            <EmptyNote text={t("brokerNoPolicies")} />
          )}
        </>
      )}

      <ContactCard d={d} />

      <Button label={t("instBrowseInsurers")} icon={Building2} variant="secondary" onPress={() => router.push("/institutions/insurers")} />

      <ProfileFooter
        licensed={!!broker.licensed && licence !== "expired"}
        legalFooter={Array.isArray(broker.legal_footer) ? broker.legal_footer.filter((l) => typeof l === "string" && l.trim()) : []}
        official={!!broker.is_official_register}
        notes={[t("brokerVerifyNote")]}
        sources={d.sources}
      />
    </>
  );
}
