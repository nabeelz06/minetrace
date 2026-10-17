<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SetUserLocale — samakan locale server (Carbon: nama hari/bulan, "2 minggu lalu") dengan bahasa UI
 * pengguna. Tanpa ini teks buatan server (translatedFormat/diffForHumans) selalu berbahasa Inggris
 * walau antarmuka berbahasa Indonesia.
 */
class SetUserLocale
{
    private const SUPPORTED = ['id', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $lang = $request->user()?->language;

        if (in_array($lang, self::SUPPORTED, true)) {
            app()->setLocale($lang);
            Carbon::setLocale($lang);
        }

        return $next($request);
    }
}
