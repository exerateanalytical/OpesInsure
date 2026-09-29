import React, { ReactNode, useMemo } from "react";
import { Text } from "react-native";
import { Columns3 } from "lucide-react-native";
import { SectionHeading } from "@/components/design";
import { Card } from "@/components/ui";
import { purchaseStyles as ps } from "@/components/purchase/PurchaseUi";
import { CompareHeader, CompareTable } from "@/components/offers/CompareTable";
import { useInsurerLogos } from "@/components/offers/useInsurerLogo";
import type { QuoteOffer } from "@/api/client";
import { comparisonTable, type TableColumn } from "@/lib/offerComparison";
import type { QuoteComparison } from "@/lib/quoteWorkflow";
import { carrierLogo } from "@/lib/renewal";
import { useFormatters } from "@/hooks/useFormatters";
import { useTranslation } from "@/i18n";

/**
 * The comparison card used by the customer comparison screen and the partner (read-only) view:
 * the same header, rows, labels and highlighting everywhere. `choose(column, index)` renders the
 * one choose button under each offer column (omitted for partners).
 */
export function QuoteComparisonView({
  comparison,
  offers,
  order,
  now,
  status,
  choose,
  subtitle,
  coverStart,
}: {
  comparison: QuoteComparison;
  /** The quote's rated offers when loaded (live status, logos, cover period, optional rows). */
  offers?: QuoteOffer[];
  order?: string[];
  now: number;
  status?: (column: TableColumn) => CompareHeader["status"];
  choose?: (column: TableColumn, index: number) => ReactNode;
  subtitle?: string;
  /** Requested cover start (quote risk facts), shown as a row when known. */
  coverStart?: string | null;
}) {
  const { t } = useTranslation();
  const f = useFormatters();
  const logoFor = useInsurerLogos();
  const table = useMemo(
    () => comparisonTable(comparison, { language: f.language, now, order, offers, formatDate: (iso) => (/^\d{4}-\d{2}-\d{2}$/.test(iso) ? f.date(iso) : f.dateTime(iso)), coverStart }),
    [comparison, f, now, order, offers, coverStart],
  );
  const columns: CompareHeader[] = table.columns.map((c) => {
    const rated = offers?.find((o) => o.id === c.offerId);
    return { ...c, logoUrl: logoFor(c.carrierId, c.name, c.logoUrl ?? carrierLogo(rated)), status: status?.(c) ?? null };
  });
  return (
    <Card>
      <SectionHeading icon={Columns3} title={t("compare")} />
      {subtitle ? <Text style={ps.meta}>{subtitle}</Text> : null}
      <CompareTable
        columns={columns}
        rows={table.rows}
        money={f.xaf}
        headerLabel={t("ofInsurer")}
        footer={choose ? (i) => (table.columns[i] ? choose(table.columns[i]!, i) : null) : undefined}
      />
    </Card>
  );
}
