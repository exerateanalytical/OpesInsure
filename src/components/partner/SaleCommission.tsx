import React from "react";
import { Text, View } from "react-native";
import { CircleDollarSign } from "lucide-react-native";
import { Button, Card, SectionTitle, StatusChip } from "@/components/ui";
import { DetailRow } from "@/components/design";
import { money, shortDate } from "@/api/partner";
import { useTranslation } from "@/i18n";
import { colors, type } from "@/theme/tokens";
import { saleCommission, type CommissionRow, type SaleCommission, type Stage } from "./commissionFilters";

type Sale = Parameters<typeof saleCommission>[1];
type Tr = ReturnType<typeof useTranslation>["t"];

const TONE: Record<Stage, "neutral" | "success" | "warning" | "info" | "danger"> = {
  estimated: "neutral",
  accrued: "info",
  payable: "warning",
  paid: "success",
  on_hold: "danger",
  reversed: "danger",
};

/** "When" line: paid date, else the payable-from date, else nothing (never guessed). */
function when(c: SaleCommission, t: Tr) {
  if (c.stage === "paid" && c.paid_at) return t("pcSalePaidOn", { date: shortDate(c.paid_at) });
  if (c.payable_from && c.stage !== "reversed") return t("pcSalePayableFrom", { date: shortDate(c.payable_from) });
  return null;
}

/** One-line commission for a list row: "Commission 6 572 FCFA · Payable". Null ledger (not loaded / no access) -> no line. */
export function saleCommissionLine(rows: CommissionRow[] | null | undefined, sale: Sale, t: Tr): string | null {
  if (!rows) return null;
  const c = saleCommission(rows, sale);
  if (!c) return t("pcSalePendingShort");
  return t("pcSaleShort", { amount: money(c.amount_minor), stage: t(`pcStage_${c.stage}` as const) });
}

/**
 * "You earned" card for one sale (policy / proposal detail, issuance and
 * after-payment states). Figures are the server's accruals joined by sale id;
 * "Estimated" only when the server marks the row ESTIMATED; otherwise
 * "Pending calculation" — nothing is computed on the device.
 */
export function SaleCommissionCard({
  rows,
  sale,
  onOpen,
  title,
}: {
  /** Ledger rows (null while loading / unavailable: the card is hidden). */
  rows: CommissionRow[] | null | undefined;
  sale: Sale;
  /** Opens the accrual detail (first row id). */
  onOpen?: (accrualId: string) => void;
  title?: string;
}) {
  const { t } = useTranslation();
  if (!rows) return null;
  const c = saleCommission(rows, sale);
  return (
    <>
      <SectionTitle title={title ?? (c?.estimated ? t("pcSaleEstimated") : t("pcSaleEarned"))} />
      <Card>
        {!c ? (
          <View style={{ gap: 6 }}>
            <StatusChip label={t("pcSalePending")} tone="neutral" />
            <Text style={{ ...type.meta, color: colors.neutral600 }}>{t("pcSalePendingBody")}</Text>
          </View>
        ) : (
          <>
            <StatusChip label={t(`pcStage_${c.stage}` as const)} tone={TONE[c.stage]} />
            <Text accessibilityRole="header" style={{ ...type.pageTitle, color: colors.navy950 }}>{money(c.amount_minor)}</Text>
            {when(c, t) ? <Text style={{ ...type.meta, color: colors.neutral600 }}>{when(c, t)}</Text> : null}
            <DetailRow
              label={t("pcRate")}
              value={
                c.rate_bps != null
                  ? `${(c.rate_bps / 100).toFixed(2)} %`
                  : c.effective_rate_bps != null
                    ? t("pcSaleEffRate", { rate: (c.effective_rate_bps / 100).toFixed(2) })
                    : null
              }
            />
            <DetailRow label={t("pcPaid")} value={money(c.paid_minor)} />
            <DetailRow label={t("pcUnpaid")} value={money(c.owed_minor)} />
            {c.count > 1 ? <DetailRow label={t("pcSaleRows")} value={String(c.count)} /> : null}
            {onOpen ? <Button variant="secondary" size="small" icon={CircleDollarSign} label={t("pcSaleOpen")} onPress={() => onOpen(c.ids[0]!)} /> : null}
          </>
        )}
      </Card>
    </>
  );
}
