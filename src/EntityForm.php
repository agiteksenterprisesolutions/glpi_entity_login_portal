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

namespace GlpiPlugin\Entitylogin;

use Entity;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Surfaces a portal's settings directly on the entity form.
 *
 * An administrator creating an organisation is already thinking about its login
 * page, so the slug and the SSO providers are editable right there rather than
 * only in a separate list. The standalone list under Setup remains as an
 * overview of every portal.
 *
 * The fields are prefixed with an underscore so GLPI treats them as transient
 * input rather than entity columns; the portal itself is written afterwards,
 * from the item_add / item_update hooks.
 */
class EntityForm
{
    public const SLUG_FIELD      = '_entitylogin_slug';
    public const PROVIDERS_FIELD = '_entitylogin_providers';

    /**
     * POST_ITEM_FORM: append the portal fields to the entity form.
     *
     * @param array{item: object, options: array} $params
     */
    public static function show(array $params): void
    {
        $item = $params['item'] ?? null;

        if (!$item instanceof Entity) {
            return;
        }

        if (!Portal::canUpdate()) {
            // Editing portals is a configuration right, which not every user
            // able to edit an entity necessarily has.
            return;
        }

        $entities_id = (int) ($item->fields['id'] ?? -1);

        // The root entity is not an organisation that gets its own login page:
        // that is what the generic login page already is.
        if ($entities_id === 0) {
            return;
        }

        $portal = $entities_id > 0 ? Portal::getForEntity($entities_id) : null;

        TemplateRenderer::getInstance()->display('@entitylogin/entity_form.html.twig', [
            'portal'          => $portal,
            'slug'            => $portal?->fields['slug'] ?? '',
            'slug_field'      => self::SLUG_FIELD,
            'providers_field' => self::PROVIDERS_FIELD,
            'provider_groups' => PortalProvider::getSelectableProvidersBySource(),
            'selected'        => $portal !== null ? PortalProvider::getSelectionForPortal((int) $portal->fields['id']) : [],
            'short_url'       => $portal?->getPortalUrl() ?? '',
            'direct_url'      => $portal?->getDirectPortalUrl() ?? '',
        ]);
    }

    /**
     * item_add / item_update: create, update or remove the entity's portal to
     * match what was submitted.
     */
    public static function save(Entity $entity): void
    {
        $input = $entity->input ?? [];

        if (!array_key_exists(self::SLUG_FIELD, $input)) {
            // The form did not carry our fields (an inline edit, an API call,
            // a user without the right): leave the portal untouched.
            return;
        }

        if (!Portal::canUpdate()) {
            return;
        }

        $entities_id = (int) $entity->fields['id'];
        $slug        = trim((string) $input[self::SLUG_FIELD]);
        $providers   = array_map('strval', (array) ($input[self::PROVIDERS_FIELD] ?? []));

        $portal = Portal::getForEntity($entities_id);

        if ($slug === '') {
            // Clearing the slug removes the portal, and with it its mappings.
            if ($portal !== null) {
                $portal->delete(['id' => (int) $portal->fields['id']], true);
                Session::addMessageAfterRedirect(
                    __('The login portal for this entity has been removed.', 'entitylogin'),
                    false,
                    INFO
                );
            }
            return;
        }

        if ($portal === null) {
            $portal = new Portal();
            $portal->add([
                'entities_id' => $entities_id,
                'slug'        => $slug,
                'is_active'   => 1,
                '_providers'  => $providers,
            ]);
            return;
        }

        $portal->update([
            'id'          => (int) $portal->fields['id'],
            'entities_id' => $entities_id,
            'slug'        => $slug,
            '_providers'  => $providers,
        ]);
    }

    /**
     * item_purge: an entity that no longer exists cannot have a login page.
     */
    public static function purge(Entity $entity): void
    {
        $portal = Portal::getForEntity((int) $entity->fields['id']);

        if ($portal !== null) {
            $portal->delete(['id' => (int) $portal->fields['id']], true);
        }
    }
}
