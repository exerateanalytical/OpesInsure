import { useSession } from "@/store/session";
import { copy, CopyKey, Language } from "@/i18n/strings";

export type Vars = Record<string, string | number>;

const fill = (text: string, vars?: Vars) =>
  vars ? text.replace(/\{(\w+)\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m)) : text;

export const translate = (language: Language, key: CopyKey, vars?: Vars) =>
  fill(copy[language][key] ?? copy.en[key], vars);

/** For keys built at runtime (statuses, categories). Falls back to the
 * humanised raw value when the catalogue has no entry. */
export const translateDynamic = (language: Language, key: string, fallback: string) => {
  const table = copy[language] as Record<string, string>;
  const en = copy.en as Record<string, string>;
  return table[key] ?? en[key] ?? fallback.replaceAll("_", " ").toLowerCase().replace(/^\w/, (c) => c.toUpperCase());
};

export function useTranslation() {
  const language = useSession((state) => state.language);
  return {
    language,
    t: (key: CopyKey, vars?: Vars) => translate(language, key, vars),
    td: (key: string, fallback: string) => translateDynamic(language, key, fallback),
    date: (value: string | null | undefined, withTime = false) =>
      formatCameroonDate(value, language, withTime),
  };
}

export const formatXaf = (amount: number, language: Language) =>
  `${new Intl.NumberFormat(language === "fr" ? "fr-CM" : "en-CM").format(amount)} FCFA`;

export const formatCameroonDate = (
  value: string | null | undefined,
  language: Language,
  withTime = true,
) => {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return new Intl.DateTimeFormat(language === "fr" ? "fr-CM" : "en-CM", {
    dateStyle: "medium",
    ...(withTime ? { timeStyle: "short" } : {}),
    timeZone: "Africa/Douala",
  }).format(date);
};
