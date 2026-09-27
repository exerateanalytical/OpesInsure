import test from "node:test";
import assert from "node:assert/strict";
// Dependency-free TypeScript, loaded with Node's type stripping.
import { findPlace, locationGroups, matchPlace, placeKey, placeSummary } from "../src/lib/locationMatch.ts";

const v = (code, en, fr, parent, aliases) => ({ code, label: { en, fr }, parent, aliases });
const regions = [v("LITTORAL", "Littoral", "Littoral"), v("NORD_OUEST", "North-West", "Nord-Ouest", undefined, ["NW"]), v("CENTRE", "Centre", "Centre"), v("EXTREME_NORD", "Far North", "Extrême-Nord")];
const departments = [v("WOURI", "Wouri", "Wouri", "LITTORAL"), v("MEZAM", "Mezam", "Mezam", "NORD_OUEST"), v("MFOUNDI", "Mfoundi", "Mfoundi", "CENTRE")];
const cities = [v("DOUALA", "Douala", "Douala", "WOURI"), v("BAMENDA", "Bamenda", "Bamenda", "MEZAM"), v("YAOUNDE", "Yaoundé", "Yaoundé", "MFOUNDI"), v("OTHER", "Other", "Autre")];

test("region names from geocoders map to Cameroon region codes", () => {
  assert.equal(placeKey("Région du Nord-Ouest"), "nord ouest");
  assert.equal(placeKey("North West Region"), "nord ouest");
  for (const [name, code] of [["Littoral", "LITTORAL"], ["Littoral Region", "LITTORAL"], ["North-West", "NORD_OUEST"], ["Northwest", "NORD_OUEST"], ["Nord-Ouest", "NORD_OUEST"], ["Centre", "CENTRE"], ["Extreme North", "EXTREME_NORD"], ["Far North Region", "EXTREME_NORD"]])
    assert.equal(findPlace(regions, name)?.code, code, name);
  assert.equal(findPlace(regions, "Lagos"), undefined);
});

test("town, department and region resolve together", () => {
  const m = matchPlace({ city: "Douala", subregion: "Wouri", region: "Littoral" }, { regions, departments, cities });
  assert.deepEqual([m.region?.code, m.department?.code, m.city?.code], ["LITTORAL", "WOURI", "DOUALA"]);
  assert.equal(placeSummary(m, "en"), "Douala, Littoral");
  const y = matchPlace({ city: "Yaounde", region: "Centre Region" }, { regions, departments, cities });
  assert.equal(y.city?.code, "YAOUNDE");
  assert.equal(placeSummary(y, "fr"), "Yaoundé, Centre");
  // Region derived from the town when the geocoder has none; unknown towns keep their text.
  assert.equal(matchPlace({ city: "Bamenda" }, { regions, departments, cities }).region?.code, "NORD_OUEST");
  const u = matchPlace({ city: "Kribi", region: "South" }, { regions, departments, cities });
  assert.equal(u.city, undefined);
  assert.equal(placeSummary(u, "en"), "Kribi, South");
});

test("region groups are found by prefix in server forms", () => {
  const fields = [
    { key: "incident_region", master: { list: "cameroon_region" } },
    { key: "incident_department", master: { list: "cameroon_department" } },
    { key: "incident_city", master: { list: "city" } },
    { key: "region", master: { list: "cameroon_region" } },
    { key: "city", master: { list: "city" } },
  ];
  assert.deepEqual(locationGroups(fields), [
    { region: "incident_region", department: "incident_department", city: "incident_city" },
    { region: "region", department: undefined, city: "city" },
  ]);
});
