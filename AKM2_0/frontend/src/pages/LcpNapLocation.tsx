import React, { useState, useEffect, useRef } from 'react';
import { Check, ChevronDown, ChevronRight, Layers, Loader2, MapPin, Search, X, ChevronLeft } from 'lucide-react';
import AddLcpNapLocationModal from '../modals/AddLcpNapLocationModal';
import LcpNapLocationDetails from '../components/LcpNapLocationDetails';
import { GOOGLE_MAPS_API_KEY } from '../config/maps';
import { settingsColorPaletteService, ColorPalette } from '../services/settingsColorPaletteService';
import { getAllLCPNAPsForMap, clearLCPNAPMapCache } from '../services/lcpnapService';
import apiClient from '../config/api';

interface LocationMarker {
  id: number;
  lcpnap_name: string;
  lcp_name: string;
  nap_name: string;
  coordinates: string;
  latitude: number;
  longitude: number;
  street?: string;
  city?: string;
  region?: string;
  barangay?: string;
  port_total?: number;
  reading_image_url?: string;
  image1_url?: string;
  image2_url?: string;
  modified_by?: string;
  modified_date?: string;
  active_sessions?: number;
  restricted_sessions?: number;
  offline_sessions?: number;
  disconnected_sessions?: number;
  not_found_sessions?: number;
  total_technical_details?: number;
  organization_id?: number | null;
}

interface LcpNapGroup {
  lcp_name: string;
  locations: LocationMarker[];
  count: number;
}

interface LcpNapItem {
  id: number;
  name: string;
  count: number;
}

interface ApiResponse<T = any> {
  success: boolean;
  data?: T;
  message?: string;
}

/**
 * Maximum pins an LP may contribute before its markers collapse into a single cluster
 * badge. Operator-adjustable from the map toolbar; this is the default it starts on.
 *
 * 0 means every LP clusters, however few pins it has — the map opens fully grouped by
 * LP and expands on demand, which is what keeps a country-wide view readable. The
 * comparison is `count > limit`, so 0 is a real setting and not "clustering off".
 */
const DEFAULT_CLUSTER_LIMIT = 0;

/** Palette for cluster badges — one stable colour per LP, so an LP looks the same each visit. */
const CLUSTER_COLORS = ['#7c3aed', '#2563eb', '#0891b2', '#059669', '#d97706', '#dc2626', '#db2777', '#4f46e5'];

const clusterColorFor = (lpName: string): string => {
  let hash = 0;
  for (let i = 0; i < lpName.length; i += 1) {
    hash = (hash * 31 + lpName.charCodeAt(i)) >>> 0;
  }
  return CLUSTER_COLORS[hash % CLUSTER_COLORS.length];
};

/** The badge is an inline SVG data URI, so an LP name goes in without a marker library. */
const escapeXml = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');

/**
 * The digits of an LCP name, and nothing else.
 *
 * Field naming is not consistent — the same LCP turns up as `LCP-001`, `LP 001`,
 * `LCP#001` and `LP-001` depending on who typed it — and at map zoom the prefix is
 * both the widest part of the badge and the least informative, since every pin on the
 * screen carries the same one. Reducing to `001` is what makes a cluster readable
 * without opening it, and it makes the differently-typed spellings of one LCP render
 * identically.
 *
 * The first run of digits is taken verbatim, so leading zeros survive: `LCP-024`
 * reads `024`, not `24`. A name with no digits at all keeps its text with the prefix
 * stripped, because showing nothing would be worse than showing an odd label.
 *
 * Shared verbatim with the mobile map so both surfaces badge an LCP the same way.
 */
