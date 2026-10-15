import { Routes, Route, Navigate, NavLink, useNavigate, Link } from 'react-router-dom';
import { tok, me, home } from './api';
import { Welcome, Login, Register } from './Auth';
import { Home, Pay, Orders, Track, Account } from './Customer';
import Courier from './Courier';
import Admin from './Admin';


function Shell({ children }) {
  const u = me.get(); const nav = useNavigate();
  const tabs = u?.role === 'courier' ? [['/entregador', '🛵', 'Corridas'], ['/conta', '👤', 'Conta']]
    : u?.role === 'admin' ? [['/admin', '⚙️', 'Painel'], ['/conta', '👤', 'Conta']]
    : [['/pedir', '📍', 'Pedir'], ['/pedidos', '📦', 'Pedidos'], ['/conta', '👤', 'Conta']];
  return <div className="shell">{children}
    <nav className="tabs">{tabs.map(([to, i, l]) => <NavLink key={to} to={to} className={({ isActive }) => isActive ? 'on' : ''}><span>{i}</span>{l}</NavLink>)}</nav></div>;
}
const Guard = ({ roles, children }) => {
  const u = me.get(); if (!tok.get() || !u) return <Navigate to="/entrar" replace />;
  if (roles && !roles.includes(u.role)) return <Navigate to={home(u)} replace />;
  return <Shell>{children}</Shell>;
};


export default function App() {
  const u = me.get();
  return <Routes>
    <Route path="/" element={tok.get() && u ? <Navigate to={home(u)} replace /> : <Welcome />} />
    <Route path="/entrar" element={<Login />} /><Route path="/cadastro" element={<Register />} />
    <Route path="/t/:token" element={<Track pub />} />
    <Route path="/pedir" element={<Guard roles={['customer', 'admin']}><Home /></Guard>} />
    <Route path="/pagar/:id" element={<Guard roles={['customer', 'admin']}><Pay /></Guard>} />
    <Route path="/pedidos" element={<Guard roles={['customer', 'admin']}><Orders /></Guard>} />
    <Route path="/acompanhar/:id" element={<Guard roles={['customer', 'admin']}><Track /></Guard>} />
    <Route path="/entregador" element={<Guard roles={['courier']}><Courier /></Guard>} />
    <Route path="/admin" element={<Guard roles={['admin']}><Admin /></Guard>} />
    <Route path="/conta" element={<Guard><Account /></Guard>} />
    <Route path="*" element={<Navigate to="/" replace />} />
  </Routes>;
}
