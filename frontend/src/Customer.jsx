import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams, Link } from 'react-router-dom';
import { api, brl, STATUS, tok, me } from './api'; import { useInterval } from './hooks'; import MapView from './Map';

function AddressField({ label, color, value, active, onFocus, onPick }) {
  const [txt, setTxt] = useState(value?.address || ''), [list, setList] = useState([]), t = useRef();
  useEffect(() => { setTxt(value?.address || ''); }, [value]);
  const type = v => { setTxt(v); clearTimeout(t.current); t.current = setTimeout(async () => { try { setList((await api('/geocode?q=' + encodeURIComponent(v))).data); } catch { setList([]); } }, 600); };
  return <div className={'field' + (active ? ' active' : '')}><i style={{ background: color }} />
    <input placeholder={label} value={txt} onFocus={onFocus} onChange={e => type(e.target.value)} />
    {active && list.length > 0 && <ul className="sugg">{list.map((s, i) => <li key={i} onClick={() => { onPick(s); setList([]); }}>{s.address}</li>)}</ul>}</div>;
}

export function Home() {
  const nav = useNavigate();
  const [pickup, setPickup] = useState(null), [dropoff, setDropoff] = useState(null), [active, setActive] = useState('pickup');
  const [q, setQ] = useState(null), [form, setForm] = useState({ recipient_name: '', recipient_phone: '', notes: '' }), [err, setErr] = useState(''), [busy, setBusy] = useState(false);
  const idem = useRef(crypto.randomUUID());
  useEffect(() => { if (pickup && dropoff) api('/quotes', { method: 'POST', body: { pickup, dropoff } }).then(setQ).catch(e => setErr(e.message)); else setQ(null); }, [pickup, dropoff]);
  const click = async (lat, lng) => {
    try { const a = await api(`/geocode/reverse?lat=${lat}&lng=${lng}`); active === 'pickup' ? (setPickup(a), setActive('dropoff')) : setDropoff(a); } catch (e) { setErr(e.message); }
  };
  const go = async () => {
    setBusy(true); setErr('');
    try { const d = await api('/deliveries', { method: 'POST', body: { pickup, dropoff, ...form } }); nav('/pagar/' + d.id); } catch (e) { setErr(e.message); } setBusy(false);
  };
  return <div className="full">
    <MapView onClick={click} markers={[pickup && { ...pickup, color: '#0fa38f', label: 'A' }, dropoff && { ...dropoff, color: '#ff7a1a', label: 'B' }].filter(Boolean)} />
    <div className="float">
      <AddressField label="Onde coletar?" color="#0fa38f" value={pickup} active={active === 'pickup'} onFocus={() => setActive('pickup')} onPick={s => { setPickup(s); setActive('dropoff'); }} />
      <AddressField label="Para onde entregar?" color="#ff7a1a" value={dropoff} active={active === 'dropoff'} onFocus={() => setActive('dropoff')} onPick={setDropoff} />
      <small className="muted">Busque o endereço ou toque no mapa.</small></div>
    {q && <div className="sheet">
      <div className="row"><input placeholder="Nome de quem recebe" value={form.recipient_name} onChange={e => setForm({ ...form, recipient_name: e.target.value })} />
        <input placeholder="Telefone" inputMode="tel" value={form.recipient_phone} onChange={e => setForm({ ...form, recipient_phone: e.target.value })} /></div>
      <input placeholder="Observações (opcional)" value={form.notes} onChange={e => setForm({ ...form, notes: e.target.value })} />
      <div className="price"><div><span>Entrega · {(q.distance_m / 1000).toFixed(1)} km</span><b>{brl(q.price_cents)}</b></div>
        <div><span>Taxa da plataforma</span><b>{brl(q.fee_cents)}</b></div><div className="tot"><span>Total</span><b>{brl(q.total_cents)}</b></div></div>
      {err && <p className="err">{err}</p>}<button className="btn" disabled={busy} onClick={go}>Continuar para o pagamento</button></div>}
    {!q && err && <p className="toast">{err}</p>}</div>;
}