export const lcpBadgeLabel = (lcpName: string): string => {
  const name = String(lcpName ?? '').trim();
  const digits = name.match(/\d+/);

  if (digits) return digits[0];

  const stripped = name.replace(/^\s*(lcp|lp)\s*[-–—_#:.]*\s*/i, '').trim();
  return stripped || name || '—';
};

/**
 * Badge geometry — kept in step with the mobile map, so the two surfaces match.
 *
 * A solid disc inside a translucent halo, carrying the LCP's number. It is the shape
 * the Subscriber Map already uses, so the two maps in the product read as one family,
 * and the halo is what separates a badge from the map underneath it at any zoom.
 *
 * These constants are duplicated in `MOBILEAPP/frontend/src/pages/LcpNapLocation.tsx`
 * rather than shared — the two apps have no common module — and the badge has to be
 * pixel-identical on a phone and on a desk. Change one, change the other.
 */

/** Point size of the digits inside the disc. */
const BADGE_TEXT_SIZE = 14;

/** Thickness of the halo around the disc, per side. */
const BADGE_HALO = 5;

/** The solid disc: wide enough for the digits, never smaller than a comfortable target. */
const clusterInnerSize = (label: string): number =>
  Math.max(32, Math.ceil(Math.max(1, label.length) * 9 + 14));

/** The full canvas, disc plus halo on every side. */
const clusterOuterSize = (label: string): number => clusterInnerSize(label) + BADGE_HALO * 2;

/**
 * The cluster badge, as an inline SVG data URI.
 *
 * `count` is no longer drawn — the badge carries the LCP's number alone — but it stays
 * in the signature because the marker's hover title reports it, and callers already
 * pass it.
 */
const createClusterIcon = (lpName: string, count: number): google.maps.Icon => {
  const color = clusterColorFor(lpName);
  const trimmed = lcpBadgeLabel(lpName);
  const label = escapeXml(trimmed);

  const size = clusterOuterSize(trimmed);
  const inner = clusterInnerSize(trimmed);
  const center = size / 2;

  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">` +
    // The halo: the disc's own hue at low opacity, legible over light and dark basemaps.
    `<circle cx="${center}" cy="${center}" r="${center}" fill="${color}" fill-opacity="0.35"/>` +
    `<circle cx="${center}" cy="${center}" r="${inner / 2}" fill="${color}"/>` +
    // Baseline offset by a fraction of the point size rather than `dominant-baseline`,
    // which Safari and older Chrome disagree about — and which mobile cannot use at all.
    `<text x="${center}" y="${center + BADGE_TEXT_SIZE * 0.35}" text-anchor="middle" ` +
    `font-family="Segoe UI, Roboto, Helvetica, Arial, sans-serif" font-size="${BADGE_TEXT_SIZE}" ` +
    `font-weight="700" fill="#ffffff">${label}</text>` +
    '</svg>';

  return {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`,
    scaledSize: new google.maps.Size(size, size),
    anchor: new google.maps.Point(center, center),
  };
};



const LcpNapLocation: React.FC = () => {
  const [isDarkMode, setIsDarkMode] = useState<boolean>(true);
  const [isMobile, setIsMobile] = useState<boolean>(window.innerWidth < 768);
  const [mobileViewMode, setMobileViewMode] = useState<'sidebar' | 'map'>('sidebar');
  const [markers, setMarkers] = useState<LocationMarker[]>([]);
  const [lcpNapGroups, setLcpNapGroups] = useState<LcpNapGroup[]>([]);
  const [selectedLcpNapId, setSelectedLcpNapId] = useState<number | string>('all');
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    const handleResize = () => {
      setIsMobile(window.innerWidth < 768);
    };
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);
  const [currentUserOrgId, setCurrentUserOrgId] = useState<number | null>(() => {
    try {
      const authData = JSON.parse(localStorage.getItem('authData') || '{}');
      return authData.organization_id || authData.user?.organization_id || authData.organization?.id || authData.user?.organization?.id || null;
    } catch {
      return null;
    }
  });
  const [showAddModal, setShowAddModal] = useState(false);

  // Pin-drop: the Add action arms the map instead of opening the form. `pinCoords`
  // tracks the provisional point under the crosshair; `pinnedCoordinates` is the value
  // the operator confirmed, handed to the modal read-only.
  const [isPlacingPin, setIsPlacingPin] = useState(false);
  const [pinCoords, setPinCoords] = useState<{ lat: number; lng: number } | null>(null);
  const [pinnedCoordinates, setPinnedCoordinates] = useState<string | null>(null);

  // Clustering by LP.
  const [clusterLimit, setClusterLimit] = useState<number>(DEFAULT_CLUSTER_LIMIT);
  const [expandedClusters, setExpandedClusters] = useState<Set<string>>(new Set());
  const [displayedLocations, setDisplayedLocations] = useState<LocationMarker[]>([]);
  const [sidebarWidth, setSidebarWidth] = useState<number>(256);
  const [isResizingSidebar, setIsResizingSidebar] = useState<boolean>(false);
  const [isMapReady, setIsMapReady] = useState<boolean>(false);
  const [colorPalette, setColorPalette] = useState<ColorPalette | null>(null);
  const [selectedLocation, setSelectedLocation] = useState<LocationMarker | null>(null);
  const [searchQuery, setSearchQuery] = useState('');
  const [showSuggestions, setShowSuggestions] = useState(false);
  const [addressSuggestions, setAddressSuggestions] = useState<google.maps.places.AutocompletePrediction[]>([]);
  const searchRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<HTMLDivElement>(null);
  const mapInstanceRef = useRef<google.maps.Map | null>(null);
  const autocompleteServiceRef = useRef<google.maps.places.AutocompleteService | null>(null);
  const placesServiceRef = useRef<google.maps.places.PlacesService | null>(null);
  const markersRef = useRef<google.maps.Marker[]>([]);
  const infoWindowRef = useRef<google.maps.InfoWindow | null>(null);
  const sidebarStartXRef = useRef<number>(0);
  const sidebarStartWidthRef = useRef<number>(0);
  const searchMarkerRef = useRef<google.maps.Marker | null>(null);
  const allMarkersMapRef = useRef<Map<number, google.maps.Marker>>(new Map());
  const clusterMarkersRef = useRef<Map<string, google.maps.Marker>>(new Map());
  const pinMarkerRef = useRef<google.maps.Marker | null>(null);
  /**
   * Set when a cluster has just been expanded, so the marker re-render that follows
   * leaves the camera alone — the click handler has already framed that LP's children
   * and a blanket re-fit would immediately pull back out again.
   */
  const suppressFitRef = useRef<boolean>(false);
  const [isDataLoaded, setIsDataLoaded] = useState(false);
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set());

  const filteredMarkers = React.useMemo(() => {
    return markers.filter(m => {
      if (currentUserOrgId) {
        // User belongs to an org: only show markers assigned to that same org
        return m.organization_id === currentUserOrgId;
      } else {
        // User has no org: only show markers that have no org assigned
        const markerOrg = m.organization_id === undefined ? null : m.organization_id;
        return markerOrg === null;
      }
    });
  }, [markers, currentUserOrgId]);

  const searchResults = React.useMemo(() => {
    if (!searchQuery) return [];
    const query = searchQuery.toLowerCase();
    return filteredMarkers.filter(marker =>
      marker.lcpnap_name.toLowerCase().includes(query) ||
      (marker.lcp_name && marker.lcp_name.toLowerCase().includes(query)) ||
      (marker.nap_name && marker.nap_name.toLowerCase().includes(query))
    ).slice(0, 5);
  }, [filteredMarkers, searchQuery]);

  useEffect(() => {
    const observer = new MutationObserver(() => {
      const theme = localStorage.getItem('theme');
      setIsDarkMode(theme !== 'light');
    });

    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ['class']
    });

    const theme = localStorage.getItem('theme');
    setIsDarkMode(theme !== 'light');

    return () => observer.disconnect();
  }, []);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (searchRef.current && !searchRef.current.contains(event.target as Node)) {
        setShowSuggestions(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  useEffect(() => {
    const fetchColorPalette = async () => {
      try {
        const activePalette = await settingsColorPaletteService.getActive();
        setColorPalette(activePalette);
      } catch (err) {
        console.error('Failed to fetch color palette:', err);
      }
    };
    fetchColorPalette();
  }, []);

  useEffect(() => {
    if (!searchQuery) {
      setAddressSuggestions([]);
      return;
    }

    const handler = setTimeout(() => {
      if (autocompleteServiceRef.current && showSuggestions) {
        autocompleteServiceRef.current.getPlacePredictions(
          { input: searchQuery, componentRestrictions: { country: 'ph' } },
          (predictions, status) => {
            if (status === google.maps.places.PlacesServiceStatus.OK && predictions) {
              setAddressSuggestions(predictions.slice(0, 5));
            } else {
              setAddressSuggestions([]);
            }
          }
        );
      }
    }, 300);

    return () => clearTimeout(handler);
  }, [searchQuery, showSuggestions]);

  useEffect(() => {
    loadGoogleMapsScript();
    loadLocations();

    return () => {
      clearMarkers();
      if (infoWindowRef.current) {
        infoWindowRef.current.close();
        infoWindowRef.current = null;
      }
      mapInstanceRef.current = null;
      setIsMapReady(false);
    };
  }, []);

  useEffect(() => {
    if (filteredMarkers.length > 0) {
      groupLocationsByLcpNap();
    }
  }, [filteredMarkers]);

  useEffect(() => {
    if (isMapReady && isDataLoaded && selectedLcpNapId === 'all') {
      initializeAllMarkers(filteredMarkers);
      setDisplayedLocations(filteredMarkers);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isMapReady, isDataLoaded, filteredMarkers]);

  /**
   * The single place markers get drawn.
   *
   * Everything that changes what should be on the map — the LP selection, the cluster
   * limit, an expanded cluster — routes through here, so clustering is applied once and
   * the callers do not each have to remember to re-render.
   */
  useEffect(() => {
    if (!isMapReady) return;
    updateMapMarkers(displayedLocations);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isMapReady, displayedLocations, clusterLimit, expandedClusters]);

  // A different LP selection or a changed limit invalidates which clusters were opened.
  useEffect(() => {
    setExpandedClusters(new Set());
  }, [selectedLcpNapId, clusterLimit]);

  useEffect(() => {
    if (!isResizingSidebar) return;

    const handleMouseMove = (e: MouseEvent) => {
      if (!isResizingSidebar) return;

      const diff = e.clientX - sidebarStartXRef.current;
      const newWidth = Math.max(200, Math.min(500, sidebarStartWidthRef.current + diff));

      setSidebarWidth(newWidth);
    };

    const handleMouseUp = () => {
      setIsResizingSidebar(false);
    };

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);

    return () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
    };
  }, [isResizingSidebar]);

  const loadGoogleMapsScript = () => {
    if (window.google?.maps) {
      initializeMap();
      return;
    }

    const existingScript = document.getElementById('google-maps-script');
    if (existingScript) {
      existingScript.addEventListener('load', initializeMap);
      return;
    }

    const script = document.createElement('script');
    script.id = 'google-maps-script';
    script.src = `https://maps.googleapis.com/maps/api/js?key=${GOOGLE_MAPS_API_KEY}&libraries=marker,places`;
    script.async = true;
    script.defer = true;
    script.onload = initializeMap;
    script.onerror = () => {
      console.error('Failed to load Google Maps script');
      setIsMapReady(false);
    };
    document.head.appendChild(script);
  };

  const initializeMap = () => {
    if (!mapRef.current || !window.google?.maps) return;

    try {
      const map = new google.maps.Map(mapRef.current, {
        center: { lat: 12.8797, lng: 121.7740 },
        zoom: 6,
        minZoom: 6,
        restriction: {
          latLngBounds: {
            north: 21.5,
            south: 4.3,
            west: 114.0,
            east: 127.5,
          },
          strictBounds: true,
        },
        mapTypeControl: true,
        streetViewControl: true,
        fullscreenControl: true,
        zoomControl: true,
        styles: [
          {
            featureType: 'all',
            elementType: 'geometry',
            stylers: [{ color: '#1f2937' }]
          },
          {
            featureType: 'water',
            elementType: 'geometry',
            stylers: [{ color: '#0f172a' }]
          },
          {
            featureType: 'road',
            elementType: 'geometry',
            stylers: [{ color: '#374151' }]
          },
          {
            featureType: 'poi',
            stylers: [{ visibility: 'off' }]
          },
          {
            featureType: 'transit',
            elementType: 'labels',
            stylers: [{ visibility: 'off' }]
          },
          {
            featureType: 'road',
            elementType: 'labels.icon',
            stylers: [{ visibility: 'off' }]
          },
          {
            elementType: 'labels.text.fill',
            stylers: [{ color: '#9ca3af' }]
          },
          {
            elementType: 'labels.text.stroke',
            stylers: [{ color: '#111827' }]
          }
        ]
      });

      infoWindowRef.current = new google.maps.InfoWindow();
      mapInstanceRef.current = map;
      autocompleteServiceRef.current = new google.maps.places.AutocompleteService();
      placesServiceRef.current = new google.maps.places.PlacesService(map);
      setIsMapReady(true);
    } catch (error) {
      console.error('Error initializing map:', error);
      setIsMapReady(false);
    }
  };

  const parseCoordinates = (coordString: string): { latitude: number; longitude: number } | null => {
    if (!coordString) return null;

    const coords = coordString.split(',').map(c => c.trim());
    if (coords.length !== 2) return null;

    const latitude = parseFloat(coords[0]);
    const longitude = parseFloat(coords[1]);

    if (isNaN(latitude) || isNaN(longitude)) return null;

    return { latitude, longitude };
  };

  const loadLocations = async (forceRefresh: boolean = false) => {
    setIsLoading(true);
    try {
      const response = await getAllLCPNAPsForMap(forceRefresh);
      const data = response;

      if (data.success && data.data) {
        const locationData = data.data
          .map((item: any) => {
            const coords = parseCoordinates(item.coordinates);
            if (!coords) return null;

            return {
              id: item.id,
              lcpnap_name: item.lcpnap_name,
              lcp_name: item.lcp_name || 'N/A',
              nap_name: item.nap_name || 'N/A',
              coordinates: item.coordinates,
              latitude: coords.latitude,
              longitude: coords.longitude,
              street: item.street,
              city: item.city,
              region: item.region,
              barangay: item.barangay,
              port_total: item.port_total,
              reading_image_url: item.reading_image_url,
              image1_url: item.image1_url,
              image2_url: item.image2_url,
              modified_by: item.modified_by,
              modified_date: item.modified_date,
              active_sessions: item.active_sessions,
              restricted_sessions: item.restricted_sessions,
              offline_sessions: item.offline_sessions,
              disconnected_sessions: item.disconnected_sessions,
              not_found_sessions: item.not_found_sessions,
              total_technical_details: item.total_technical_details,
              organization_id: item.organization_id
            } as LocationMarker;
          })
          .filter((marker): marker is LocationMarker => marker !== null);

        setMarkers(locationData);
        setIsDataLoaded(true);

        // Pre-create markers after data is loaded and map is ready
        if (mapInstanceRef.current) {
          initializeAllMarkers(locationData);
        }
      }
    } catch (error) {
      console.error('Error loading locations:', error);
    } finally {
      setIsLoading(false);
    }
  };

  const initializeAllMarkers = (locations: LocationMarker[]) => {
    if (!mapInstanceRef.current || !window.google?.maps) return;

    // Clear existing markers mapping
    allMarkersMapRef.current.forEach(m => m.setMap(null));
    allMarkersMapRef.current.clear();

    locations.forEach(location => {
      const isFull = location.port_total && location.total_technical_details !== undefined && location.total_technical_details >= location.port_total;
      const markerColor = isFull ? '#ef4444' : '#22c55e'; // Red if full, Green otherwise

      const marker = new google.maps.Marker({
        position: { lat: location.latitude, lng: location.longitude },
        icon: createMarkerIcon(markerColor),
        title: location.lcpnap_name
      });

      marker.addListener('click', () => {
        setSelectedLocation(location);
      });

      marker.addListener('mouseover', () => {
        if (infoWindowRef.current && mapInstanceRef.current) {
          const addressParts = [
            location.street,
            location.barangay,
            location.city,
            location.region
          ].filter(Boolean);

          const address = addressParts.length > 0
            ? addressParts.join(', ')
            : 'No address available';

          const contentString = `
            <div style="padding: 8px; min-width: 200px;">
              <h3 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: #1f2937;">
                ${location.lcpnap_name}
              </h3>
              <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                <strong>LCP:</strong> ${location.lcp_name}
              </div>
              <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                <strong>NAP:</strong> ${location.nap_name}
              </div>
              <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">
                <strong>Ports:</strong> ${location.total_technical_details || 0} / ${location.port_total || 0}
              </div>
              <div style="margin-top: 8px; padding-top: 8px; border-top: 1px solid #e5e7eb;">
                <div style="font-size: 11px;">
                  <span style="color: #22c55e;">On: ${location.active_sessions || 0}</span> | 
                  <span style="color: #f59e0b;">Off: ${location.offline_sessions || 0}</span> | 
                  <span style="color: #ef4444;">Disc: ${location.disconnected_sessions || 0}</span>
                </div>
              </div>
              <div style="font-size: 11px; color: #9ca3af; margin-top: 4px;">
                ${address}
              </div>
            </div>
          `;

          infoWindowRef.current.setContent(contentString);
          infoWindowRef.current.open(mapInstanceRef.current, marker);
        }
      });

      marker.addListener('mouseout', () => {
        if (infoWindowRef.current) {
          infoWindowRef.current.close();
        }
      });

      allMarkersMapRef.current.set(location.id, marker);
    });
  };

  const groupLocationsByLcpNap = () => {
    const grouped: { [key: string]: LcpNapGroup } = {};

    filteredMarkers.forEach(marker => {
      const groupKey = marker.lcp_name || 'Others';
      if (!grouped[groupKey]) {
        grouped[groupKey] = {
          lcp_name: groupKey,
          locations: [],
          count: 0
        };
      }
      grouped[groupKey].locations.push(marker);
      grouped[groupKey].count++;
    });

    const groupArray = Object.values(grouped).sort((a, b) =>
      a.lcp_name.localeCompare(b.lcp_name)
    );

    setLcpNapGroups(groupArray);
  };

  const clearMarkers = () => {
    allMarkersMapRef.current.forEach(marker => marker.setMap(null));
    clusterMarkersRef.current.forEach(marker => marker.setMap(null));
    clusterMarkersRef.current.clear();
    if (searchMarkerRef.current) {
      searchMarkerRef.current.setMap(null);
      searchMarkerRef.current = null;
    }
    if (pinMarkerRef.current) {
      pinMarkerRef.current.setMap(null);
      pinMarkerRef.current = null;
    }
  };

  const createMarkerIcon = (color: string = '#22c55e'): google.maps.Symbol => {
    return {
      path: google.maps.SymbolPath.CIRCLE,
      scale: 8,
      fillColor: color,
      fillOpacity: 1,
      strokeColor: '#ffffff',
      strokeWeight: 1,
    };
  };

  /**
   * Draw the given locations, grouping each LP that exceeds the cluster limit into one
   * badge instead of its individual pins.
   *
   * An LP is the unit of clustering — not a distance radius — because that is how the
   * field team reasons about the plant: everything hanging off one LP is one thing to
   * visit. An LP at or under the limit always draws its real pins, so a small LP is
   * never hidden behind a badge it does not need. An LP the operator has already
   * expanded stays expanded until the view or the limit changes.
   */
  const updateMapMarkers = (locations: LocationMarker[]) => {
    if (!mapInstanceRef.current || !window.google?.maps) return;

    const groups = new Map<string, LocationMarker[]>();
    locations.forEach(location => {
      const lpName = location.lcp_name || 'Others';
      const existing = groups.get(lpName);
      if (existing) existing.push(location);
      else groups.set(lpName, [location]);
    });

    const individualIds = new Set<number>();
    const clustered: Array<{ lpName: string; locations: LocationMarker[] }> = [];

    groups.forEach((groupLocations, lpName) => {
      if (groupLocations.length > clusterLimit && !expandedClusters.has(lpName)) {
        clustered.push({ lpName, locations: groupLocations });
      } else {
        groupLocations.forEach(location => individualIds.add(location.id));
      }
    });

    const bounds = new google.maps.LatLngBounds();
    let hasVisibleMarkers = false;

    allMarkersMapRef.current.forEach((marker, id) => {
      if (individualIds.has(id)) {
        marker.setMap(mapInstanceRef.current);
        const pos = marker.getPosition();
        if (pos) bounds.extend(pos);
        hasVisibleMarkers = true;
      } else {
        marker.setMap(null);
      }
    });

    syncClusterMarkers(clustered, bounds, () => { hasVisibleMarkers = true; });

    // A freshly expanded cluster has already framed itself; re-fitting here would undo it.
    if (suppressFitRef.current) {
      suppressFitRef.current = false;
      return;
    }

    if (hasVisibleMarkers && locations.length > 0) {
      mapInstanceRef.current.fitBounds(bounds, { top: 50, right: 50, bottom: 50, left: 50 });
      if (locations.length === 1) {
        mapInstanceRef.current.setZoom(18);
      }
    }
  };

  /** Reconcile the cluster badges on the map against the groups that should be clustered. */
  const syncClusterMarkers = (
    clustered: Array<{ lpName: string; locations: LocationMarker[] }>,
    bounds: google.maps.LatLngBounds,
    markVisible: () => void
  ) => {
    if (!mapInstanceRef.current || !window.google?.maps) return;

    const wanted = new Set(clustered.map(group => group.lpName));

    // Retire badges for LPs that are no longer clustered.
    clusterMarkersRef.current.forEach((marker, lpName) => {
      if (!wanted.has(lpName)) {
        marker.setMap(null);
        clusterMarkersRef.current.delete(lpName);
      }
    });

    clustered.forEach(({ lpName, locations: groupLocations }) => {
      const centroid = groupLocations.reduce(
        (acc, location) => ({ lat: acc.lat + location.latitude, lng: acc.lng + location.longitude }),
        { lat: 0, lng: 0 }
      );
      const position = {
        lat: centroid.lat / groupLocations.length,
        lng: centroid.lng / groupLocations.length,
      };

      // Rebuilt rather than repositioned: the badge encodes the child count, so a
      // changed count means a new icon anyway.
      const existing = clusterMarkersRef.current.get(lpName);
      if (existing) existing.setMap(null);

      const marker = new google.maps.Marker({
        position,
        map: mapInstanceRef.current,
        icon: createClusterIcon(lpName, groupLocations.length),
        title: `${lpName} — ${groupLocations.length} LCP/NAP locations. Click to expand.`,
        zIndex: 1000,
      });

      marker.addListener('click', () => expandCluster(lpName, groupLocations));

      clusterMarkersRef.current.set(lpName, marker);
      bounds.extend(position);
      markVisible();
    });
  };

  /** Zoom to the LP's own children and let them draw individually from here on. */
  const expandCluster = (lpName: string, groupLocations: LocationMarker[]) => {
    if (!mapInstanceRef.current || groupLocations.length === 0) return;

    const bounds = new google.maps.LatLngBounds();
    groupLocations.forEach(location => {
      bounds.extend({ lat: location.latitude, lng: location.longitude });
    });

    suppressFitRef.current = true;
    mapInstanceRef.current.fitBounds(bounds, { top: 60, right: 60, bottom: 60, left: 60 });

    setExpandedClusters(prev => {
      const next = new Set(prev);
      next.add(lpName);
      return next;
    });
  };

  const handleLcpNapSelect = (lcpName: string) => {
    setSelectedLcpNapId(lcpName);

    if (lcpName === 'all') {
      setDisplayedLocations(filteredMarkers);
    } else {
      const selectedGroup = lcpNapGroups.find(g => g.lcp_name === lcpName);
      if (selectedGroup) {
        setDisplayedLocations(selectedGroup.locations);
      }
    }

    if (isMobile) {
      setMobileViewMode('map');
    }
  };

  // ---- Pin-drop placement ------------------------------------------------

  /**
   * Arm the map instead of opening the form.
   *
   * The operator frames the pole on the map and confirms; only then does the form open,
   * with those coordinates already filled and locked. Typing a lat/lng into a blank form
   * was the step this replaces.
   */
  const startPinPlacement = () => {
    if (!mapInstanceRef.current) return;

    const center = mapInstanceRef.current.getCenter();
    if (center) setPinCoords({ lat: center.lat(), lng: center.lng() });

    setSelectedLocation(null);
    setIsPlacingPin(true);
    if (isMobile) setMobileViewMode('map');
  };

  const cancelPinPlacement = () => {
    setIsPlacingPin(false);
    setPinCoords(null);
  };

  /** Lock the point in and hand it to the form. */
  const confirmPinPlacement = () => {
    if (!pinCoords) return;
    setPinnedCoordinates(`${pinCoords.lat.toFixed(6)}, ${pinCoords.lng.toFixed(6)}`);
    setIsPlacingPin(false);
    setShowAddModal(true);
  };

  /**
   * While placing, the map centre *is* the provisional coordinate — panning moves the
   * pin under the fixed crosshair, and a tap re-centres on the tapped point so the two
   * never disagree. Listeners are torn down the moment the mode ends.
   */
  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!isPlacingPin || !map || !window.google?.maps) return;

    const syncFromCenter = () => {
      const center = map.getCenter();
      if (center) setPinCoords({ lat: center.lat(), lng: center.lng() });
    };

    const centerListener = map.addListener('center_changed', syncFromCenter);
    const clickListener = map.addListener('click', (event: google.maps.MapMouseEvent) => {
      if (!event.latLng) return;
      map.panTo(event.latLng);
      setPinCoords({ lat: event.latLng.lat(), lng: event.latLng.lng() });
    });

    syncFromCenter();

    return () => {
      google.maps.event.removeListener(centerListener);
      google.maps.event.removeListener(clickListener);
    };
  }, [isPlacingPin]);

  /** The provisional marker itself, drawn under the crosshair while placing. */
  useEffect(() => {
    const map = mapInstanceRef.current;
    if (!map || !window.google?.maps) return;

    if (!isPlacingPin || !pinCoords) {
      if (pinMarkerRef.current) {
        pinMarkerRef.current.setMap(null);
        pinMarkerRef.current = null;
      }
      return;
    }

    if (!pinMarkerRef.current) {
      pinMarkerRef.current = new google.maps.Marker({
        position: pinCoords,
        map,
        zIndex: 2000,
        title: 'New LCP/NAP location',
      });
    } else {
      pinMarkerRef.current.setPosition(pinCoords);
    }
  }, [isPlacingPin, pinCoords]);

  const toggleGroup = (groupName: string, e: React.MouseEvent) => {
    e.stopPropagation();
    const newExpanded = new Set(expandedGroups);
    if (newExpanded.has(groupName)) {
      newExpanded.delete(groupName);
    } else {
      newExpanded.add(groupName);
    }
    setExpandedGroups(newExpanded);
  };

  const handleLocationSelect = (location: LocationMarker) => {
    if (!mapInstanceRef.current) return;

    if (searchMarkerRef.current) {
      searchMarkerRef.current.setMap(null);
    }

    const position = { lat: location.latitude, lng: location.longitude };
    mapInstanceRef.current.setCenter(position);
    mapInstanceRef.current.setZoom(18);

    // Add a red pin at the selected LCPNAP location
    searchMarkerRef.current = new google.maps.Marker({
      position,
      map: mapInstanceRef.current,
      title: location.lcpnap_name,
      animation: google.maps.Animation.DROP
    });

    const marker = markersRef.current.find(m => {
      const pos = m.getPosition();
      return pos && Math.abs(pos.lat() - location.latitude) < 0.000001 && Math.abs(pos.lng() - location.longitude) < 0.000001;
    });

    if (marker && infoWindowRef.current) {
      google.maps.event.trigger(marker, 'click');
    }
  };

  const handleAddressSelect = (placeId: string, description: string) => {
    setSearchQuery(description);
    setShowSuggestions(false);

    if (!placesServiceRef.current || !mapInstanceRef.current) return;

    placesServiceRef.current.getDetails(
      { placeId, fields: ['geometry', 'formatted_address', 'name'] },
      (place, status) => {
        if (status === google.maps.places.PlacesServiceStatus.OK && place?.geometry?.location) {
          const location = place.geometry.location;
          mapInstanceRef.current?.setCenter(location);
          mapInstanceRef.current?.setZoom(18);

          if (searchMarkerRef.current) {
            searchMarkerRef.current.setMap(null);
          }

          searchMarkerRef.current = new google.maps.Marker({
            position: location,
            map: mapInstanceRef.current,
            title: description,
            animation: google.maps.Animation.DROP
          });

          if (infoWindowRef.current) {
            infoWindowRef.current.setContent(`
              <div style="padding: 8px; min-width: 150px;">
                <h3 style="margin: 0 0 4px 0; font-size: 14px; font-weight: 600; color: #1f2937;">Selected Location</h3>
                <p style="margin: 0; font-size: 12px; color: #6b7280;">${description}</p>
              </div>
            `);
            infoWindowRef.current.open(mapInstanceRef.current, searchMarkerRef.current);
          }
        }
      }
    );
  };

  const handleMouseDownSidebarResize = (e: React.MouseEvent) => {
    e.preventDefault();
    setIsResizingSidebar(true);
    sidebarStartXRef.current = e.clientX;
    sidebarStartWidthRef.current = sidebarWidth;
  };

  const handleSaveLocation = () => {
    clearLCPNAPMapCache();
    loadLocations(true);
  };

  const lcpNapItems = lcpNapGroups;


  return (
    <div className={`${isDarkMode ? 'bg-gray-950' : 'bg-gray-50'
      } h-full flex overflow-hidden`}>
      <div className={`${
        isMobile
          ? mobileViewMode === 'sidebar' ? 'flex w-full' : 'hidden'
          : 'flex-shrink-0 flex flex-col border-r relative'
      } ${isDarkMode ? 'bg-gray-900 border-gray-700' : 'bg-white border-gray-200'
        }`} style={!isMobile ? { width: `${sidebarWidth}px` } : undefined}>
        <div className={`p-4 border-b flex-shrink-0 ${isDarkMode ? 'border-gray-700' : 'border-gray-200'
          }`}>
          <div className="flex items-center justify-between mb-1">
            <h2 className={`text-lg font-semibold ${isDarkMode ? 'text-white' : 'text-gray-900'
              }`}>LCP/NAP Locations</h2>
            {isMobile && (
              <button
                onClick={() => setMobileViewMode('map')}
                className="px-3 py-1.5 text-xs text-white rounded transition-colors"
                style={{ backgroundColor: colorPalette?.primary || '#7c3aed' }}
              >
                View Map
              </button>
            )}
          </div>
        </div>

        <div className="flex-1 overflow-y-auto [&::-webkit-scrollbar]:hidden [-ms-overflow-style:none] [scrollbar-width:none]">
          <button
            onClick={() => handleLcpNapSelect('all')}
            className={`w-full flex items-center justify-between px-4 py-3 text-sm transition-colors ${isDarkMode ? 'hover:bg-gray-800' : 'hover:bg-gray-100'
              } ${selectedLcpNapId === 'all'
                ? 'font-medium'
                : isDarkMode ? 'text-gray-300' : 'text-gray-700'
              }`}
            style={selectedLcpNapId === 'all' ? {
              backgroundColor: colorPalette?.primary ? `${colorPalette.primary}33` : 'rgba(124, 58, 237, 0.2)',
              color: colorPalette?.primary || '#7c3aed'
            } : {}}
          >
            <div className="flex items-center">
              <span>All Locations</span>
            </div>
            <span className={`px-2 py-1 rounded-full text-xs ${selectedLcpNapId === 'all'
              ? 'text-white'
              : isDarkMode ? 'bg-gray-700 text-gray-300' : 'bg-gray-200 text-gray-700'
              }`}
              style={selectedLcpNapId === 'all' ? {
                backgroundColor: colorPalette?.primary || '#7c3aed'
              } : {}}
            >
              {filteredMarkers.length}
            </span>
          </button>

          {lcpNapItems.map((group) => (
            <div key={group.lcp_name}>
              <button
                onClick={() => handleLcpNapSelect(group.lcp_name)}
                className={`w-full flex items-center justify-between px-4 py-3 text-sm transition-colors group/lp ${isDarkMode ? 'hover:bg-gray-800' : 'hover:bg-gray-100'
                  } ${selectedLcpNapId === group.lcp_name
                    ? 'font-medium'
                    : isDarkMode ? 'text-gray-300' : 'text-gray-700'
                  }`}
                style={selectedLcpNapId === group.lcp_name ? {
                  backgroundColor: colorPalette?.primary ? `${colorPalette.primary}33` : 'rgba(124, 58, 237, 0.2)',
                  color: colorPalette?.primary || '#7c3aed'
                } : {}}
              >
                <div className="flex items-center overflow-hidden">
                  <div 
                    onClick={(e) => toggleGroup(group.lcp_name, e)}
                    className={`mr-2 p-1 rounded hover:bg-black/10 transition-colors`}
                  >
                    {expandedGroups.has(group.lcp_name) ? (
                      <ChevronDown className="h-4 w-4" />
                    ) : (
                      <ChevronRight className="h-4 w-4" />
                    )}
                  </div>
                  <MapPin className="h-4 w-4 mr-2 flex-shrink-0" />
                  <span className="truncate">{group.lcp_name}</span>
                </div>
                <span className={`px-2 py-1 rounded-full text-xs ${selectedLcpNapId === group.lcp_name
                  ? 'text-white'
                  : isDarkMode ? 'bg-gray-700 text-gray-300' : 'bg-gray-200 text-gray-700'
                  }`}
                  style={selectedLcpNapId === group.lcp_name ? {
                    backgroundColor: colorPalette?.primary || '#7c3aed'
                  } : {}}
                >
                  {group.count}
                </span>
              </button>

              {expandedGroups.has(group.lcp_name) && (
                <div className={`${isDarkMode ? 'bg-gray-900/50' : 'bg-gray-50'}`}>
                  {group.locations.sort((a,b) => a.lcpnap_name.localeCompare(b.lcpnap_name)).map((loc) => (
                    <button
                      key={loc.id}
                      onClick={() => {
                        setSelectedLocation(loc);
                        handleLocationSelect(loc);
                        if (isMobile) {
                          setMobileViewMode('map');
                        }
                      }}
                      className={`w-full flex items-center justify-between pl-12 pr-4 py-2 text-xs transition-colors ${isDarkMode ? 'hover:bg-gray-800 text-gray-400 hover:text-white' : 'hover:bg-gray-200 text-gray-600 hover:text-gray-900'
                        } ${selectedLocation?.id === loc.id ? 'font-bold bg-black/5' : ''}`}
                      style={selectedLocation?.id === loc.id ? {
                        color: colorPalette?.primary || '#7c3aed'
                      } : {}}
                    >
                      <span className="truncate">{loc.lcpnap_name}</span>
                      {loc.total_technical_details !== undefined && (
                        <span className="opacity-60">
                           {loc.total_technical_details}/{loc.port_total}
                        </span>
                      )}
                    </button>
                  ))}
                </div>
              )}
            </div>
          ))}
        </div>

        <div
          className="absolute right-0 top-0 bottom-0 w-1 cursor-col-resize transition-colors z-10"
          style={{
            backgroundColor: 'transparent'
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.backgroundColor = colorPalette?.primary || '#7c3aed';
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.backgroundColor = 'transparent';
          }}
          onMouseDown={handleMouseDownSidebarResize}
        />
      </div>

      <div className={`${
        isMobile && mobileViewMode !== 'map' ? 'hidden' : 'flex-1'
      } overflow-hidden ${isDarkMode ? 'bg-gray-900' : 'bg-white'
        }`}>
        <div className="flex flex-col h-full">
          <div className={`p-4 border-b flex-shrink-0 relative z-10 ${isDarkMode ? 'bg-gray-900 border-gray-700' : 'bg-white border-gray-200'
            }`}>
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
              <div className="flex items-center justify-between">
                <h3 className={`text-lg font-semibold flex items-center gap-2 ${isDarkMode ? 'text-white' : 'text-gray-900'
                  }`}>
                  {isMobile && mobileViewMode === 'map' && (
                    <button
                      onClick={() => setMobileViewMode('sidebar')}
                      className={`p-1 mr-1 rounded-lg transition-colors ${
                        isDarkMode ? 'hover:bg-gray-800 text-gray-400 hover:text-white' : 'hover:bg-gray-100 text-gray-500 hover:text-gray-900'
                      }`}
                    >
                      <ChevronLeft size={20} />
                    </button>
                  )}
                  Map View
                </h3>
                {isMobile && (
                  <button
                    onClick={startPinPlacement}
                    disabled={isPlacingPin}
                    title="Drop a pin on the map to add an LCP/NAP location"
                    className="p-2 text-white rounded flex items-center justify-center transition-colors disabled:opacity-50"
                    style={{
                      backgroundColor: colorPalette?.primary || '#7c3aed'
                    }}
                  >
                    <MapPin className="h-4 w-4" />
                  </button>
                )}
              </div>

              <div className="flex-1 max-w-md relative sm:mx-4" ref={searchRef}>
                <div className="relative">
                  <Search className={`absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`} />
                  <input
                    type="text"
                    placeholder="Search location..."
                    value={searchQuery}
                    onChange={(e) => {
                      setSearchQuery(e.target.value);
                      setShowSuggestions(true);
                    }}
                    onFocus={() => setShowSuggestions(true)}
                    className={`w-full pl-10 pr-10 py-2 rounded-lg border text-sm transition-colors focus:outline-none ${isDarkMode
                      ? 'bg-gray-800 border-gray-700 text-white focus:border-gray-600'
                      : 'bg-white border-gray-300 text-gray-900 focus:border-gray-400'
                      }`}
                  />
                  {searchQuery && (
                    <button
                      onClick={() => {
                        setSearchQuery('');
                        setShowSuggestions(false);
                      }}
                      className={`absolute right-3 top-1/2 transform -translate-y-1/2 p-0.5 rounded-full transition-colors ${isDarkMode ? 'text-gray-400 hover:text-white hover:bg-gray-800' : 'text-gray-500 hover:text-gray-900 hover:bg-gray-100'
                        }`}
                    >
                      <X className="h-4 w-4" />
                    </button>
                  )}
                </div>
                {showSuggestions && searchQuery && (searchResults.length > 0 || addressSuggestions.length > 0) && (
                  <div className={`absolute top-full left-0 mt-1 w-full rounded-md shadow-lg border overflow-hidden z-[1001] max-h-96 overflow-y-auto ${isDarkMode ? 'bg-gray-800 border-gray-700' : 'bg-white border-gray-200'
                    }`}>
                    {searchResults.length > 0 && (
                      <div className={`px-3 py-1.5 text-xs font-semibold uppercase tracking-wider ${isDarkMode ? 'text-gray-400 bg-gray-900 border-b border-gray-700' : 'text-gray-500 bg-gray-50 border-b border-gray-200'}`}>
                        LCP / NAP Locations
                      </div>
                    )}
                    {searchResults.map(result => (
                      <button
                        key={result.id}
                        className={`w-full text-left px-4 py-2 text-sm transition-colors border-b last:border-0 ${isDarkMode
                          ? 'border-gray-700 hover:bg-gray-700 text-gray-200'
                          : 'border-gray-100 hover:bg-gray-50 text-gray-800'
                          }`}
                        onClick={() => {
                          setSearchQuery(result.lcpnap_name);
                          setShowSuggestions(false);
                          handleLocationSelect(result);
                        }}
                      >
                        <div className="font-medium">{result.lcpnap_name}</div>
                        <div className={`text-xs mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-500'}`}>
                          LCP: {result.lcp_name} • NAP: {result.nap_name}
                        </div>
                      </button>
                    ))}

                    {addressSuggestions.length > 0 && (
                      <div className={`px-3 py-1.5 text-xs font-semibold uppercase tracking-wider ${isDarkMode ? 'text-gray-400 bg-gray-900 border-b border-gray-700' : 'text-gray-500 bg-gray-50 border-b border-gray-200'}`}>
                        Address Suggestions
                      </div>
                    )}
                    {addressSuggestions.map(suggestion => (
                      <button
                        key={suggestion.place_id}
                        className={`w-full text-left px-4 py-2 text-sm transition-colors border-b last:border-0 ${isDarkMode
                          ? 'border-gray-700 hover:bg-gray-700 text-gray-200'
                          : 'border-gray-100 hover:bg-gray-50 text-gray-800'
                          }`}
                        onClick={() => handleAddressSelect(suggestion.place_id, suggestion.description)}
                      >
                        <div className="flex items-start gap-2">
                          <MapPin className={`h-4 w-4 mt-0.5 flex-shrink-0 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`} />
                          <span className="font-medium">{suggestion.description}</span>
                        </div>
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {!isMobile && (
                <button
                  onClick={startPinPlacement}
                  disabled={isPlacingPin}
                  title="Drop a pin on the map to add an LCP/NAP location"
                  className="px-4 py-2 text-white rounded flex items-center gap-2 text-sm transition-colors disabled:opacity-50"
                  style={{
                    backgroundColor: colorPalette?.primary || '#7c3aed'
                  }}
                  onMouseEnter={(e) => {
                    if (colorPalette?.accent) {
                      e.currentTarget.style.backgroundColor = colorPalette.accent;
                    }
                  }}
                  onMouseLeave={(e) => {
                    e.currentTarget.style.backgroundColor = colorPalette?.primary || '#7c3aed';
                  }}
                >
                  <MapPin className="h-4 w-4" />
                  Add LCPNAP
                </button>
              )}
            </div>
          </div>

          <div className="flex-1 relative z-0">
            <div
              ref={mapRef}
              className="absolute inset-0 w-full h-full z-0"
            />

            {/* Cluster size control. Sits out of the way top-right until an LP is big
                enough for it to matter. */}
            {!isPlacingPin && (
              <div
                className={`absolute top-3 right-3 z-[500] flex items-center gap-2 px-3 py-2 rounded-lg shadow-lg border text-xs ${
                  isDarkMode ? 'bg-gray-900/95 border-gray-700 text-gray-200' : 'bg-white/95 border-gray-200 text-gray-700'
                }`}
              >
                <Layers className="h-4 w-4 flex-shrink-0" style={{ color: colorPalette?.primary || '#7c3aed' }} />
                <label htmlFor="lcpnap-cluster-limit" className="whitespace-nowrap">Cluster over</label>
                <input
                  id="lcpnap-cluster-limit"
                  type="number"
                  min={0}
                  value={clusterLimit}
                  onChange={(e) => setClusterLimit(Math.max(0, Number(e.target.value) || 0))}
                  title="Maximum pins an LP may show before its markers collapse into one cluster badge. 0 clusters every LP."
                  className={`w-16 px-2 py-1 rounded border text-xs ${
                    isDarkMode ? 'bg-gray-800 border-gray-700 text-white' : 'bg-white border-gray-300 text-gray-900'
                  }`}
                />
                <span className="whitespace-nowrap">pins per LP</span>
              </div>
            )}

            {/* Pin-drop crosshair. Fixed to the centre of the viewport and click-through,
                so the map underneath still pans and zooms normally. */}
            {isPlacingPin && (
              <div className="absolute inset-0 z-[600] pointer-events-none flex items-center justify-center">
                <div className="relative">
                  <div
                    className="w-10 h-10 rounded-full border-2 opacity-70"
                    style={{ borderColor: colorPalette?.primary || '#7c3aed' }}
                  />
                  <div
                    className="absolute left-1/2 top-1/2 w-[2px] h-8 -translate-x-1/2 -translate-y-1/2"
                    style={{ backgroundColor: colorPalette?.primary || '#7c3aed' }}
                  />
                  <div
                    className="absolute left-1/2 top-1/2 h-[2px] w-8 -translate-x-1/2 -translate-y-1/2"
                    style={{ backgroundColor: colorPalette?.primary || '#7c3aed' }}
                  />
                </div>
              </div>
            )}

            {/* Floating confirmation bar for the pin-drop. */}
            {isPlacingPin && (
              <div className="absolute bottom-6 left-1/2 -translate-x-1/2 z-[700] w-[min(92%,30rem)]">
                <div
                  className={`rounded-xl shadow-2xl border p-3 flex flex-col gap-3 ${
                    isDarkMode ? 'bg-gray-900/95 border-gray-700' : 'bg-white/95 border-gray-200'
                  }`}
                >
                  <div className="flex items-start gap-2">
                    <MapPin
                      className="h-4 w-4 mt-0.5 flex-shrink-0"
                      style={{ color: colorPalette?.primary || '#7c3aed' }}
                    />
                    <div className="min-w-0">
                      <p className={`text-sm font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}`}>
                        Position the pin
                      </p>
                      <p className={`text-xs ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
                        Pan the map or tap a spot, then confirm.
                      </p>
                      <p className={`text-xs font-mono mt-1 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}`}>
                        {pinCoords
                          ? `${pinCoords.lat.toFixed(6)}, ${pinCoords.lng.toFixed(6)}`
                          : 'Waiting for the map…'}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    <button
                      onClick={confirmPinPlacement}
                      disabled={!pinCoords}
                      className="flex-1 px-4 py-2 text-white rounded flex items-center justify-center gap-2 text-sm transition-colors disabled:opacity-50"
                      style={{ backgroundColor: colorPalette?.primary || '#7c3aed' }}
                    >
                      <Check className="h-4 w-4" />
                      Confirm
                    </button>
                    <button
                      onClick={cancelPinPlacement}
                      className={`flex-1 px-4 py-2 rounded flex items-center justify-center gap-2 text-sm border transition-colors ${
                        isDarkMode
                          ? 'border-gray-700 text-gray-300 hover:bg-gray-800'
                          : 'border-gray-300 text-gray-700 hover:bg-gray-100'
                      }`}
                    >
                      <X className="h-4 w-4" />
                      Cancel
                    </button>
                  </div>
                </div>
              </div>
            )}

            {isLoading && (
              <div className={`absolute inset-0 bg-opacity-75 flex items-center justify-center z-[1000] ${isDarkMode ? 'bg-gray-900' : 'bg-gray-100'
                }`}>
                <div className="flex flex-col items-center gap-3">
                  <Loader2
                    className="h-8 w-8 animate-spin"
                    style={{ color: colorPalette?.primary || '#7c3aed' }}
                  />
                  <p className={`text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'
                    }`}>Loading map...</p>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      <AddLcpNapLocationModal
        isOpen={showAddModal}
        onClose={() => {
          setShowAddModal(false);
          // The pin is consumed by the form; dropping it here means reopening Add starts
          // a fresh placement rather than silently reusing the last point.
          setPinnedCoordinates(null);
          setPinCoords(null);
        }}
        onSave={handleSaveLocation}
        initialCoordinates={pinnedCoordinates ?? undefined}
        lockCoordinates={pinnedCoordinates !== null}
      />

      {selectedLocation && (
        <div className="fixed inset-0 z-50 md:relative md:inset-auto md:z-auto md:flex-shrink-0 md:overflow-hidden">
          <LcpNapLocationDetails
            location={selectedLocation}
            onClose={() => setSelectedLocation(null)}
            onSave={handleSaveLocation}
            isMobile={isMobile}
          />
        </div>
      )}
    </div>
  );
};

export default LcpNapLocation;
