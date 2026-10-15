import { useEffect, useRef, useState } from 'react';
import { api, brl, STATUS } from './api'; import { useInterval } from './hooks'; import MapView from './Map';
const NEXT = { assigned: ['at_pickup', 'Cheguei na coleta'], at_pickup: ['picked_up', 'Coletei o pedido'], picked_up: ['in_transit', 'Iniciar entrega'], in_transit: ['at_dropoff', 'Cheguei ao destino'], at_dropoff: ['delivered', 'Concluir entrega'] };

export default function Courier() {
  const [av, setAv] = useState('offline'), [offers, setOffers] = useState([]), [act, setAct] = useState(null), [pin, setPin] = useState(''), [err, setErr] = useState(''), [gps, setGps] = useState('');
  const pos = useRef(null), seq = useRef(Date.now()), watch = useRef();
  const online = av === 'available' || av === 'busy';
  const refresh = async () => {
    try { const m = await api('/couriers/me'); setAv(m.availability); const a = await api('/couriers/me/active'); setAct(a.delivery);
      if (!a.delivery && m.availability === 'available') setOffers((await api('/couriers/me/offers')).data); else setOffers([]); } catch (e) { setErr(e.message); }
  };
  useEffect(() => { refresh(); }, []); useInterval(refresh, 5000);
  // GPS: captura contínua; envio a cada 5 s (meta; navegadores podem atrasar em segundo plano)
  useEffect(() => {
    if (!online) { if (watch.current != null) navigator.geolocation.clearWatch(watch.current); return; }
    if (!navigator.geolocation) { setGps('Este aparelho não oferece GPS'); return; }
    watch.current = navigator.geolocation.watchPosition(p => { pos.current = p; setGps(''); }, e => setGps(e.code === 1 ? 'Permita a localização para receber corridas' : 'GPS indisponível'), { enableHighAccuracy: true, maximumAge: 2000 });
    return () => navigator.geolocation.clearWatch(watch.current);
  }, [online]);
  const send = async () => {
    const p = pos.current; if (!p || !online) return;
    try { await api('/couriers/me/location', { method: 'POST', body: { latitude: p.coords.latitude, longitude: p.coords.longitude, accuracy_m: p.coords.accuracy, captured_at: new Date(p.timestamp).toISOString(), sequence_no: ++seq.current, speed_mps: p.coords.speed } }); } catch { /* tenta no próximo ciclo */ }
  };
  useInterval(send, 5000, online); useEffect(() => { if (online) setTimeout(send, 1500); }, [online]);
  const toggle = async () => { setErr(''); try { await api('/couriers/me/availability', { method: 'PUT', body: { availability: online ? 'offline' : 'available' } }); refresh(); } catch (e) { setErr(e.message); } };
  const accept = async o => { try { await api(`/offers/${o.offer_id}/accept`, { method: 'POST' }); refresh(); } catch (e) { setErr(e.status === 409 ? 'Outro entregador aceitou primeiro.' : e.message); refresh(); } };
  const reject = async o => { await api(`/offers/${o.offer_id}/reject`, { method: 'POST' }); refresh(); };
  const adv = async () => { setErr(''); try { await api(`/deliveries/${act.id}/advance`, { method: 'POST', body: { to: NEXT[act.status][0], pin } }); setPin(''); refresh(); } catch (e) { setErr(e.message); } };
  return <div className="full">
    <MapView markers={act ? [{ lat: act.pickup_lat, lng: act.pickup_lng, color: '#0fa38f', label: 'A' }, { lat: act.dropoff_lat, lng: act.dropoff_lng, color: '#ff7a1a', label: 'B' }] : []} />
    <div className="float"><div className="row2"><b>{av === 'busy' ? 'Em entrega' : online ? 'Você está online' : 'Você está offline'}</b>
      <button className={'switch' + (online ? ' on' : '')} disabled={av === 'busy' || av === 'suspended'} onClick={toggle}><i /></button></div>
      {gps && <small className="warn">{gps}</small>}{av === 'suspended' && <small className="err">Conta suspensa. Fale com o suporte.</small>}</div>
    <div className="sheet">
      {act ? <><h3>{STATUS[act.status]}</h3><p><b>Coleta:</b> {act.pickup_address}</p><p><b>Entrega:</b> {act.dropoff_address}</p>
        <p className="muted">{act.recipient_name} {act.recipient_phone} · Ganho {brl(act.earn_cents)}</p>
        {act.notes && <p className="muted">Obs.: {act.notes}</p>}
        {act.status === 'at_dropoff' && <input placeholder="PIN de 4 dígitos informado pelo destinatário" inputMode="numeric" maxLength={4} value={pin} onChange={e => setPin(e.target.value)} />}
        <button className="btn" onClick={adv}>{NEXT[act.status][1]}</button></>
      : offers.length ? offers.map(o => <div key={o.offer_id} className="card offer"><b>{brl(o.earn_cents)}</b> <span className="muted">· {(o.distance_m / 1000).toFixed(1)} km{o.km_to_pickup != null && ` · coleta a ${o.km_to_pickup} km`}</span>
          <p>{o.pickup_address.split(',').slice(0, 2).join(',')}</p><p className="muted">→ {o.dropoff_address.split(',').slice(0, 2).join(',')}</p>
          <div className="row"><button className="btn ghost" onClick={() => reject(o)}>Recusar</button><button className="btn" onClick={() => accept(o)}>Aceitar</button></div></div>)
      : <p className="muted">{online ? 'Procurando corridas perto de você…' : 'Fique online para receber corridas.'}</p>}
      {err && <p className="err">{err}</p>}</div></div>;
}
