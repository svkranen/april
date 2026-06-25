<?php

namespace App\View;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves the UI view mode (business vs. expert) from the current request.
 *
 * Resolution order: ?view= query parameter, then the april_view_mode cookie,
 * then the business default. Invalid values normalise to business.
 *
 * This is purely a UI affordance and has NO security effect.
 */
final readonly class ViewModeProvider
{
    public const MODE_BUSINESS = 'business';
    public const MODE_EXPERT = 'expert';
    public const QUERY_PARAM = 'view';
    public const COOKIE_NAME = 'april_view_mode';

    public function __construct(
        private RequestStack $requestStack
    ) {
    }

    public function getMode(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return self::MODE_BUSINESS;
        }

        if ($request->query->has(self::QUERY_PARAM)) {
            return self::normalizeMode($request->query->get(self::QUERY_PARAM));
        }

        return self::normalizeMode($request->cookies->get(self::COOKIE_NAME));
    }

    public function isExpert(): bool
    {
        return $this->getMode() === self::MODE_EXPERT;
    }

    public static function normalizeMode(mixed $value): string
    {
        return $value === self::MODE_EXPERT ? self::MODE_EXPERT : self::MODE_BUSINESS;
    }
}
