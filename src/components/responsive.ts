import { useWindowDimensions } from "react-native";
import { space } from "@/theme/tokens";

/** 1 column below 360dp, 2 on phones, 3 at 768dp and wider. */
export function columnsFor(width: number) {
  if (width < 360) return 1;
  if (width >= 768) return 3;
  return 2;
}

/**
 * Responsive grid helper for flex-wrap rows. `inset` is the total horizontal
 * space consumed around the grid (Screen padding is 2 x 20 by default).
 * Returns a pixel width per item so rows never overflow or leave "48%" gaps.
 */
export function useColumns({
  gap = space.x3,
  inset = space.x5 * 2,
  max,
}: { gap?: number; inset?: number; max?: number } = {}) {
  const { width } = useWindowDimensions();
  let columns = columnsFor(width);
  if (max) columns = Math.min(columns, max);
  const available = Math.max(0, width - inset);
  const itemWidth = Math.floor((available - gap * (columns - 1)) / columns);
  return {
    columns,
    width,
    isNarrow: width < 360,
    gap,
    row: { flexDirection: "row", flexWrap: "wrap", gap } as const,
    item: { width: itemWidth } as const,
  };
}
