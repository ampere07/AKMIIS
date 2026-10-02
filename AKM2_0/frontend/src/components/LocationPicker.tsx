import React, { useState, useEffect, useRef } from 'react';
import { MapPin, Navigation } from 'lucide-react';

interface LocationPickerProps {
  value: string;
  onChange: (coordinates: string) => void;
  isDarkMode: boolean;
  label?: string;
  required?: boolean;
  error?: string;
  showCurrentLocation?: boolean;
}

const LEAFLET_SCRIPT_ID = 'leaflet-script';
const LEAFLET_STYLE_ID = 'leaflet-style';

interface Coordinates {
  lat: number;
  lng: number;
}

const LocationPicker: React.FC<LocationPickerProps> = ({
  value,
  onChange,
  isDarkMode,
  label = 'Location',
  required = false,
  error,
  showCurrentLocation = true
}) => {
  const [map, setMap] = useState<any>(null);
  const [marker, setMarker] = useState<any>(null);
  const [coordinates, setCoordinates] = useState<Coordinates | null>(null);
  const [isGettingLocation, setIsGettingLocation] = useState(false);
  const [latStr, setLatStr] = useState('');
  const [lngStr, setLngStr] = useState('');
  const mapRef = useRef<HTMLDivElement>(null);
  const mapInstance = useRef<any>(null);

  useEffect(() => {
    if (value && value.trim()) {
      const parts = value.split(',').map(p => p.trim());
      if (parts.length === 2) {
        const vLat = parseFloat(parts[0]);
        const vLng = parseFloat(parts[1]);

        const currentLat = parseFloat(latStr);
        const currentLng = parseFloat(lngStr);

        if (!isNaN(vLat) && !isNaN(vLng)) {
          setCoordinates({ lat: vLat, lng: vLng });
        }

        // Only update the string state if the numeric values are different
        // to avoid interrupting the user's typing (e.g., adding a decimal point)
        if (vLat !== currentLat || isNaN(currentLat)) setLatStr(parts[0]);
        if (vLng !== currentLng || isNaN(currentLng)) setLngStr(parts[1]);
      }
    } else {
      setCoordinates(null);
      setLatStr('');
      setLngStr('');
    }
  }, [value]);

  useEffect(() => {
    let unmounted = false;

    const loadLeaflet = () => {
      if (!document.getElementById(LEAFLET_STYLE_ID)) {
        const link = document.createElement('link');
        link.id = LEAFLET_STYLE_ID;
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
        document.head.appendChild(link);
      }

      const existingScript = document.getElementById(LEAFLET_SCRIPT_ID);
      if (existingScript) {
        existingScript.addEventListener('load', initializeMap);
        return;
      }

      const script = document.createElement('script');
      script.id = LEAFLET_SCRIPT_ID;
      script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
      script.async = true;
      script.addEventListener('load', initializeMap);
      document.head.appendChild(script);
    };

    const initializeMap = () => {
      if (unmounted || !mapRef.current || mapInstance.current) return;

      const L = (window as any).L;
      if (!L) return;

      const initialLat = coordinates?.lat || 14.5995;
      const initialLng = coordinates?.lng || 120.9842;

      const newMap = L.map(mapRef.current).setView([initialLat, initialLng], 13);
      mapInstance.current = newMap;

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
      }).addTo(newMap);

      const newMarker = L.marker([initialLat, initialLng], {
        draggable: true
      }).addTo(newMap);

      newMarker.on('dragend', function (e: any) {
        const position = e.target.getLatLng();
        updateCoordinates(position.lat, position.lng);
      });

      newMap.on('click', function (e: any) {
        const { lat, lng } = e.latlng;
        newMarker.setLatLng([lat, lng]);
        updateCoordinates(lat, lng);
      });

      setMap(newMap);
      setMarker(newMarker);

      if (coordinates) {
        newMarker.setLatLng([coordinates.lat, coordinates.lng]);
        newMap.setView([coordinates.lat, coordinates.lng], 13);
      }
    };

    if ((window as any).L) {
      initializeMap();
    } else {
      loadLeaflet();
    }

    return () => {
      unmounted = true;
      document.getElementById(LEAFLET_SCRIPT_ID)?.removeEventListener('load', initializeMap);
      if (mapInstance.current) {
        mapInstance.current.remove();
        mapInstance.current = null;
      }
    };
  }, []);

  useEffect(() => {
    if (map && marker && coordinates) {
      marker.setLatLng([coordinates.lat, coordinates.lng]);
      map.setView([coordinates.lat, coordinates.lng], 13);
    }
  }, [coordinates, map, marker]);

  const updateCoordinates = (lat: number, lng: number) => {
    const roundedLat = parseFloat(lat.toFixed(6));
    const roundedLng = parseFloat(lng.toFixed(6));
    setCoordinates({ lat: roundedLat, lng: roundedLng });
    onChange(`${roundedLat}, ${roundedLng}`);
  };

  const handleLatInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    if (val === '' || /^-?\d*\.?\d*$/.test(val)) {
      setLatStr(val);
      const num = parseFloat(val);
      if (!isNaN(num)) {
        onChange(`${num}, ${lngStr}`);
      }
    }
  };

  const handleLngInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value;
    if (val === '' || /^-?\d*\.?\d*$/.test(val)) {
      setLngStr(val);
      const num = parseFloat(val);
      if (!isNaN(num)) {
        onChange(`${latStr}, ${num}`);
      }
    }
  };

  const handleGetCurrentLocation = () => {
    if (!navigator.geolocation) {
      alert('Geolocation is not supported by your browser');
      return;
    }

    setIsGettingLocation(true);

    navigator.geolocation.getCurrentPosition(
      (position) => {
        const { latitude, longitude } = position.coords;
        updateCoordinates(latitude, longitude);

        if (marker && map) {
          marker.setLatLng([latitude, longitude]);
          map.setView([latitude, longitude], 15);
        }

        setIsGettingLocation(false);
      },
      (error) => {
        console.error('Error getting location:', error);
        alert('Unable to get your location. Please ensure location permissions are enabled.');
        setIsGettingLocation(false);
      },
      {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 0
      }
    );
  };

  return (
    <div>
      <label className={`block text-sm font-medium mb-2 ${isDarkMode ? 'text-gray-300' : 'text-gray-700'
        }`}>
        {label}{required && <span className="text-red-500">*</span>}
      </label>

      <div className={`border rounded overflow-hidden ${isDarkMode ? 'border-gray-700' : 'border-gray-300'
        } ${error ? 'border-red-500' : ''}`}>
        <div className="relative">
          <div
            ref={mapRef}
            className="w-full h-64"
            style={{ zIndex: 1 }}
          />

          {showCurrentLocation && (
          <button
            type="button"
            onClick={handleGetCurrentLocation}
            disabled={isGettingLocation}
            className={`absolute top-2 right-2 px-3 py-2 rounded shadow-lg flex items-center space-x-2 z-[1000] transition-colors ${isDarkMode
                ? 'bg-gray-800 hover:bg-gray-700 text-white'
                : 'bg-white hover:bg-gray-50 text-gray-900'
              } ${isGettingLocation ? 'opacity-50 cursor-not-allowed' : ''}`}
          >
            <Navigation className={`h-4 w-4 ${isGettingLocation ? 'animate-spin' : ''}`} />
            <span className="text-sm">
              {isGettingLocation ? 'Getting...' : 'Get My Location'}
            </span>
          </button>
          )}
        </div>

        <div className={`p-3 border-t ${isDarkMode ? 'bg-gray-800 border-gray-700' : 'bg-gray-50 border-gray-200'
          }`}>
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1">
              <label className={`text-[10px] font-semibold uppercase tracking-wider ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                Latitude
              </label>
              <div className="relative">
                <MapPin className={`absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`} />
                <input
                  type="text"
                  value={latStr}
                  onChange={handleLatInputChange}
                  placeholder="Latitude"
                  aria-label="Latitude"
                  className={`w-full pl-8 pr-3 py-2 rounded text-sm transition-all focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 outline-none ${isDarkMode
                      ? 'bg-gray-900 text-white border-gray-700'
                      : 'bg-white text-gray-900 border-gray-300'
                    } border`}
                />
              </div>
            </div>
            <div className="space-y-1">
              <label className={`text-[10px] font-semibold uppercase tracking-wider ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`}>
                Longitude
              </label>
              <div className="relative">
                <MapPin className={`absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 ${isDarkMode ? 'text-gray-500' : 'text-gray-400'}`} />
                <input
                  type="text"
                  value={lngStr}
                  onChange={handleLngInputChange}
                  placeholder="Longitude"
                  aria-label="Longitude"
                  className={`w-full pl-8 pr-3 py-2 rounded text-sm transition-all focus:ring-2 focus:ring-orange-500/20 focus:border-orange-500 outline-none ${isDarkMode
                      ? 'bg-gray-900 text-white border-gray-700'
                      : 'bg-white text-gray-900 border-gray-300'
                    } border`}
                />
              </div>
            </div>
          </div>
        </div>
      </div>

      {error && (
        <div className="flex items-center mt-1">
          <div className="flex items-center justify-center w-4 h-4 rounded-full bg-orange-500 text-white text-xs mr-2">!</div>
          <p className="text-orange-500 text-xs">{error}</p>
        </div>
      )}

      <p className={`text-xs mt-1 ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}`}>
        Click on the map to set location or drag the marker
      </p>
    </div>
  );
};

export default LocationPicker;