export function Pay() {
  const { id } = useParams(), nav = useNavigate(), u = me.get();
  const [d, setD] = useState(null), [method, setMethod] = useState('pix'), [pix, setPix] = useState(null), [err, setErr] = useState(''), [busy, setBusy] = useState(false), [copied, setCopied] = useState(false);
  const [c, setC] = useState({ holder_name: '', number: '', exp_month: '', exp_year: '', cvv: '', postal_code: '', address_number: '' });
  const load = () => api('/deliveries/' + id).then(x => { setD(x); if (x.payment_status === 'paid') nav('/acompanhar/' + id, { replace: true }); }).catch(e => setErr(e.message));
  useEffect(() => { load(); }, []);
  useInterval(load, 4000, true);
  const pay = async () => {
    setBusy(true); setErr('');
    try { const r = await api(`/deliveries/${id}/pay`, { method: 'POST', body: { method, card: method === 'card' ? c : undefined } }); setC({ holder_name: '', number: '', exp_month: '', exp_year: '', cvv: '', postal_code: '', address_number: '' }); if (method === 'pix') setPix(r.payment); setD(r.delivery); if (r.delivery.payment_status === 'paid') nav('/acompanhar/' + id); }
    catch (e) { setErr(e.message); } setBusy(false);
  };
  const k = f => e => setC({ ...c, [f]: e.target.value });
  if (!d) return <div className="page"><p className="muted">Carregando…</p>{err && <p className="err">{err}</p>}</div>;
  return <div className="page"><h2>Pagamento</h2>
    <div className="price card"><div><span>Entrega</span><b>{brl(d.price_cents)}</b></div><div><span>Taxa da plataforma</span><b>{brl(d.fee_cents)}</b></div><div className="tot"><span>Total</span><b>{brl(d.total_cents)}</b></div></div>
    <div className="seg"><button className={method === 'pix' ? 'on' : ''} onClick={() => setMethod('pix')}>Pix</button><button className={method === 'card' ? 'on' : ''} onClick={() => setMethod('card')}>Cartão de crédito</button></div>
    {method === 'pix' && (pix ? <div className="card center"><img alt="QR Code Pix" src={'data:image/png;base64,' + pix.pix_qr} width="200" />
      <textarea readOnly value={pix.pix_payload} rows={3} /><button className="btn ghost" onClick={() => { navigator.clipboard.writeText(pix.pix_payload); setCopied(true); }}>{copied ? 'Copiado!' : 'Copiar Pix copia e cola'}</button>
      <p className="muted">Aguardando pagamento… a tela avança sozinha quando o Pix for confirmado.</p></div>
      : <button className="btn" disabled={busy} onClick={pay}>Gerar Pix</button>)}
    {method === 'card' && <div className="card form">
      <input placeholder="Número do cartão" inputMode="numeric" autoComplete="cc-number" value={c.number} onChange={k('number')} />
      <input placeholder="Nome impresso no cartão" autoComplete="cc-name" value={c.holder_name} onChange={k('holder_name')} />
      <div className="row"><input placeholder="Mês (MM)" inputMode="numeric" maxLength={2} value={c.exp_month} onChange={k('exp_month')} /><input placeholder="Ano (AAAA)" inputMode="numeric" maxLength={4} value={c.exp_year} onChange={k('exp_year')} />
        <input placeholder="CVV" inputMode="numeric" maxLength={4} autoComplete="cc-csc" value={c.cvv} onChange={k('cvv')} /></div>
      <div className="row"><input placeholder="CEP" inputMode="numeric" value={c.postal_code} onChange={k('postal_code')} /><input placeholder="Nº do endereço" value={c.address_number} onChange={k('address_number')} /></div>
      <small className="muted">🔒 Por segurança, o código de segurança (CVV) é pedido a cada compra e nenhum dado do cartão fica salvo em nossos servidores.</small>
      <button className="btn" disabled={busy} onClick={pay}>{busy ? 'Processando…' : `Pagar ${brl(d.total_cents)}`}</button></div>}
    {err && <p className="err">{err}</p>}
    <Link className="muted" to="/pedidos">Pagar depois</Link></div>;
}

export function Orders() {
  const [rows, setRows] = useState(null);
  useEffect(() => { api('/deliveries').then(r => setRows(r.data)); }, []);
  return <div className="page"><h2>Meus pedidos</h2>{!rows ? <p className="muted">Carregando…</p> : rows.length === 0 ? <p className="muted">Nenhum pedido ainda.</p> :
    rows.map(d => <Link key={d.id} className="card order" to={d.payment_status === 'paid' || d.status === 'cancelled' ? '/acompanhar/' + d.id : '/pagar/' + d.id}>
      <b>{STATUS[d.status]}</b><span>{d.dropoff_address.split(',')[0]}</span><span className="muted">{brl(d.total_cents)} · {new Date(d.created_at).toLocaleString('pt-BR')}</span></Link>)}</div>;
}

