import L from 'leaflet';

/**
 * The OpenStreetMap setup the LCP/NAP screens share.
 *
 * Both the map page and the add-location form draw the same country on the same
 * basemaps with the same pins, so the tiles, bounds, icons and geocoder live
 * here rather than being written out twice and drifting.
 *
 * Nothing here needs an API key. That is the point: the screens used to load
 * the Google Maps JS API, which meant a billable key provisioned, restricted
 * and rotated for two screens that draw dots on a map of one country.
 */

/**
 * Light and dark basemaps: ESRI's Canvas services, which need no API key.
 *
 * Two layers per theme, not one. Canvas keeps geography and labels in separate
 * services, and drawing the base without the reference is what leaves a map
 * with no place names; drawing both is what gives clean labels without the
 * commercial points of interest a general-purpose basemap carries.
 *
 * CARTO was used here first and had to be replaced: its basemaps now stamp
 * "API KEY REQUIRED" into the tile image itself. The request still succeeds, so
 * there is nothing to catch in code — the watermark just appears on the map.
 *
 * No {s} subdomain placeholder: these services are served from one host, and
 * leaving it in produces requests to hosts that do not exist.
 */
const ARCGIS = 'https://services.arcgisonline.com/ArcGIS/rest/services';
const ESRI = `${ARCGIS}/Canvas`;

export const BASEMAPS = {
  light: {
    base: `${ESRI}/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}`,
    reference: `${ESRI}/World_Light_Gray_Reference/MapServer/tile/{z}/{y}/{x}`,
  },
  dark: {
    base: `${ESRI}/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}`,
    reference: `${ESRI}/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}`,
  },
} as const;

/** Aerial photography, and the road and place-name overlay that pairs with it. */
const AERIAL = `${ARCGIS}/World_Imagery/MapServer/tile/{z}/{y}/{x}`;
const AERIAL_LABELS = `${ARCGIS}/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}`;

/** The services behind those tile templates, for the coverage lookup below. */
const AERIAL_SERVICE = `${ARCGIS}/World_Imagery/MapServer`;
const AERIAL_LABELS_SERVICE = `${ARCGIS}/Reference/World_Transportation/MapServer`;

/**
 * How deep each service actually has tiles over the Philippines.
 *
 * Every one of these advertises 23 levels, and past its real data each answers
 * 200 with "Map data not yet available" drawn into the tile. Nothing errors, so
 * the only symptom is that text tiled across the map.
 *
 * Canvas is the simple one — it stops at 16 nationwide, so a plain cap is all
 * it needs. The aerial and its label overlay are not: their depth is regional
 * and does not track how built-up a place is. World_Imagery has 19 over Manila,
 * 18 over rural Isabela, and only 17 over Sulu and Palawan. That is why the
 * single hardcoded number this used to carry could not hold — wherever it
 * overshot the real coverage, the placeholder came back. Those two are resolved
 * per area instead, against the service's own tilemap; see nativeZoomAt.
 */
const CANVAS_MAX_ZOOM = 16;

/**
 * The shallowest depth the aerial tier is assumed to have anywhere in the
 * country, used until a lookup says otherwise. Deliberately pessimistic: it is
 * what the first paint of a new area draws with, so it has to be a zoom that is
 * there, not one that usually is.
 */
const AERIAL_FLOOR_ZOOM = 17;

/** As far as the map lets anyone zoom. */
export const MAX_ZOOM = 19;

/**
 * Nothing sensible is served shallower than this, so a lookup that keeps
 * missing stops here rather than walking to the top of the world.
 */
const COVERAGE_FLOOR_ZOOM = 12;

/** Credit required by the tile service. */
export const TILE_ATTRIBUTION =
  'Tiles &copy; <a href="https://www.esri.com/">Esri</a> &mdash; Esri, HERE, Garmin, ' +
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors, and the GIS user community';

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
 * not to span a coverage boundary. Kept for the life of the page; coverage does
 * not change while someone is looking at it.
 */
const coverageCache = new Map<string, Promise<number>>();

