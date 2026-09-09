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
 * Install / uninstall routines and the global hook callbacks.
 *
 * @license GPLv3+
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Entitylogin\Portal;
use GlpiPlugin\Entitylogin\PortalProvider;
use GlpiPlugin\Entitylogin\PortalContext;
use GlpiPlugin\Entitylogin\LoginRenderer;

/**
 * Create the plugin tables.
 */
function plugin_entitylogin_install(): bool
{
    $migration = new Migration(PLUGIN_ENTITYLOGIN_VERSION);

    Portal::install($migration);
    PortalProvider::install($migration);

    $migration->executeMigration();

    return true;
}

/**
 * Drop the plugin tables. Nothing outside of these tables is ever written, so
 * uninstalling leaves GLPI and samlSSO exactly as they were.
 */
function plugin_entitylogin_uninstall(): bool
{
    $migration = new Migration(PLUGIN_ENTITYLOGIN_VERSION);

    PortalProvider::uninstall($migration);
    Portal::uninstall($migration);

    $migration->executeMigration();

    return true;
}

/**
 * Runs after all plugins registered their hooks.
 *
 * The supported SSO plugins each list *every* active provider, with no entity
 * awareness. We take their button rendering over so the list can be scoped to
 * the portal being visited. Their authentication flows (samlSSO's `doAuth`,
 * ACS, SLO and metadata; Single Sign-On's OAuth callback) are deliberately left
 * untouched: only the button *rendering* is replaced.
 */
function plugin_entitylogin_post_init(): void
{
    LoginRenderer::takeOverSsoRendering();
}

/**
 * DISPLAY_LOGIN hook: render the SSO buttons for the current portal, if any.
 */
function plugin_entitylogin_display_login(): void
{
    (new LoginRenderer())->render(PortalContext::get());
}
