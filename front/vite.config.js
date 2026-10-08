import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
// base './' : le site fonctionne dans n'importe quel dossier de l'hébergement
export default defineConfig({
  plugins: [react()],
  base: './',
  server: { proxy: { '/api': 'http://127.0.0.1:8099' } },
});
