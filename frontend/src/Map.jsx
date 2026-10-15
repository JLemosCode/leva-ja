import { useEffect, useRef } from 'react';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
const TILE = import.meta.env.VITE_TILE_URL || 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'; // produção: use provedor/servidor de tiles próprio
const icon = (c, t) => L.divIcon({ className: '', html: `<div class="pin" style="background:${c}">${t || ''}</div>`, iconSize: [30, 30], iconAnchor: [15, 15] });
export default function MapView({ markers = [], onClick, className = 'map' }) {
  const el = useRef(), map = useRef(), layer = useRef(), fitted = useRef(0), cb = useRef(onClick);
  cb.current = onClick;
  useEffect(() => {
    map.current = L.map(el.current, { zoomControl: false }).setView([-27.24, -48.63], 13);
    L.tileLayer(TILE, { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map.current);
    layer.current = L.layerGroup().addTo(map.current);
    map.current.on('click', e => cb.current?.(e.latlng.lat, e.latlng.lng));
    return () => map.current.remove();
  }, []);
  useEffect(() => {
    layer.current.clearLayers();
    const pts = markers.filter(m => m.lat != null);
    pts.forEach(m => L.marker([m.lat, m.lng], { icon: icon(m.color, m.label) }).addTo(layer.current));
    if (pts.length && fitted.current !== pts.length) { fitted.current = pts.length; map.current.fitBounds(pts.map(m => [m.lat, m.lng]), { padding: [60, 60], maxZoom: 16 }); }
  }, [markers]);
  return <div ref={el} className={className} />;
}