/**
 * Whether a service has real tiles across a block, by asking it.
 *
 * ESRI's tilemap endpoint answers with a 1 or a 0 per tile, which is the only
 * honest way to tell a real tile from the placeholder: the tile endpoint itself
 * returns 200 either way, so there is nothing for Leaflet's tileerror to catch
 * and nothing to read off the image without drawing it to a canvas first.
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
const nativeZoomAt = (service: string, lat: number, lon: number): Promise<number> => {
  const key = `${service}@${tileY(lat, 10)}/${tileX(lon, 10)}`;
  const cached = coverageCache.get(key);
  if (cached) return cached;

  const resolved = (async () => {
    for (let z = MAX_ZOOM; z > COVERAGE_FLOOR_ZOOM; z--) {
      try {
        if (await blockIsCovered(service, z, lat, lon)) return z;
      } catch {
        // The lookup is an optimisation, not a requirement. If it cannot be
        // reached, leave the layer on the floor it started at — a slightly soft
        // map is the failure anyone would pick over the placeholder.
        return AERIAL_FLOOR_ZOOM;
      }
    }
    return COVERAGE_FLOOR_ZOOM;
  })();

  coverageCache.set(key, resolved);
  return resolved;
};

/**
 * A tile layer that only ever asks for tiles the service has.
 *
 * It starts at the pessimistic floor, raises maxNativeZoom to whatever the area
 * on screen actually holds, and lowers it again on the way into thinner
 * coverage. Leaflet stretches the last real tile over the levels past that, so
 * the deep end of the map goes soft instead of going blank.
 */
class CoverageCappedTileLayer extends L.TileLayer {
  private readonly service: string;

  /** Guards against a slow lookup for an area the map has already left. */
  private probe = 0;

  constructor(url: string, service: string, options: L.TileLayerOptions) {
    super(url, { ...options, maxNativeZoom: AERIAL_FLOOR_ZOOM });
    this.service = service;
  }

  onAdd(map: L.Map) {
    super.onAdd(map);
    map.on('moveend', this.syncCoverage, this);
    this.syncCoverage();
    return this;
  }

  onRemove(map: L.Map) {
    map.off('moveend', this.syncCoverage, this);
    super.onRemove(map);
    return this;
  }

  private syncCoverage() {
    const map = this._map;
    if (!map) return;

    const centre = map.getCenter();
    const probe = ++this.probe;

    void nativeZoomAt(this.service, centre.lat, centre.lng).then((native) => {
      // A newer lookup has since started, or the layer was removed while this
      // one was in flight.
      if (probe !== this.probe || !this._map) return;

      const capped = Math.min(native, MAX_ZOOM);
      if (this.options.maxNativeZoom === capped) return;

      this.options.maxNativeZoom = capped;
      this.redraw();
    });
  }
}

/** Takes the glare off the aerial tiles so they sit in a dark page. */
const DARK_AERIAL_CLASS = 'lcpnap-aerial-dark';

const ensureDarkAerialStyle = () => {
  const id = 'lcpnap-aerial-dark-style';
  if (document.getElementById(id)) return;

  const style = document.createElement('style');
  style.id = id;
  // On the layer container, so it dims the photography without touching the
  // markers — those live in a different Leaflet pane.
  style.textContent = `.${DARK_AERIAL_CLASS} { filter: brightness(0.72) saturate(0.85); }`;
  document.head.appendChild(style);
};

/**
 * The basemap for a theme, as one removable unit.
 *
 * A LayerGroup rather than loose layers so the theme switch stays a single add
 * and a single remove — miss one and the old labels stay printed over the new
 * map.
 *
 * Two tiers, because no single free service covers the whole range. The grey
 * canvas is the better overview and is what the country-wide view shows, but it
 * has nothing below z16; from z17 the aerial takes over, which is also the more
 * useful thing to be looking at when the job is placing a pole. The canvas is
 * capped outright at the zoom it has; the aerial pair works out per area how
 * deep it can go, so a tile the service does not hold is never requested and
 * that placeholder cannot appear.
 */
