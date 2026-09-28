import React from "react";
import {
  BadgeCheck,
  BookOpenCheck,
  ContactRound,
  FileSignature,
  PackageCheck,
  ReceiptText,
  RefreshCw,
  Search,
  ShieldAlert,
  Store,
  UserPlus,
  UserRoundPlus,
  Users,
} from "lucide-react-native";
import { PartnerHome } from "@/components/agent";
import type { KpiRoute } from "@/components/portal/KpiGrid";
import { BrokerApi } from "@/api/client";
import { useTranslation } from "@/i18n";

/** DASH-002: each KPI opens its work queue (server still authorizes it). */
const KPI_ROUTES: Record<string, KpiRoute> = {
  Clients: { label: "kpiClients", href: "/broker/clients" },
  "Policies in force": { label: "kpiPoliciesInForce", href: "/broker/policies?f_status=ACTIVE" },
  "Premium written (12m)": { label: "kpiPremiumWritten", href: "/broker/production" },
  "Commission outstanding": { label: "kpiCommissionOutstanding", href: "/broker/commissions?f_lifecycle=unpaid" },
  "Renewals due": { label: "kpiRenewalsDue", href: "/broker/renewals" },
  "Open compliance items": { label: "kpiOpenCompliance", href: "/broker/compliance" },
};

/** Broker staff Home (Commercial Agent kit). Customers, Policies, Claims and Earnings stay on the bottom bar. */
export default function BrokerHome() {
  const { t } = useTranslation();
  return (
    <PartnerHome
      portal="broker"
      title={t("brMobileOffice")}
      subtitle={t("brMobileOfficeSubtitle")}
      load={() => BrokerApi.dashboard()}
      kpiRoutes={KPI_ROUTES}
      quick={[
        { title: t("agAddLead"), subtitle: t("brQuickLeadSub"), icon: UserPlus, href: "/broker/leads/new" },
        { title: t("agRegisterClientTitle"), subtitle: t("brQuickRegisterSub"), icon: UserRoundPlus, href: "/broker/clients/new" },
        { title: t("brReportClaim"), subtitle: t("brQuickReportClaimSub"), icon: ShieldAlert, href: "/broker/claims/new" },
        { title: t("searchTitle"), subtitle: t("searchOpenSubtitle"), icon: Search, href: "/search?role=broker" },
      ]}
      queues={[
        { title: t("brLeads"), subtitle: t("brLeadsSubtitle"), icon: ContactRound, href: "/broker/leads" },
        { title: t("agQuotes"), subtitle: t("brQuotesForClients"), icon: FileSignature, href: "/broker/quotes" },
        { title: t("ptProposals"), subtitle: t("ptProposalsSubtitle"), icon: FileSignature, href: "/broker/proposals" },
        { title: t("notifPref_renewals"), subtitle: t("agPoliciesDueSoon"), icon: RefreshCw, href: "/broker/renewals" },
        { title: t("brComplianceShort"), subtitle: t("brLicencesCases"), icon: BadgeCheck, href: "/broker/compliance" },
      ]}
      more={[
        { title: t("brSales"), subtitle: t("brProductionRegister"), icon: BookOpenCheck, href: "/broker/production" },
        { title: t("brReceivables"), subtitle: t("brAmountsDue"), icon: ReceiptText, href: "/broker/receivables" },
        { title: t("catTitle"), subtitle: t("catMenuSubtitle"), icon: PackageCheck, href: "/broker/catalogue" },
        { title: t("brPublications"), subtitle: t("brMarketplaceListings"), icon: Store, href: "/broker/publications" },
        { title: t("brStaff"), subtitle: t("brTeamInvitations"), icon: Users, href: "/broker/staff" },
      ]}
    />
  );
}
