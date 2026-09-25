@echo off
cd /d "C:\laragon\www\opesinsure\mobile app"
set EXPO_PUBLIC_APP_ENV=production
set EXPO_PUBLIC_SHOW_DEMO_LOGIN=true
set EXPO_PUBLIC_API_BASE_URL=https://insurance.opesdatacenter.tech/api/v1
npx expo start --web --port 8089
