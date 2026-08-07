{**
 * plugins/blocks/keywordCloud/templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * Keyword cloud plugin settings
 *
 *}
<script>
	$(function() {ldelim}
		$('#keywordCloudSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="keywordCloudSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="blocks" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="keywordCloudSettingsFormNotification"}

	<div id="description">{translate key="plugins.block.keywordCloud.settings.description"}</div>

	<h3>{translate key="plugins.block.keywordCloud.settings.period"}</h3>

	{fbvFormArea id="keywordCloudPeriodFormArea"}
		{fbvFormSection}
			{fbvElement type="select" id="yearStart" name="yearStart" from=$yearOptions selected=$yearStart translate=false label="plugins.block.keywordCloud.settings.yearStart" size=$fbvStyles.size.SMALL}
			{fbvElement type="select" id="yearEnd" name="yearEnd" from=$yearOptions selected=$yearEnd translate=false label="plugins.block.keywordCloud.settings.yearEnd" size=$fbvStyles.size.SMALL}
		{/fbvFormSection}

		{fbvFormSection list=true}
			{fbvElement type="checkbox" id="showPeriod" name="showPeriod" value="1" checked=$showPeriod label="plugins.block.keywordCloud.settings.showPeriod"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
