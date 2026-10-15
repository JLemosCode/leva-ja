const BASE = '/api/v1';
export const tok = { get: () => localStorage.getItem('lj_t'), set: v => localStorage.setItem('lj_t', v), clear: () => { localStorage.removeItem('lj_t'); localStorage.removeItem('lj_u'); } };
export const me = { get: () => JSON.parse(localStorage.getItem('lj_u') || 'null'), set: u => localStorage.setItem('lj_u', JSON.stringify(u)) };
export async function api(path, { method = 'GET', body } = {}) {
  const r = await fetch(BASE + path, { method, headers: { 'Content-Type': 'application/json', ...(tok.get() ? { Authorization: 'Bearer ' + tok.get() } : {}) }, body: body ? JSON.stringify(body) : undefined });
  if (r.status === 204) return null;
  const j = await r.json().catch(() => ({}));
  if (!r.ok) { if (r.status === 401 && tok.get()) { tok.clear(); location.href = '/entrar'; } throw Object.assign(new Error(j.error?.message || 'Erro inesperado'), { code: j.error?.code, status: r.status }); }
  return j;
}
export const brl = c => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
export const STATUS = { created: 'Aguardando pagamento', dispatching_favorites: 'Chamando seus favoritos', dispatching_network: 'Procurando entregador', assigned: 'Entregador a caminho da coleta', at_pickup: 'Entregador na coleta', picked_up: 'Pedido coletado', in_transit: 'A caminho do destino', at_dropoff: 'Chegou ao destino', delivered: 'Entregue', cancelled: 'Cancelada', failed: 'Não concluída' };
export const home = u => u?.role === 'courier' ? '/entregador' : u?.role === 'admin' ? '/admin' : '/pedir';
