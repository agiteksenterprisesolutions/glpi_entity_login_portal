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

namespace GlpiPlugin\Entitylogin;

use Glpi\Application\View\TemplateRenderer;
use Glpi\Plugin\Hooks;
use GlpiPlugin\Entitylogin\Provider\SinglesignonSource;
use Plugin;

/**
 * Renders the SSO area of the login page, scoped to the portal being visited.
 *
 * Two SSO plugins are handled, because they integrate with GLPI in two very
 * different ways:
 *
 *  - samlSSO renders through the DISPLAY_LOGIN hook, so that hook is taken over
 *    (see plugin_entitylogin_post_init()).
 *  - Single Sign-On replaces GLPI's login template and renders its buttons from
 *    a Twig function, so that function is re-registered here to return the
 *    filtered list instead.
 *
 * In both cases the emitted markup keeps the originating plugin's own contract,
 * so the authentication flows themselves are untouched.
 */
class LoginRenderer
{
    /**
     * samlSSO's DISPLAY_LOGIN callback as it was before we removed it, kept so
     * the original behaviour can be restored if ever needed.
     */
    public static mixed $suppressed_samlsso_hook = null;

    /**
     * Renders the button block for a portal, or nothing on the generic login
     * page, where no SSO is offered at all.
     */
    public function render(?Portal $portal): void
    {
        $html = $this->getButtonsHtml($portal);
        if ($html !== '') {
            echo $html;
        }
    }

    /**
     * @return string HTML, or '' when there is nothing to show.
     */
    public function getButtonsHtml(?Portal $portal): string
    {
        if ($portal === null) {
            return '';
        }

        $buttons = PortalProvider::getLoginButtonsForPortal((int) $portal->fields['id']);

        if ($buttons === []) {
            return '';
        }

        return TemplateRenderer::getInstance()->render('@entitylogin/login_buttons.html.twig', [
            'header'            => __('Login with external provider', 'entitylogin'),
            'buttons'           => $buttons,
            'hide_login_fields' => $this->shouldHideLoginFields(),
        ]);
    }

    /**
     * Takes over the button rendering of every supported SSO plugin.
     *
     * Called from POST_INIT, once all plugins have registered their own hooks.
     */
    public static function takeOverSsoRendering(): void
    {
        self::takeOverSamlsso();
        self::takeOverTemplateOverrides();
    }

    /**
     * samlSSO lists every active IdP through DISPLAY_LOGIN; drop its callback so
     * only ours runs.
     */
    private static function takeOverSamlsso(): void
    {
        global $PLUGIN_HOOKS;

        if (isset($PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN]['samlsso'])) {
            self::$suppressed_samlsso_hook = $PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN]['samlsso'];
            unset($PLUGIN_HOOKS[Hooks::DISPLAY_LOGIN]['samlsso']);
        }
    }

    /**
     * Some SSO plugins replace GLPI's login template with their own and render
     * an unfiltered provider list from inside it. Serving GLPI's own template
     * instead puts the SSO area back under the DISPLAY_LOGIN hook, which this
     * plugin owns, so the list can be scoped to the portal.
     *
     * Re-registering their Twig function is not an option: Twig raises
     * "function is already registered" rather than replacing, and plugin
     * initialisation order is not deterministic, so neither plugin can rely on
     * going last. See {@see LoginTemplateLoader}.
     */
    private static function takeOverTemplateOverrides(): void
    {
        if (!Plugin::isPluginActive(SinglesignonSource::KEY)) {
            // Nothing installed that overrides the login template.
            return;
        }

        try {
            $env    = TemplateRenderer::getInstance()->getEnvironment();
            $loader = $env->getLoader();

            if (LoginTemplateLoader::isWrapping($loader)) {
                return;
            }

            $core_template = GLPI_ROOT . '/templates/' . LoginTemplateLoader::LOGIN_TEMPLATE;
            if (!is_readable($core_template)) {
                return;
            }

            $env->setLoader(new LoginTemplateLoader($loader, $core_template));
        } catch (\Throwable) {
            // Never break the login page over a presentation concern.
        }
    }

    /**
     * samlSSO can be configured to hide the local login fields. That setting is
     * global and is honoured here so taking over the renderer does not silently
     * change behaviour for installations relying on it.
     */
    private function shouldHideLoginFields(): bool
    {
        $config = 'GlpiPlugin\\Samlsso\\Config';

        if (!Plugin::isPluginActive('samlsso') || !class_exists($config) || !method_exists($config, 'getHideLoginFields')) {
            return false;
        }

        try {
            return (bool) $config::getHideLoginFields();
        } catch (\Throwable) {
            return false;
        }
    }
}
