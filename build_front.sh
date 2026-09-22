#!/usr/bin/env bash
set -euo pipefail

cd /var/www/mdm-2isy_vf/mdm-2isy-front
npm ci
npm run build -- --configuration production
