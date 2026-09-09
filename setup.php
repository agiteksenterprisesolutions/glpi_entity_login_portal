<?php

/**
 * ---------------------------------------------------------------------------
 * Entity Login Portals - give every GLPI entity its own login page.
 * Copyright (C) 2026 Agiteks.
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
 * Entity Login Portals
 *
 * Gives every GLPI entity its own dedicated login page (a "portal") reachable
 * through a slug, e.g. https://glpi.example.com/agiteks. Each portal shows the
 * standard GLPI login form plus only the SSO providers mapped to that entity.
 *
 * The generic login page (/) shows the login form with no SSO providers at all.
 *
 * This plugin does not modify GLPI core nor the samlSSO plugin. It renders SSO
 * buttons using the exact same contract as samlSSO (a submit button carrying
 * `samlIdpId`), so the actual authentication flow stays untouched.
 *
 * @license GPLv3+
 */

use Glpi\Plugin\Hooks;
use GlpiPlugin\Entitylogin\EntityForm;
use GlpiPlugin\Entitylogin\Portal;

define('PLUGIN_ENTITYLOGIN_VERSION', '1.2.0');
define('PLUGIN_ENTITYLOGIN_MIN_GLPI', '11.0.0');
define('PLUGIN_ENTITYLOGIN_MAX_GLPI', '11.9.99');

/**
 * Plugin init: register hooks.
 */
function plugin_init_entitylogin(): void
{
    global $PLUGIN_HOOKS;

    // Required so GLPI accepts our pages without a CSRF compliance warning.
    $PLUGIN_HOOKS['csrf_compliant']['entitylogin'] = true;

    // Our own login-screen renderer. It replaces samlSSO's global button list
    // with the per-portal filtered list.
    $PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN]['entitylogin'] = 'plugin_entitylogin_display_login';

    // POST_INIT runs after every plugin has registered its hooks, which is the
    // only point where we can reliably suppress samlSSO's own button renderer.
    $PLUGIN_HOOKS[Hooks::POST_INIT]['entitylogin'] = 'plugin_entitylogin_post_init';

    // Portal settings appear directly on the entity form, so an organisation's
    // login page is configured where the organisation is.
    $PLUGIN_HOOKS[Hooks::POST_ITEM_FORM]['entitylogin'] = [EntityForm::class, 'show'];
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['entitylogin']       = ['Entity' => [EntityForm::class, 'save']];
    // PRE_ITEM_UPDATE rather than ITEM_UPDATE: the latter only fires when an
    // entity column actually changed, so editing just the slug - which is not
    // an entity column - would be silently dropped.
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['entitylogin'] = ['Entity' => [EntityForm::class, 'save']];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['entitylogin']     = ['Entity' => [EntityForm::class, 'purge']];

    if (Session::getLoginUserID()) {
        // Configuration entry under Setup > Dropdowns is not appropriate here,
        // portals are a setup-level object.
        $PLUGIN_HOOKS['menu_toadd']['entitylogin'] = ['config' => Portal::class];
    }
}

/**
 * Plugin metadata.
 */
function plugin_version_entitylogin(): array
{
    return [
        'name'           => 'Entity Login Portals',
        'version'        => PLUGIN_ENTITYLOGIN_VERSION,
        'author'         => 'Agiteks',
        'license'        => 'GPLv3+',
        'homepage'       => '',
        'requirements'   => [
            'glpi' => [
                'min' => PLUGIN_ENTITYLOGIN_MIN_GLPI,
                'max' => PLUGIN_ENTITYLOGIN_MAX_GLPI,
            ],
        ],
    ];
}

/**
 * Prerequisites check.
 *
 * The GLPI version range is declared in plugin_version_entitylogin() and is
 * enforced by GLPI itself, so this only has to cover what GLPI cannot know.
 */
function plugin_entitylogin_check_prerequisites(): bool
{
    return true;
}

/**
 * Configuration check.
 *
 * A portal can only offer SSO buttons that some SSO plugin provides, so this
 * reports when none of the supported ones is active. It stays "true" because
 * that is not a fault: portals can be defined before the SSO plugin is
 * installed, and the plugin is harmless in the meantime.
 *
 * GLPI calls this while checking plugin states, which happens before this
 * plugin's own autoloader is registered, so it must not reference any class
 * from this plugin.
 */
function plugin_entitylogin_check_config($verbose = false): bool
{
    if ($verbose && !plugin_entitylogin_has_sso_plugin()) {
        echo __('No supported SSO plugin is active. Install samlSSO or Single Sign-On to offer providers on a portal.', 'entitylogin');
    }

    return true;
}

/**
 * Whether any supported SSO plugin is installed and active.
 *
 * Deliberately written with core APIs only, for the reason above. The keys
 * mirror the sources in GlpiPlugin\Entitylogin\Provider\SourceRegistry.
 */
function plugin_entitylogin_has_sso_plugin(): bool
{
    /** @var DBmysql|null $DB */
    global $DB;

    $supported = [
        'samlsso'      => 'glpi_plugin_samlsso_configs',
        'singlesignon' => 'glpi_plugin_singlesignon_providers',
    ];

    foreach ($supported as $plugin_key => $table) {
        if (Plugin::isPluginActive($plugin_key) && $DB instanceof DBmysql && $DB->tableExists($table)) {
            return true;
        }
    }

    return false;
}
