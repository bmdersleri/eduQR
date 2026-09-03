<?php

declare(strict_types=1);

namespace EduQR\Controllers\Public;

use EduQR\Controllers\HtmlController;

/**
 * The two standing pages: the landing page and the privacy notice (NFR-81).
 *
 * Neither takes a parameter, reads a cookie, or asks the container for
 * anything — they are translated copy inside the public layout. They are
 * grouped here rather than split into a controller each because a class per
 * route is a filing system, not a design; what they have in common is that
 * they are the site's own pages rather than any feature's.
 *
 * @requirement NFR-81
 */
final class PageController extends HtmlController
{
    public function home(): void
    {
        $this->render(
            'home.php',
            [],
            t('app.name') . ' — ' . t('app.subtitle'),
        );
    }

    /**
     * Linked from `templates/partials/privacy-notice.php`, which appears on
     * every student-facing page, so this must answer without a session of any
     * kind (FR-75, NFR-31).
     */
    public function privacy(): void
    {
        $this->render(
            'privacy.php',
            [],
            self::titleWithAppName(t('privacy.page.title')),
        );
    }
}
