<?php

declare(strict_types=1);

namespace EduQR\Controllers\Public;

use EduQR\Controllers\HtmlController;
use EduQR\Middleware\CsrfMiddleware;

/**
 * The three unauthenticated auth screens (NFR-81).
 *
 * The screens only; `Api\AuthController` does the signing in, the reset
 * request and the reset confirmation. These three pages render a form and the
 * page's own JavaScript posts it there, which is why this controller resolves
 * no service: there is nothing to look up before the form is shown, not even
 * for the reset link, whose token is validated by the endpoint that consumes
 * it rather than by the page that carries it.
 *
 * `CsrfMiddleware::getToken()` is the one thing they all need. It used to be
 * called from inside each template; under NFR-81 the controller calls it and
 * hands the result over as `$csrfToken`.
 *
 * @requirement NFR-81
 */
final class AuthPageController extends HtmlController
{
    public function login(): void
    {
        $this->render(
            'auth/login.php',
            ['csrfToken' => CsrfMiddleware::getToken()],
            self::titleWithAppName(t('auth.login.title')),
        );
    }

    public function forgotPassword(): void
    {
        $this->render(
            'auth/forgot.php',
            ['csrfToken' => CsrfMiddleware::getToken()],
            self::titleWithAppName(t('auth.reset.title')),
        );
    }

    /**
     * The token travels from the route pattern `/reset-password/{token}` into
     * the hidden form field, and nowhere else. The page does not judge it —
     * an unknown or expired token is answered by
     * `POST /api/v1/auth/password-reset/confirm` with `invalid_reset_token`,
     * so that the page cannot become a second, weaker oracle for whether a
     * given token exists.
     */
    public function resetPassword(string $token): void
    {
        $this->render(
            'auth/reset.php',
            [
                'csrfToken' => CsrfMiddleware::getToken(),
                'token' => $token,
            ],
            self::titleWithAppName(t('auth.reset.confirm_title')),
        );
    }
}
