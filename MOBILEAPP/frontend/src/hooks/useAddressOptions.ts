import { useEffect, useRef, useState } from 'react';
import { getRegions, getCitiesByRegionId, getBarangaysByCityId, Region, City, Borough } from '../services/cityService';

const sameName = (a?: string | null, b?: string | null) =>
  (a || '').trim().toLowerCase() === (b || '').trim().toLowerCase();

export const useAddressOptions = (isOpen: boolean, savedRegion: string, savedCity: string) => {
  const [regions, setRegions] = useState<Region[]>([]);
  const [cities, setCities] = useState<City[]>([]);
  const [barangays, setBarangays] = useState<Borough[]>([]);
  const cityRequest = useRef(0);
  const barangayRequest = useRef(0);

  const loadCities = (regionId: number | null) => {
    const request = ++cityRequest.current;
    setCities([]);
    setBarangays([]);
    if (!regionId) return Promise.resolve<City[]>([]);
    return getCitiesByRegionId(regionId).then(list => {
      if (cityRequest.current === request) setCities(list);
      return list;
    });
  };

  const loadBarangays = (cityId: number | null) => {
    const request = ++barangayRequest.current;
    setBarangays([]);
    if (!cityId) return;
    getBarangaysByCityId(cityId).then(list => {
      if (barangayRequest.current === request) setBarangays(list);
    });
  };

  useEffect(() => {
    if (!isOpen) return;
    getRegions().then(list => {
      setRegions(list);
      const region = list.find(r => sameName(r.name, savedRegion));
      loadCities(region?.id ?? null).then(cityList => {
        const city = cityList.find(c => sameName(c.name, savedCity));
        loadBarangays(city?.id ?? null);
      });
    });
  }, [isOpen, savedRegion, savedCity]);

  return {
    regions,
    cities,
    barangays,
    selectRegion: (option?: any) => loadCities(option?.id ?? null),
    selectCity: (option?: any) => loadBarangays(option?.id ?? null),
  };
};
