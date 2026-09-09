<?php

/**
 * ---------------------------------------------------------------------------
 * Entity Login Portals - give every GLPI entity its own login page.
 * Copyright (C) 2026 Agiteks Enterprise Solutions.
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program. If not, see <https://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------------
 */

/**
 * Serves the per-entity login pages.
 *
 * GLPI forces every plugin route under /plugins/<key>/ or /marketplace/<key>/
 * (see Glpi\Routing\PluginRoutesLoader), so the canonical route here is
 * /plugins/entitylogin/portal/{slug}. The short public URL (/agiteks) is
 * produced by rewriting it to this route at the reverse proxy, which leaves the
 * address bar untouched.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Entitylogin\Controller;

use Auth;
use CronTask;
use Dropdown;
use Glpi\Controller\AbstractController;
use Glpi\Http\Firewall;
use Glpi\Security\Attribute\SecurityStrategy;
use GlpiPlugin\Entitylogin\Portal;
use GlpiPlugin\Entitylogin\PortalContext;
use Html;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Toolbox;
use User;

final class PortalController extends AbstractController
{
    #[Route('/portal/{slug}', name: 'entitylogin_portal', requirements: ['slug' => '[a-z0-9][a-z0-9_-]{1,63}'])]
    #[SecurityStrategy(Firewall::STRATEGY_NO_CHECK)]
    public function __invoke(Request $request, string $slug): Response
    {
        global $CFG_GLPI;

        $portal = Portal::getBySlug($slug);

        if ($portal === null) {
            // An unknown slug is simply not a page on this site.
            throw new NotFoundHttpException(sprintf('No active login portal for slug "%s".', $slug));
        }

        // Must be set before the login template renders, since the SSO buttons
        // are produced from within it through the DISPLAY_LOGIN hook.
        PortalContext::set($portal);

        $_SESSION['glpicookietest'] = 'testcookie';

        // Same compatibility shim as the core login page.
        if (isset($_GET['noCAS'])) {
            $_GET['noAUTO'] = $_GET['noCAS'];
        }

        if (!isset($_GET['noAUTO'])) {
            Auth::redirectIfAuthenticated();
        }

        $redirect = $_GET['redirect'] ?? '';

        Auth::checkAlternateAuthSystems(true, $redirect);

        $errors = [];
        if (isset($_GET['error']) && $redirect !== '') {
            switch ($_GET['error']) {
                case 1:
                    $errors[] = __('You must accept cookies to reach this application');
                    break;
                case 2:
                    $errors[] = __('Logins are not possible at this time. Please contact your administrator.');
                    break;
                case 3:
                    $errors[] = __('Your session has expired. Please log in again.');
                    break;
            }
        }

        if (count($errors) > 0) {
            return $this->render('pages/login_error.html.twig', [
                'errors'    => $errors,
                'title'     => __('Access denied'),
                'login_url' => $CFG_GLPI['root_doc'] . '/front/logout.php?noAUTO=1&redirect=' . \rawurlencode($redirect),
                'lang'      => $CFG_GLPI['languages'][$_SESSION['glpilanguage']][3],
            ]);
        }

        if ($redirect !== '') {
            Toolbox::manageRedirect($redirect);
        }

        $rand = mt_rand();

        // Renders GLPI's own login template, so the portal page stays identical
        // to the standard one apart from the SSO area.
        return $this->render('pages/login.html.twig', [
            'rand'                => $rand,
            'card_bg_width'       => true,
            'lang'                => $CFG_GLPI['languages'][$_SESSION['glpilanguage']][3],
            'title'               => $portal->fields['slug'],
            'noAuto'              => $_GET['noAUTO'] ?? 0,
            'redirect'            => $redirect,
            'text_login'          => $CFG_GLPI['text_login'],
            'show_lost_password'  => $CFG_GLPI['notifications_mailing']
                && countElementsInTable('glpi_notifications', [
                    'itemtype'  => User::class,
                    'event'     => 'passwordforget',
                    'is_active' => 1,
                ]),
            'languages_dropdown'  => Dropdown::showLanguages('language', [
                'display'             => false,
                'rand'                => $rand,
                'display_emptychoice' => true,
                'emptylabel'          => __('Default (from user profile)'),
                'width'               => '100%',
            ]),
            // The right-hand panel must render for the SSO buttons to appear.
            'right_panel'         => true,
            'auth_dropdown_login' => Auth::dropdownLogin(false, $rand),
            'copyright_message'   => Html::getCopyrightMessage(false),
            'must_call_cron'      => CronTask::mustRunWebTasks(),
        ]);
    }
}
