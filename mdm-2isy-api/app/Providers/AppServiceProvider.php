<?php

namespace App\Providers;

use App\Models\DeviceCredential;
use App\Models\Terminal;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('device-heartbeat', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($this->deviceRateLimitKey($request)));

        RateLimiter::for('device-command-poll', fn (Request $request): Limit => Limit::perMinute(60)
            ->by($this->deviceRateLimitKey($request)));

        RateLimiter::for('device-command-transition', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($this->deviceRateLimitKey($request)));
    }

    private function deviceRateLimitKey(Request $request): string
    {
        $credential = $request->attributes->get('device_credential');

        if ($credential instanceof DeviceCredential) {
            return 'credential:'.$credential->getKey();
        }

        $terminal = $request->attributes->get('device');

        if ($terminal instanceof Terminal) {
            return 'terminal:'.$terminal->getKey();
        }

        return 'unidentified-device';
    }
}
