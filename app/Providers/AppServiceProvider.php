<?php

namespace App\Providers;

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
        // On Windows, PHP's OpenSSL needs a config file for EC key operations
        // (used by Web Push payload encryption / VAPID). Provide a minimal one
        // when the environment doesn't already point to a valid openssl.cnf.
        if (PHP_OS_FAMILY === 'Windows' && ! getenv('OPENSSL_CONF')) {
            $cnf = config_path('openssl-min.cnf');
            if (is_file($cnf)) {
                putenv('OPENSSL_CONF=' . $cnf);
                $_ENV['OPENSSL_CONF'] = $cnf;
                $_SERVER['OPENSSL_CONF'] = $cnf;
            }
        }
    }
}
