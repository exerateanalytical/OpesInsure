import { useMemo } from "react";
import { formatCameroonDate, formatXaf } from "@/i18n";
import { useSession } from "@/store/session";

/**
 * Money/date formatting for the purchase and wallet screens, bound to the
 * user's language. Never throws on a missing or malformed value: an
 * absent date renders "—" instead of "Invalid Date".
 */
export function useFormatters() {
  const language = useSession((s) => s.language);
  return useMemo(() => {
    const xaf = (minor: number | null | undefined) =>
      typeof minor === "number" && Number.isFinite(minor)
        ? formatXaf(minor / 100, language)
        : "—";
    const dateTime = (value: string | null | undefined) => {
      if (!value || !Number.isFinite(Date.parse(value))) return "—";
      return formatCameroonDate(value, language);
    };
    const date = (value: string | null | undefined) => {
      if (!value || !Number.isFinite(Date.parse(value))) return "—";
      return new Intl.DateTimeFormat(language === "fr" ? "fr-CM" : "en-CM", {
        dateStyle: "medium",
        timeZone: "Africa/Douala",
      }).format(new Date(value));
    };
    const range = (start?: string | null, end?: string | null) =>
      `${date(start)} — ${date(end)}`;
    return { language, xaf, date, dateTime, range };
  }, [language]);
}