export function Track({ pub }) {
  const { id, token } = useParams(), nav = useNavigate();
  const [t, setT] = useState(null), [err, setErr] = useState('');
  const load = () => api(pub ? '/track/' + token : `/deliveries/${id}/tracking`).then(setT).catch(e => setErr(e.message));
  useEffect(() => { load(); }, []); useInterval(load, 5000, t ? !['delivered', 'cancelled', 'failed'].includes(t.status) : true);
  if (!t) return <div className="page"><p className="err">{err}</p></div>;
  const cancel = async () => { if (confirm('Cancelar esta entrega?')) try { await api(`/deliveries/${id}/cancel`, { method: 'POST' }); load(); } catch (e) { setErr(e.message); } };
  const c = t.courier;
  return <div className="full"><MapView markers={[{ lat: t.pickup_lat, lng: t.pickup_lng, color: '#0fa38f', label: 'A' }, { lat: t.dropoff_lat, lng: t.dropoff_lng, color: '#ff7a1a', label: 'B' }, c?.lat != null && { lat: c.lat, lng: c.lng, color: '#0b3d3a', label: '🛵' }].filter(Boolean)} />
    <div className="sheet"><h3>{STATUS[t.status]}</h3>
      {c && <p>{c.first_name} · {t.eta_min ? `chega em ~${t.eta_min} min (${t.distance_km} km)` : 'sem sinal de GPS'}{c.stale && <span className="warn"> · localização desatualizada</span>}</p>}
      {!pub && t.delivery_pin && !['delivered', 'cancelled'].includes(t.status) && <div className="pin4">PIN de entrega <b>{t.delivery_pin}</b><small>Informe ao entregador só ao receber.</small></div>}
      {!pub && <p className="muted">{t.dropoff_address}</p>}
      {!pub && t.tracking_token && <button className="btn ghost" onClick={() => navigator.clipboard.writeText(location.origin + '/t/' + t.tracking_token)}>Copiar link para o destinatário</button>}
      {!pub && ['created', 'dispatching_favorites', 'dispatching_network', 'assigned', 'at_pickup'].includes(t.status) && <button className="btn danger" onClick={cancel}>Cancelar entrega</button>}
      {err && <p className="err">{err}</p>}</div></div>;
}

export function Account() {
  const nav = useNavigate(), u = me.get(); const [favs, setFavs] = useState([]), [email, setEmail] = useState(''), [keys, setKeys] = useState([]), [newKey, setNewKey] = useState(''), [err, setErr] = useState('');
  const cust = u?.role === 'customer';
  const load = () => { if (cust) { api('/favorites').then(r => setFavs(r.data)); api('/api-keys').then(r => setKeys(r.data)); } };
  useEffect(load, []);
  const addFav = async () => { try { await api('/favorites', { method: 'POST', body: { courier_email: email } }); setEmail(''); setErr(''); load(); } catch (e) { setErr(e.message); } };
  return <div className="page"><h2>{u.name}</h2><p className="muted">{u.email}</p>
    {cust && <><h3>Entregadores favoritos</h3><p className="muted">Recebem seus pedidos primeiro, por 60 segundos.</p>
      {favs.map(f => <div key={f.id} className="card row2"><span>{f.name}<small className="muted"> · {f.availability}</small></span><button className="link" onClick={async () => { await api('/favorites/' + f.id, { method: 'DELETE' }); load(); }}>remover</button></div>)}
      <div className="row"><input placeholder="E-mail do entregador" value={email} onChange={e => setEmail(e.target.value)} /><button className="btn sm" onClick={addFav}>Adicionar</button></div>
      <h3>API para integrações</h3>{keys.map(k => <div key={k.id} className="card row2"><span>{k.name} <small className="muted">{k.key_prefix}…</small></span><button className="link" onClick={async () => { await api('/api-keys/' + k.id, { method: 'DELETE' }); load(); }}>revogar</button></div>)}
      {newKey && <p className="card"><small>Copie agora (não será exibida de novo):</small><br /><code>{newKey}</code></p>}
      <button className="btn ghost" onClick={async () => { const r = await api('/api-keys', { method: 'POST', body: { name: 'Integração' } }); setNewKey(r.key); load(); }}>Gerar chave de API</button></>}
    {err && <p className="err">{err}</p>}<button className="btn danger" onClick={() => { tok.clear(); nav('/'); }}>Sair</button></div>;
}
