import React from "react";
import { Text } from "react-native";
import { FileText } from "lucide-react-native";
import { Button, Card, Money, SectionTitle, StatusChip } from "@/components/ui";
import { DetailRow } from "@/components/design";
import { humanize, money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";
import type { CommissionRow } from "./commissionFilters";

/**
 * Record-level commission view (COM-007), shared by agent and broker. Shows
 * every attribution field the server returned; missing fields render "—"
 * rather than being guessed on the device.
 */
export function CommissionDetail({ row, onOpenPolicy }: { row: CommissionRow; onOpenPolicy?: (policyId: string) => void }) {
  const { t, td } = useTranslation();
  const outstanding = row.amount_minor - (row.paid_minor ?? 0);
  return (
    <>
      <Card feature>
        <StatusChip label={td(`commissionStatus_${row.status}`, humanize(row.status))} tone={row.status === "PAID" ? "success" : row.status === "REVERSED" ? "danger" : "info"} />
        <Money amount={row.amount_minor / 100} size="large" />
        <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("pcOutstanding", { amount: money(outstanding) })}</Text>
      </Card>
      <SectionTitle title={t("pcAttribution")} />
      <Card>
        <DetailRow label={t("policies")} value={row.policy_number} />
        <DetailRow label={t("pcCustomer")} value={row.customer_name} />
        <DetailRow label={t("pcInsurer")} value={row.carrier_name} />
        <DetailRow label={t("pcProduct")} value={row.product_name ?? (row.line_code ? td(`line_${row.line_code}`, humanize(row.line_code)) : null)} />
        {row.producer_name ? <DetailRow label={t("pcProducer")} value={row.producer_name} /> : null}
      </Card>
      <SectionTitle title={t("pcCalculation")} />
      <Card>
        <DetailRow label={t("pcPremium")} value={row.premium_minor != null ? money(row.premium_minor) : null} />
        <DetailRow label={t("pcBasis")} value={row.basis ?? row.reason} />
        <DetailRow label={t("pcRate")} value={row.rate_bps != null ? `${(row.rate_bps / 100).toFixed(2)} %` : null} />
        <DetailRow label={t("pcAmount")} value={money(row.amount_minor)} strong />
        <DetailRow label={t("pcPaid")} value={money(row.paid_minor ?? 0)} />
      </Card>
      <SectionTitle title={t("pcSettlement")} />
      <Card>
        <DetailRow label={t("pcBasis_sale")} value={shortDate(row.sale_at)} />
        <DetailRow label={t("pcBasis_issue")} value={shortDate(row.issued_at)} />
        <DetailRow label={t("pcBasis_accrual")} value={shortDate(row.accrued_at)} />
        <DetailRow label={t("pcAvailableOn")} value={shortDate(row.available_at)} />
        <DetailRow label={t("pcBasis_payment")} value={shortDate(row.paid_at)} />
        <DetailRow label={t("pcStatement")} value={row.statement_number} />
      </Card>
      <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("pcAuditNotice")}</Text>
      {row.policy_id && onOpenPolicy ? (
        <Button variant="secondary" icon={FileText} label={t("pcOpenPolicy")} onPress={() => onOpenPolicy(row.policy_id!)} />
      ) : null}
    </>
  );
}
