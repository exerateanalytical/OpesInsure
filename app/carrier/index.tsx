import React from "react";
import {
  ClipboardCheck,
  FileCheck2,
  FileSignature,
  FileSpreadsheet,
  FileText,
  Handshake,
  Inbox,
  Landmark,
  Package,
  Search,
  ShieldAlert,
} from "lucide-react-native";
import { PartnerHome } from "@/components/agent";
import type { KpiRoute } from "@/components/portal/KpiGrid";
import { useWorkspacePermissions } from "@/components/carrier/CarrierGate";
import { CARRIER_ROUTE_MODULE, canUseCarrierModule } from "@/lib/carrierAccess";
import { useCapabilities } from "@/store/capabilities";
import { CarrierApi } from "@/api/client";
import { useTranslation } from "@/i18n";

/** DASH-002: each KPI opens its work queue (server still authorizes it). */
const KPI_ROUTES: Record<string, KpiRoute> = {
  "Underwriting referrals": { label: "kpiReferrals", href: "/carrier/referrals" },
  "Issuance queue": { label: "kpiIssuanceQueue", href: "/carrier/issuance" },
  "Open claims": { label: "kpiOpenClaims", href: "/carrier/claims" },
  "Policies in force": { label: "kpiPoliciesInForce", href: "/carrier/policies" },
  "Settlements (net)": { label: "kpiSettlementsNet", href: "/carrier/settlements" },
};

/** Insurer staff Home (Commercial Agent kit). Underwriting, Claims, Policies and Payments stay on the bottom bar. */
export default function CarrierHome() {
  const { t } = useTranslation();
  // NAV-003: show only modules this workspace is granted (the server still
  // enforces every route; CarrierGate covers direct links).
  const perms = useWorkspacePermissions();
  const caps = useCapabilities((s) => s.caps);
  const allowed = (href: string) => {
    const mod = CARRIER_ROUTE_MODULE[href.split("?")[0]!.split("/")[2] ?? ""];
    return !mod || canUseCarrierModule(perms, mod, caps);
  };
  return (
    <PartnerHome
      portal="carrier"
      title={t("caOperations")}
      subtitle={t("caOperationsSubtitle")}
      load={() => CarrierApi.dashboard()}
      kpiRoutes={KPI_ROUTES}
      allowed={allowed}
      quick={[
        { title: t("portalTab_Underwriting"), subtitle: t("caReferralsToDecide"), icon: ClipboardCheck, href: "/carrier/referrals" },
        { title: t("caIssuance"), subtitle: t("caApproveRejectIssuance"), icon: FileCheck2, href: "/carrier/issuance" },
        { title: t("cqrTitle"), subtitle: t("cqrMenuSubtitle"), icon: Inbox, href: "/carrier/quote-requests" },
        { title: t("searchTitle"), subtitle: t("searchOpenSubtitle"), icon: Search, href: "/search?role=carrier" },
      ]}
      queues={[
        { title: t("caQuotesProposals"), subtitle: t("caProposalsForProducts"), icon: FileSignature, href: "/carrier/proposals" },
        { title: t("claims"), subtitle: t("caAcknowledgeDecide"), icon: ShieldAlert, href: "/carrier/claims" },
        { title: t("policies"), subtitle: t("caPoliciesInForce"), icon: FileText, href: "/carrier/policies" },
        { title: t("caSettlements"), subtitle: t("caPremiumSettlements"), icon: Landmark, href: "/carrier/settlements" },
      ]}
      more={[
        { title: t("caProducts"), subtitle: t("caProductsTariffs"), icon: Package, href: "/carrier/products" },
        { title: t("caDistributionPartners"), subtitle: t("caBrokersAgentsSelling"), icon: Handshake, href: "/carrier/partners" },
        { title: t("caBordereaux"), subtitle: t("caBrokerBordereaux"), icon: FileSpreadsheet, href: "/carrier/bordereaux" },
      ]}
    />
  );
}
