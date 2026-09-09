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

use Twig\Loader\LoaderInterface;
use Twig\Source;

/**
 * Pins GLPI's login template to the copy shipped with GLPI itself.
 *
 * Some SSO plugins integrate by prepending their own `pages/login.html.twig`,
 * replacing the whole login page and rendering an unfiltered provider list from
 * inside it. A portal cannot filter what it does not render, so while this
 * plugin is active the core template is served instead, and the SSO buttons
 * come from the DISPLAY_LOGIN hook - which this plugin owns.
 *
 * This is done in the loader rather than by removing the other plugin's
 * template path because plugin initialisation order is not deterministic: GLPI
 * boots plugins in the order returned by a database query, so this plugin
 * cannot rely on running after the one it needs to override. The loader is
 * consulted at render time, long after every plugin has initialised, which
 * makes the outcome the same regardless of that order.
 *
 * Only the login template is affected; everything else is delegated untouched.
 */
final class LoginTemplateLoader implements LoaderInterface
{
    public const LOGIN_TEMPLATE = 'pages/login.html.twig';

    public function __construct(
        private readonly LoaderInterface $inner,
        private readonly string $core_template_path,
    ) {
    }

    /**
     * Wraps the environment's loader, unless it is already wrapped.
     */
    public static function isWrapping(LoaderInterface $loader): bool
    {
        return $loader instanceof self;
    }

    private function handles(string $name): bool
    {
        return $name === self::LOGIN_TEMPLATE && is_readable($this->core_template_path);
    }

    public function getSourceContext(string $name): Source
    {
        if ($this->handles($name)) {
            return new Source(
                (string) file_get_contents($this->core_template_path),
                $name,
                $this->core_template_path
            );
        }

        return $this->inner->getSourceContext($name);
    }

    public function getCacheKey(string $name): string
    {
        if ($this->handles($name)) {
            // Distinct from the inner loader's key so a template compiled from
            // another plugin's override is never reused for this one.
            return 'entitylogin:' . $this->core_template_path;
        }

        return $this->inner->getCacheKey($name);
    }

    public function isFresh(string $name, int $time): bool
    {
        if ($this->handles($name)) {
            $mtime = @filemtime($this->core_template_path);

            return $mtime !== false && $mtime < $time;
        }

        return $this->inner->isFresh($name, $time);
    }

    public function exists(string $name)
    {
        if ($this->handles($name)) {
            return true;
        }

        return $this->inner->exists($name);
    }
}
