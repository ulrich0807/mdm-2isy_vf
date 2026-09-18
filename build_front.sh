cd /var/www/mdm-2isy_vf/mdm-2isy-front
cat << 'ENV' > src/environments/environment.production.ts
export const environment = {
  production: true,
  apiUrl: 'http://api.mdm-2isy.com/api',
};
ENV
npm install
npm run build
