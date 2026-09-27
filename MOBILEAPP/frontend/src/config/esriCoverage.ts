/**
 * How deep ESRI's tile services genuinely go over a given place.
 *
 * The LCP/NAP map draws its deep zoom from ESRI's aerial imagery and the road
 * overlay that pairs with it. Both advertise 23 levels, and past the data they
 * actually hold each answers 200 with "Map data not yet available" drawn into
 * the tile image. Nothing errors, so the only symptom is that text tiled across
 * the map as soon as someone zooms in.
 *
 * The obvious guard — one maximumNativeZ constant per layer — is what used to
 * be here, and it cannot work: coverage is regional and does not track how
 * built-up a place is. World_Imagery has 19 over Manila, 18 over rural Isabela,
 * and only 17 over Sulu and Palawan. Any single number is too deep for
 * somewhere in the country.
 *
 * So the depth is asked for, per area, and the answer caps the layer.
 * Mirrors AKM2_0/frontend/src/config/osmMap.ts, which does the same for the web
 * map against the same services.
 */

const ARCGIS = 'https://services.arcgisonline.com/ArcGIS/rest/services';

/** The aerial tier: photography, and the road and place-name overlay over it. */
export const AERIAL_SERVICE = `${ARCGIS}/World_Imagery/MapServer`;
export const AERIAL_LABELS_SERVICE = `${ARCGIS}/Reference/World_Transportation/MapServer`;

/** As far as the map lets anyone zoom. */
export const MAX_ZOOM = 19;

/**
 * The shallowest depth the aerial tier is assumed to have anywhere in the
 * country, used until a lookup says otherwise. Deliberately pessimistic: it is
 * what the first paint of a new area draws with, so it has to be a zoom that is
 * there, not one that usually is.
 */
export const AERIAL_FLOOR_ZOOM = 17;

/**
 * Nothing sensible is served shallower than this, so a lookup that keeps
 * missing stops here rather than walking to the top of the world.
 */
const COVERAGE_FLOOR_ZOOM = 12;

/** Slippy-map tile column for a longitude at a zoom. */
const tileX = (lon: number, z: number) => Math.floor(((lon + 180) / 360) * 2 ** z);

/** Slippy-map tile row for a latitude at a zoom. */
const tileY = (lat: number, z: number) => {
  const rad = (lat * Math.PI) / 180;
  return Math.floor(((1 - Math.log(Math.tan(rad) + 1 / Math.cos(rad)) / Math.PI) / 2) * 2 ** z);
};

/**
 * One resolved area, keyed by the z10 tile it falls in — about 40km across,
 * coarse enough that panning around a town reuses the answer and fine enough
 * not to span a coverage boundary. Kept for the life of the process; coverage
 * does not change while someone is looking at it.
 */
const coverageCache = new Map<string, Promise<number>>();

/**
 * Whether a service has real tiles across a block, by asking it.
 *
 * ESRI's tilemap endpoint answers with a 1 or a 0 per tile, which is the only
 * honest way to tell a real tile from the placeholder: the tile endpoint itself
 * returns 200 either way, so there is nothing for the map to report as a
 * failure and nothing to read off the image without decoding it first.
 *
 * A block rather than the single centre tile, because a view straddling a
 * coverage edge would otherwise be told the whole screen is covered and show
 * the placeholder across half of it. Four tiles square is about a viewport.
 */
const blockIsCovered = async (service: string, z: number, lat: number, lon: number) => {
  const span = 4;
  const y = Math.max(0, tileY(lat, z) - span / 2);
  const x = Math.max(0, tileX(lon, z) - span / 2);

  const response = await fetch(`${service}/tilemap/${z}/${y}/${x}/${span}/${span}`);
  if (!response.ok) return false;

  const body = await response.json();
  return (
    Array.isArray(body.data) &&
    body.data.length > 0 &&
    body.data.every((present: number) => present === 1)
  );
};

/**
 * The deepest zoom a service genuinely has over a point.
 *
 * Walks down from the deepest zoom the map can reach until the service admits
 * to the tiles, so each place keeps every level it really has. Capping the
 * country at its thinnest coverage instead would have been the simpler fix, but
 * it blurs Manila — where the pins are densest — to spare Palawan.
 *
 * The in-flight promise is what gets cached, not its result, so the two layers
 * and the repeated camera moves that ask about one area at the same moment
 * share a single round trip.
 */
export const nativeZoomAt = (service: string, lat: number, lon: number): Promise<number> => {
  const key = `${service}@${tileY(lat, 10)}/${tileX(lon, 10)}`;
  const cached = coverageCache.get(key);
  if (cached) return cached;

  const resolved = (async () => {
    for (let z = MAX_ZOOM; z > COVERAGE_FLOOR_ZOOM; z--) {
      try {
        if (await blockIsCovered(service, z, lat, lon)) return z;
      } catch {
        // The lookup is an optimisation, not a requirement. On a flaky
        // connection — which is the normal case for a technician in the field —
        // leave the layer on the floor it started at. A slightly soft map is
        // the failure anyone would pick over the placeholder.
        return AERIAL_FLOOR_ZOOM;
      }
    }
    return COVERAGE_FLOOR_ZOOM;
  })();

  coverageCache.set(key, resolved);
  return resolved;
};
