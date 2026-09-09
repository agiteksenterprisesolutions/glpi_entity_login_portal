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
 * A login portal: the association between an entity and the slug used to reach
 * its dedicated login page.
 *
 * @license GPLv3+
 */

namespace GlpiPlugin\Entitylogin;

use CommonDBTM;
use Entity;
use Migration;
use Session;
use Html;
use Glpi\Application\View\TemplateRenderer;

class Portal extends CommonDBTM
{
    /** Portals belong to an entity. */
    public static $rightname = 'config';

    /**
     * Slugs must be URL-safe and must never collide with a GLPI top-level path.
     * Kept deliberately strict: lowercase letters, digits, dash, underscore.
     */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9_-]{1,63}$/';

    /**
     * Top-level paths owned by GLPI (core, legacy entry points, asset dirs) and
     * by this plugin. A slug matching one of these would be shadowed by, or
     * would shadow, a real GLPI route once the reverse-proxy rewrite is in
     * place, so they are rejected at save time.
     */
    public const RESERVED_SLUGS = [
        'ajax', 'api', 'apirest', 'apixmlrpc', 'bin', 'cache', 'caldav', 'config',
        'css', 'dependency_injection', 'files', 'front', 'inc', 'install', 'js',
        'lib', 'locales', 'login', 'logout', 'marketplace', 'node_modules',
        'pics', 'plugins', 'public', 'resources', 'routes', 'schema', 'scripts',
        'sound', 'src', 'status', 'templates', 'tests', 'tools', 'vendor',
    ];

    public static function getTypeName($nb = 0)
    {
        return _n('Login portal', 'Login portals', $nb, 'entitylogin');
    }

    public static function getIcon()
    {
        return 'ti ti-door-enter';
    }

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_entitylogin_portals';
    }

    /**
     * The slug identifies a portal to admins, so GLPI uses it wherever it would
     * normally show a `name` (list links, form titles, dropdowns).
     */
    public static function getNameField()
    {
        return 'slug';
    }

    /**
     * Show the slug alongside the entity by default, rather than the bare id.
     */
    public static function getDefaultSearchRequest()
    {
        return [
            'sort' => 1,
            'order' => 'ASC',
        ];
    }

    /**
     * Portals are entity-scoped objects.
     */
    public function isEntityAssign()
    {
        return true;
    }

    public function maybeRecursive()
    {
        return false;
    }

    /**
     * The portal of an entity, if it has one. Each entity has at most one.
     */
    public static function getForEntity(int $entities_id): ?self
    {
        $portal = new self();

        if (!$portal->getFromDBByCrit(['entities_id' => $entities_id])) {
            return null;
        }

        return $portal;
    }

    /**
     * Look up an active portal by slug.
     */
    public static function getBySlug(string $slug): ?self
    {
        if (!preg_match(self::SLUG_PATTERN, $slug)) {
            return null;
        }

        $portal = new self();
        if (!$portal->getFromDBByCrit(['slug' => $slug, 'is_active' => 1])) {
            return null;
        }

        return $portal;
    }

    /**
     * Normalise and validate input shared by add and update.
     */
    private function prepareInput(array $input, ?int $current_id = null): array|false
    {
        if (isset($input['slug'])) {
            $slug = strtolower(trim((string) $input['slug']));
            // Be forgiving about what admins type: spaces and stray characters
            // become dashes rather than an error.
            $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug);
            $slug = trim((string) $slug, '-');

            if (!preg_match(self::SLUG_PATTERN, $slug)) {
                Session::addMessageAfterRedirect(
                    __('The URL slug must be 2 to 64 characters long and may only contain lowercase letters, digits, dashes and underscores.', 'entitylogin'),
                    false,
                    ERROR
                );
                return false;
            }

            if (in_array($slug, self::RESERVED_SLUGS, true)) {
                Session::addMessageAfterRedirect(
                    sprintf(__('"%s" is reserved by GLPI and cannot be used as a URL slug.', 'entitylogin'), $slug),
                    false,
                    ERROR
                );
                return false;
            }

            $criteria = ['slug' => $slug];
            if ($current_id !== null) {
                $criteria[] = ['NOT' => ['id' => $current_id]];
            }
            $existing = new self();
            if ($existing->getFromDBByCrit($criteria)) {
                Session::addMessageAfterRedirect(
                    sprintf(__('The URL slug "%s" is already used by another portal.', 'entitylogin'), $slug),
                    false,
                    ERROR
                );
                return false;
            }

            $input['slug'] = $slug;
        }

        return $input;
    }

    public function prepareInputForAdd($input)
    {
        return $this->prepareInput($input);
    }

    public function prepareInputForUpdate($input)
    {
        return $this->prepareInput($input, (int) $this->fields['id']);
    }

    public function post_addItem()
    {
        PortalProvider::saveForPortal((int) $this->fields['id'], $this->input['_providers'] ?? null);
    }

    public function post_updateItem($history = true)
    {
        PortalProvider::saveForPortal((int) $this->fields['id'], $this->input['_providers'] ?? null);
    }

    public function post_purgeItem()
    {
        PortalProvider::purgeForPortal((int) $this->fields['id']);
    }

    /**
     * Full public URL of this portal, using the short root-level form.
     */
    public function getPortalUrl(): string
    {
        global $CFG_GLPI;

        return rtrim((string) $CFG_GLPI['url_base'], '/') . '/' . $this->fields['slug'];
    }

    /**
     * Web path of this plugin, always with a leading slash.
     *
     * Plugin::getWebDir() returns the path without one when asked for the
     * relative form, which would produce broken URLs if concatenated directly.
     */
    public static function getPluginWebDir(): string
    {
        return '/' . ltrim((string) \Plugin::getWebDir('entitylogin', false), '/');
    }

    /**
     * URL that always works, even without the reverse-proxy rewrite.
     */
    public function getDirectPortalUrl(): string
    {
        global $CFG_GLPI;

        return rtrim((string) $CFG_GLPI['url_base'], '/')
            . self::getPluginWebDir()
            . '/portal/' . $this->fields['slug'];
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);

        TemplateRenderer::getInstance()->display('@entitylogin/portal_form.html.twig', [
            'item'      => $this,
            'params'    => $options,
            'provider_groups' => PortalProvider::getSelectableProvidersBySource(),
            'selected'        => $ID > 0 ? PortalProvider::getSelectionForPortal((int) $ID) : [],
        ]);

        return true;
    }

    public function rawSearchOptions()
    {
        $opts = [];

        $opts[] = ['id' => 'common', 'name' => self::getTypeName(2)];

        $opts[] = [
            'id'    => '1',
            'table' => self::getTable(),
            'field' => 'slug',
            'name'  => __('URL slug', 'entitylogin'),
            'datatype' => 'itemlink',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'    => '2',
            'table' => self::getTable(),
            'field' => 'id',
            'name'  => __('ID'),
            'datatype' => 'number',
            'massiveaction' => false,
        ];

        $opts[] = [
            'id'    => '3',
            'table' => 'glpi_entities',
            'field' => 'completename',
            'name'  => Entity::getTypeName(1),
            'datatype' => 'dropdown',
        ];

        $opts[] = [
            'id'    => '4',
            'table' => self::getTable(),
            'field' => 'is_active',
            'name'  => __('Active'),
            'datatype' => 'bool',
        ];

        $opts[] = [
            'id'    => '5',
            'table' => self::getTable(),
            'field' => 'comment',
            'name'  => __('Comments'),
            'datatype' => 'text',
        ];

        return $opts;
    }

    public static function install(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();

        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Installing $table");

            $charset   = 'utf8mb4';
            $collation = 'utf8mb4_unicode_ci';

            $query = <<<SQL
            CREATE TABLE `$table` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entities_id`   INT UNSIGNED NOT NULL DEFAULT 0,
                `slug`          VARCHAR(64) NOT NULL,
                `is_active`     TINYINT NOT NULL DEFAULT 1,
                `comment`       TEXT NULL,
                `date_creation` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                `date_mod`      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `slug` (`slug`),
                KEY `entities_id` (`entities_id`),
                KEY `is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation
            SQL;

            $DB->doQuery($query) or die($DB->error());
        }
    }

    public static function uninstall(Migration $migration): void
    {
        global $DB;

        $table = self::getTable();
        if ($DB->tableExists($table)) {
            $migration->displayMessage("Removing $table");
            $DB->doQuery("DROP TABLE `$table`") or die($DB->error());
        }
    }
}