export const createBasemap = (isDark: boolean): L.LayerGroup => {
  const theme = isDark ? BASEMAPS.dark : BASEMAPS.light;
  if (isDark) ensureDarkAerialStyle();

  return L.layerGroup([
    L.tileLayer(theme.base, { attribution: TILE_ATTRIBUTION, maxZoom: CANVAS_MAX_ZOOM }),
    L.tileLayer(theme.reference, { maxZoom: CANVAS_MAX_ZOOM }),
    new CoverageCappedTileLayer(AERIAL, AERIAL_SERVICE, {
      minZoom: CANVAS_MAX_ZOOM + 1,
      maxZoom: MAX_ZOOM,
      className: isDark ? DARK_AERIAL_CLASS : '',
    }),
    new CoverageCappedTileLayer(AERIAL_LABELS, AERIAL_LABELS_SERVICE, {
      minZoom: CANVAS_MAX_ZOOM + 1,
      maxZoom: MAX_ZOOM,
    }),
  ]);
};

/**
 * The Philippines, as the old Google `restriction.latLngBounds` described it.
 * Applied as maxBounds so the reader cannot pan away from the only country the
 * data covers.
 */
export const PH_BOUNDS = L.latLngBounds([4.3, 114.0], [21.5, 127.5]);

/**
 * A map pin, drawn inline.
 *
 * Leaflet's default marker resolves its images relative to the stylesheet,
 * which a bundler rewrites — the well-known result is markers that render as
 * broken images. An SVG in the markup has nothing to resolve.
 */
export const pinIcon = (fill: string) =>
  L.divIcon({
    className: '',
    html: `<svg width="26" height="34" viewBox="0 0 26 34" xmlns="http://www.w3.org/2000/svg">
      <path d="M13 0C5.82 0 0 5.82 0 13c0 9.2 11.6 20.1 12.1 20.6a1.3 1.3 0 0 0 1.8 0C14.4 33.1 26 22.2 26 13 26 5.82 20.18 0 13 0z" fill="${fill}"/>
      <circle cx="13" cy="13" r="5" fill="#ffffff"/>
    </svg>`,
    iconSize: [26, 34],
    // Anchored at the point of the pin rather than its middle, so it marks the
    // spot it is standing on.
    iconAnchor: [13, 34],
    tooltipAnchor: [0, -34],
  });

/** A saved or selected location. */
export const selectedPinIcon = pinIcon('#0d9488');

/** A location being placed but not yet confirmed — never the same colour. */
export const provisionalPinIcon = pinIcon('#f59e0b');

/** One address the geocoder matched. */
export interface AddressSuggestion {
  /** Stable enough to key a list: Photon has no place id of its own. */
  id: string;
  description: string;
  lat: number;
  lon: number;
}

/**
 * Address search on Photon — OpenStreetMap data, free, no API key.
 *
 * Google's autocomplete returned a prediction that then needed a second
 * getDetails call to resolve into coordinates. A Photon feature already carries
 * its geometry, so choosing a suggestion needs no further request.
 */
export const photonSearch = async (query: string): Promise<AddressSuggestion[]> => {
  try {
    // bbox: min_lon,min_lat,max_lon,max_lat — the same country restriction the
    // Google call made with componentRestrictions: { country: 'ph' }.
    const response = await fetch(
      `https://photon.komoot.io/api/?q=${encodeURIComponent(query)}&limit=5&bbox=114.1,4.4,126.6,21.1`
    );
    if (!response.ok) return [];

    const body = await response.json();
    return (body?.features ?? [])
      .filter((f: any) => Array.isArray(f?.geometry?.coordinates))
      .map((f: any, index: number) => {
        const p = f.properties ?? {};
        const description = [p.name, p.street, p.city, p.state, p.country]
          .filter(Boolean)
          .join(', ');
        const [lon, lat] = f.geometry.coordinates;
        return {
          id: `${p.osm_type ?? 'f'}:${p.osm_id ?? index}:${index}`,
          description: description || p.name || 'Unnamed place',
          lat,
          lon,
        };
      });
  } catch (err) {
    console.error('Photon geocoding error:', err);
    return [];
  }
};

/**
 * Is the app on its dark theme right now?
 *
 * Read from the DOM rather than from React state for the one call that happens
 * outside render — creating the map — where a closed-over value would be
 * whatever it was when the component mounted.
 */
export const isDarkThemeActive = (): boolean =>
  document.documentElement.classList.contains('dark') || localStorage.getItem('theme') === 'dark';
