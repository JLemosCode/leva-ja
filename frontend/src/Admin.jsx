import { useEffect, useState } from 'react'; import { api, brl, STATUS } from './api';
const LABELS = { platform_fee_cents: 'Taxa da plataforma (centavos)', base_cents: 'Preço base (centavos)', per_km_cents: 'Preço por km (centavos)', min_cents: 'Preço mínimo (centavos)', road_factor: 'Fator de rota (× linha reta)', courier_share_pct: 'Repasse ao entregador (%)', favorites_window_s: 'Janela dos favoritos (s)', offer_ttl_s: 'Validade da oferta (s)', network_radius_km: 'Raio inicial da rede (km)', stale_after_s: 'GPS desatualizado após (s)', max_accuracy_m: 'Precisão máxima de GPS (m)', avg_speed_kmh: 'Velocidade média p/ ETA (km/h)', gps_retention_days: 'Retenção do GPS (dias)' };
export default function Admin() {
  const [s, setS] = useState(null), [o, setO] = useState(null), [msg, setMsg] = useState('');
  const load = () => { api('/admin/settings').then(r => setS(r.settings)); api('/admin/overview').then(setO); };
  useEffect(load, []);
  const save = async () => { try { await api('/admin/settings', { method: 'PUT', body: s }); setMsg('Salvo ✔'); } catch (e) { setMsg(e.message); } };
  const susp = async (id, to) => { await api('/admin/couriers/' + id, { method: 'PATCH', body: { availability: to } }); load(); };
  if (!s || !o) return <div className="page"><p className="muted">Carregando…</p></div>;
  return <div className="page"><h2>Painel</h2>
    <div className="stats"><div className="card"><span>Receita de taxas</span><b>{brl(o.revenue_fee_cents)}</b></div><div className="card"><span>Volume pago</span><b>{brl(o.gross_cents)}</b></div></div>
    <div className="card">{o.deliveries_by_status.map(x => <div key={x.status} className="row2"><span>{STATUS[x.status]}</span><b>{x.n}</b></div>)}</div>
    <h3>Configurações</h3><p className="muted">A taxa é somada ao preço de cada nova cobrança.</p>
    <div className="card form">{Object.keys(LABELS).map(k => <label key={k}>{LABELS[k]}<input value={s[k] ?? ''} inputMode="decimal" onChange={e => setS({ ...s, [k]: e.target.value })} /></label>)}
      <button className="btn" onClick={save}>Salvar</button>{msg && <small>{msg}</small>}</div>
    <h3>Entregadores</h3>{o.couriers_list.map(c => <div key={c.id} className="card row2"><span>{c.name}<small className="muted"> · {c.availability}</small></span>
      {c.availability === 'suspended' ? <button className="link" onClick={() => susp(c.id, 'offline')}>reativar</button> : <button className="link" onClick={() => susp(c.id, 'suspended')}>suspender</button>}</div>)}</div>;
}
