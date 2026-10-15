import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Dev: backend via Docker em http://localhost:8000 (docker compose up)
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        rewrite: (p) => p.replace(/^\/api/, ''),
      },
    },
  },
});
