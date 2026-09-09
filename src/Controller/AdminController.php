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
 * Back-office pages for managing login portals.
 *
 * GLPI 11 exposes plugin pages through Symfony controllers; the legacy
 * `front/*.php` paths are registered as well because that is what GLPI's menu
 * builder generates for a plugin itemtype.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Entitylogin\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use Glpi\Exception\Http\NotFoundHttpException;
use GlpiPlugin\Entitylogin\Portal;
use Html;
use Search;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    public const LIST_FILE  = 'front/portal.php';
    public const LIST_ROUTE = 'front/portal';
    public const FORM_FILE  = 'front/portal.form.php';
    public const FORM_ROUTE = 'front/portal/form';

    /**
     * Portal list.
     */
    #[Route(self::LIST_ROUTE, name: 'entitylogin_portal_list')]
    #[Route(self::LIST_FILE, name: 'entitylogin_portal_list_file')]
    public function list(Request $request): Response
    {
        Session::checkRight(Portal::$rightname, READ);

        Html::header(
            Portal::getTypeName(2),
            $_SERVER['PHP_SELF'] ?? '',
            'config',
            Portal::class
        );

        Search::show(Portal::class);

        Html::footer();

        return new Response();
    }

    /**
     * Portal form (GET) and its submission (POST).
     */
    #[Route(self::FORM_ROUTE, name: 'entitylogin_portal_form')]
    #[Route(self::FORM_FILE, name: 'entitylogin_portal_form_file')]
    public function form(Request $request): Response
    {
        Session::checkRight(Portal::$rightname, READ);

        if ($request->isMethod('POST')) {
            return $this->handleSubmit($request);
        }

        $id = (int) $request->query->get('id', -1);

        $portal = new Portal();

        if ($id > 0) {
            if (!$portal->getFromDB($id)) {
                throw new NotFoundHttpException();
            }
        } else {
            $portal->getEmpty();
        }

        Html::header(
            Portal::getTypeName(1),
            $_SERVER['PHP_SELF'] ?? '',
            'config',
            Portal::class
        );

        $portal->showForm($id > 0 ? $id : -1);

        Html::footer();

        return new Response();
    }

    /**
     * Add / update / delete a portal.
     */
    private function handleSubmit(Request $request): Response
    {
        Session::checkRight(Portal::$rightname, UPDATE);

        $post   = $request->request->all();
        $portal = new Portal();

        // `_providers` is absent from the payload when nothing is selected;
        // normalise it to an empty array so clearing the selection persists.
        // Values are "source:id" tokens, validated in PortalProvider.
        $post['_providers'] = array_map('strval', (array) ($post['_providers'] ?? []));

        if (isset($post['add'])) {
            $portal->add($post);
        } elseif (isset($post['update'])) {
            $portal->update($post);
        } elseif (isset($post['delete'])) {
            $portal->delete($post);
        } elseif (isset($post['purge'])) {
            $portal->delete($post, true);
        }

        $list_url = Portal::getPluginWebDir() . '/' . self::LIST_FILE;

        if (isset($post['add']) && !isset($post['delete'], $post['purge'])) {
            // Stay on the freshly created portal so its URLs are visible.
            $new_id = (int) $portal->getID();
            if ($new_id > 0) {
                return new RedirectResponse(
                    Portal::getPluginWebDir() . '/' . self::FORM_FILE . '?id=' . $new_id
                );
            }
        }

        if (isset($post['update'])) {
            return new RedirectResponse(
                Portal::getPluginWebDir() . '/' . self::FORM_FILE . '?id=' . (int) $post['id']
            );
        }

        return new RedirectResponse($list_url);
    }
}
