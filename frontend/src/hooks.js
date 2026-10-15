import { useEffect, useRef } from 'react';
export function useInterval(fn, ms, on = true) {
  const ref = useRef(fn); ref.current = fn;
  useEffect(() => { if (!on) return; const t = setInterval(() => ref.current(), ms); return () => clearInterval(t); }, [ms, on]);
}
