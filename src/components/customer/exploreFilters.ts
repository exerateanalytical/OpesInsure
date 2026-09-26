import { ArrowDownAZ, Star } from "lucide-react-native";
import type { Institution } from "@/api/extra";
import { CATEGORIES } from "@/components/customer/categories";
import type { FilterSection, FilterValues } from "@/components/customer/FiltersSheet";
import { institutionLogo } from "@/components/InstitutionMark";
import type { CopyKey } from "@/i18n/strings";

/** Categories a marketplace filter can pick (the "more" tile is navigation only). */
export const FILTER_CATEGORIES = CATEGORIES.filter((c) => c.id !== "more");

/** Does this provider publish a product in the category (by product line code)? */
export const offersCategory = (p: Institution, catId: string) => {
  const cat = CATEGORIES.find((c) => c.id === catId);
  return !!cat && (p.products ?? []).some((x) => cat.lines.some((l) => (x.line_code ?? "").toUpperCase().includes(l)));
};

/** Sections for the marketplace filter sheet (Home + Explore): category, insurer, sort. */
export function exploreSections(providers: Institution[], t: (k: CopyKey) => string): FilterSection[] {
  const insurers = providers.filter((p) => p.type === "insurer" && (p.products?.length ?? 0) > 0);
  return [
    { key: "cat", title: t("filterCategory"), subtitle: t("filterCategoryBody"), options: FILTER_CATEGORIES.map((c) => ({ value: c.id, label: t(c.label), icon: c.icon })) },
    { key: "prov", title: t("filterProvider"), subtitle: t("filterProviderBody"), options: insurers.map((p) => ({ value: p.id, label: p.short_name ?? p.name, logoUrl: institutionLogo(p), initials: p.initials })) },
    {
      key: "sort",
      single: true,
      title: t("filterSort"),
      subtitle: t("filterSortBody"),
      options: [
        { value: "best", label: t("sortBestMatch"), icon: Star },
        { value: "name", label: t("sortNameAZ"), icon: ArrowDownAZ },
      ],
    },
  ];
}

/** Providers left after the sheet's category / insurer filters, in the chosen order. */
export function applyExploreFilters(providers: Institution[], f: FilterValues): Institution[] {
  const cats = f.cat ?? [];
  const provs = f.prov ?? [];
  const out = providers.filter(
    (p) => (!cats.length || cats.some((c) => offersCategory(p, c))) && (!provs.length || provs.includes(p.id)),
  );
  return (f.sort?.[0] ?? "best") === "name"
    ? [...out].sort((a, b) => a.name.localeCompare(b.name))
    : [...out].sort((a, b) => (b.products?.length ?? 0) - (a.products?.length ?? 0));
}

/** "motor,travel" route param ⇄ string[]. */
export const listParam = (v: unknown) => (typeof v === "string" && v ? v.split(",").filter(Boolean) : []);
