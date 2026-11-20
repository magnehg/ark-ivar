import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Grensesnittet bygges til statiske filer som PHP og webserveren leverer (arkivar/public/app). Ingen Node på serveren.
// I utvikling (npm run dev) sendes API-et og Kjernens ruter til PHPs innebygde server på port 8080.
export default defineConfig({
  base: '/app/',
  plugins: [react()],
  build: { outDir: '../arkivar/public/app', emptyOutDir: true },
  server: { proxy: { '/api': 'http://127.0.0.1:8080', '/bivrost': 'http://127.0.0.1:8080' } },
});
