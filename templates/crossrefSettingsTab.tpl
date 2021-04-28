{**
 * templates/crossrefSettingsTab.tpl
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * CrossRef plugin -- displays the StaticPagesGrid.
 *}
<tab id="crossref-settings" label="Crossref Settings">
	{capture assign=crossrefSettingsGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin="crossrefplugin" category="generic" verb="settings" escape=false}{/capture}
	{load_url_in_div id="crossrefSettingsGridUrl" url=$crossrefSettingsGridUrl}
</tab>
