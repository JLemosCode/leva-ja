import { useState } from 'react'; import { Link, useNavigate } from 'react-router-dom';
import { api, tok, me, home } from './api';
const NOTICE = 'Leva Já é um aplicativo independente e original de entregas — não é cópia nem possui vínculo com outros aplicativos.';
export function Welcome() {
  return <div className="welcome"><div className="logo">Leva<b>Já</b></div>
    <h1>Entregas rápidas,<br />do seu jeito.</h1>
    <p>Peça, pague e acompanhe no mapa. Um app novo, feito para entregas.</p>
    <div className="stack"><Link className="btn" to="/cadastro">Criar conta</Link><Link className="btn ghost" to="/entrar">Já tenho conta</Link></div>
    <small className="notice">{NOTICE}</small></div>;
}
function useForm(path, extra = {}) {
  const [f, setF] = useState(extra), [err, setErr] = useState(''), [busy, setBusy] = useState(false), nav = useNavigate();
  const set = k => e => setF({ ...f, [k]: e.target.value });
  const submit = async e => { e.preventDefault(); setBusy(true); setErr(''); try { const r = await api(path, { method: 'POST', body: f }); tok.set(r.token); me.set(r.user); nav(home(r.user)); } catch (x) { setErr(x.message); } setBusy(false); };
  return { f, set, err, busy, submit, setF };
}
export function Login() {
  const { set, err, busy, submit } = useForm('/auth/login');
  return <form className="auth" onSubmit={submit}><div className="logo sm">Leva<b>Já</b></div><h2>Entrar</h2>
    <input placeholder="E-mail" type="email" onChange={set('email')} required /><input placeholder="Senha" type="password" onChange={set('password')} required />
    {err && <p className="err">{err}</p>}<button className="btn" disabled={busy}>Entrar</button>
    <p className="muted">Novo por aqui? <Link to="/cadastro">Criar conta</Link></p><small className="notice">{NOTICE}</small></form>;
}
export function Register() {
  const { f, set, setF, err, busy, submit } = useForm('/auth/register', { role: 'customer' });
  return <form className="auth" onSubmit={submit}><div className="logo sm">Leva<b>Já</b></div><h2>Criar conta</h2>
    <div className="seg"><button type="button" className={f.role === 'customer' ? 'on' : ''} onClick={() => setF({ ...f, role: 'customer' })}>Quero enviar</button>
      <button type="button" className={f.role === 'courier' ? 'on' : ''} onClick={() => setF({ ...f, role: 'courier' })}>Quero entregar</button></div>
    <input placeholder="Nome completo" onChange={set('name')} required /><input placeholder="E-mail" type="email" onChange={set('email')} required />
    <input placeholder="CPF ou CNPJ (necessário para pagamento)" inputMode="numeric" onChange={set('cpf_cnpj')} required /><input placeholder="Celular" inputMode="tel" onChange={set('phone')} />
    {f.role === 'courier' && <input placeholder="Veículo (moto, bike, carro)" onChange={set('vehicle')} />}
    <input placeholder="Senha (mín. 8 caracteres)" type="password" minLength={8} onChange={set('password')} required />
    {err && <p className="err">{err}</p>}<button className="btn" disabled={busy}>Cadastrar</button>
    <p className="muted">Já tem conta? <Link to="/entrar">Entrar</Link></p><small className="notice">{NOTICE}</small></form>;
}
