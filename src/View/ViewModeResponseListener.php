<?php

namespace App\View;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Persists the chosen view mode in a cookie whenever an explicit ?view= query
 * parameter is present, so subsequent requests keep the selected mode.
 */
final class ViewModeResponseListener
{
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->query->has(ViewModeProvider::QUERY_PARAM)) {
            return;
        }

        $mode = ViewModeProvider::normalizeMode($request->query->get(ViewModeProvider::QUERY_PARAM));

        $event->getResponse()->headers->setCookie(Cookie::create(
            ViewModeProvider::COOKIE_NAME,
            $mode,
            time() + 31_536_000,
            '/',
            null,
            false,
            true,
            false,
            Cookie::SAMESITE_LAX
        ));
    }
}
