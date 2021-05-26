<?php

/**
 * @file plugins/generic/crossref/CrossRefPlugin.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @package plugins.generic.crossRefPlugin
 * @class CrossRefPlugin
 *
 * Plugin to let managers deposit DOIs and metadata to Crossref
 *
 */

use Illuminate\Support\Facades\DB;

use PKP\core\JSONMessage;
use PKP\linkAction\request\AjaxModal;
use PKP\security\Role;

import('lib.pkp.classes.plugins.GenericPlugin');

class CrossRefPlugin extends GenericPlugin {

	/** @var CrossRefExportPlugin */
	var $_exportPlugin = null;

	function getDisplayName() {
		return __('plugins.generic.crossref.displayName');
	}

	function getDescription() {
		return __('plugins.generic.crossref.description');
	}

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success) {
			// If the system isn't installed, or is performing an upgrade, don't
			// register hooks. This will prevent DB access attempts before the
			// schema is installed.
			if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return true;

			if ($this->getEnabled($mainContextId)) {
				$this->import('CrossRefExportPlugin');
				PluginRegistry::register('importexport', new CrossRefExportPlugin(), $this->getPluginPath());

				HookRegistry::register('APIHandler::endpoints', array($this, 'modifySubmissionEndpoints'));
				HookRegistry::register('Template::doiManagement', array($this, 'callbackShowDoiManagementTabs'));
				HookRegistry::register('DoiManagement::setListPanelArgs', array($this, 'modifyDoiManagementListPanelArgs'));
				HookRegistry::register('DoiListPanel::setConfig', array($this, 'modifyDoiListPanelConfig'));

				// Submissions with Crossref status API
				HookRegistry::register('API::submissions::params', [$this, 'modifyAPISubmissionsParams']);
				HookRegistry::register('Submission::getMany::queryBuilder', [$this, 'modifySubmissionQueryBuilder']);
				HookRegistry::register('Submission::getMany::queryObject', [$this, 'modifySubmissionQueryObject']);
			}
		}

		return $success;
	}

	/**
	 * Extend the website settings tabs to include static pages
	 * @param $hookName string The name of the invoked hook
	 * @param $args array Hook parameters
	 * @return boolean Hook handling status
	 */
	function callbackShowDoiManagementTabs($hookName, $args) {
		$templateMgr = $args[1];
		$output =& $args[2];
		$request =& Registry::get('request');
		$dispatcher = $request->getDispatcher();

		$output .= $templateMgr->fetch($this->getTemplateResource('crossrefSettingsTab.tpl'));

		// Permit other plugins to continue interacting with this hook
		return false;
	}

	/**
	 * Add custom endpoints to APIHandler
	 *
	 * @param $hookName string APIHandler::endpoints
	 * @param $args array [
	 * 		@option $endpoints array
	 * 		@option $handler APIHandler
	 * ]
	 */
	function modifySubmissionEndpoints($hookName, $args) {
		$endpoints =& $args[0];
		$handler =& $args[1];

		switch ($handler) {
			case is_a($handler, 'PKPSubmissionHandler'):
				array_unshift(
					$endpoints['POST'],
					[
						'pattern' => $handler->getEndpointPattern() . '/crossref',
						'handler' => [$this, 'executeCrossRefExportAction'],
						'roles' => [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR],
					]
				);
				break;
		}
	}

	/**
	 * Allows DOI registry plugins to add their presence to DoiListPanel.
	 *
	 * @param $hookName string DoiManagement::setListPanelArgs
	 * @param $args array [
	 * 		@option &listPanelArgs array
	 * ]
	 */
	function modifyDoiManagementListPanelArgs($hookName, $args) {
		$listPanelArgs = &$args[0];
		$listPanelArgs['getParams']['crossrefPluginEnabled'] = true;
		$listPanelArgs['crossrefPluginEnabled'] = true;
	}

	/**
	 * Allows editing DoiListPanel config before being sent to frontend component.
	 *
	 * @param $hookName string DoiListPanel::setConfig
	 * @param $args array [
	 * @option &$config array
	 * ]
	 */
	function modifyDoiListPanelConfig($hookName, $args) {
		$config = &$args[0];

		$config['crossrefPluginEnabled'] = true;
		$config['filters'][] = [
			'heading' => 'Crossref Deposit Status',
			'filters' => [
				[
					'title' => 'Not deposited',
					'param' => 'crossrefStatus',
					'value' => 'notDeposited'
				],
				[
					'title' => 'Active',
					'param' => 'crossrefStatus',
					'value' => 'registered'
				],
				[
					'title' => 'Failed',
					'param' => 'crossrefStatus',
					'value' => 'failed'
				],
				[
					'title' => 'Marked Active',
					'param' => 'crossrefStatus',
					'value' => 'markedRegistered'
				],
			]
		];
	}

	/**
	 * Collect and sanitize request params for submissions API endpoint
	 *
	 * @param $hookname string
	 * @param $args array [
	 * 		@option array $returnParams
	 * 		@option SlimRequest $slimRequest
	 * ]
	 *
	 * @return array
	 */
	public function modifyAPISubmissionsParams($hookname, $args)
	{
		$returnParams = & $args[0];
		$slimRequest = $args[1];
		$requestParams = $slimRequest->getQueryParams();

		foreach ($requestParams as $param => $value) {
			switch ($param) {
				// Always convert crossrefStatus to array
				case 'crossrefStatus':
					if (is_string($value) && strpos($value, ',') > -1) {
						$value = explode(',', $value);
					} elseif (!is_array($value)) {
						$value = [$value];
					}
					// TODO: If using int, must map with array_map('intval', $value)
					$returnParams[$param] = $value;
					break;
			}
		}
	}

	/**
	 * Run app-specific query builder methods for getMany
	 *
	 * @param $args array [
	 * 		@option \APP\Services\QueryBuilders\SubmissionQueryBuilder
	 * 		@option int Context ID
	 * 		@option array Request args
	 * ]
	 *
	 * @return \APP\Services\QueryBuilders\SubmissionQueryBuilder
	 */
	public function modifySubmissionQueryBuilder($hookname, $args)
	{
		// This is for modifying the query builder, i.e. to add crossref::status as a filter
		$submissionQB = & $args[0];
		$queryArgs = $args[1];

		if (!empty($queryArgs['crossrefStatus'])) {
			$crossrefStatus = $queryArgs['crossrefStatus'];
			$submissionQB->crossrefStatus = $crossrefStatus;
		}
	}

	/**
	 * Add app-specific query statements to the submission getMany query
	 *
	 * @param $args array [
	 * 		@option object $queryObject
	 * 		@option \APP\Services\QueryBuilders\SubmissionQueryBuilder $queryBuilder
	 * ]
	 *
	 * @return object
	 */
	public function modifySubmissionQueryObject($hookname, $args)
	{
		// Include desired query into the query objects, i.e. filter on crossref::status
		$queryObject = & $args[0];
		$queryBuilder = $args[1];

		if (!empty($queryBuilder->crossrefStatus)) {
			$crossrefStatus = $queryBuilder->crossrefStatus;

			$queryObject->leftJoin('submission_settings as pss', function ($queryObject) {
				$queryObject->on('pss.submission_id', '=', 's.submission_id');
				$queryObject->on('pss.setting_name', '=', DB::raw("'crossref::status'"));
			});

			// Items not deposited are null in DB, so first check for notDepsited filter and remove from array
			$useNotDeposited = false;
			if (in_array('notDeposited', $crossrefStatus)) {
				$toRemove = ['notDeposited'];
				$crossrefStatus = array_values(array_diff($crossrefStatus, $toRemove));
				$useNotDeposited = true;
			}

			// If the remaining crossrefStatus array is not empty,
			// add it along with notDeposited null query if present
			$queryObject->where(function ($queryObject) use ($crossrefStatus, $useNotDeposited) {
				if (!empty($crossrefStatus)) {
					if ($useNotDeposited) {
						$queryObject->whereNull('pss.setting_value');
						$queryObject->orWhere(function ($queryObject) use ($crossrefStatus) {
							$queryObject->whereIn('pss.setting_value', $crossrefStatus);
						});
					} else {
						$queryObject->whereIn('pss.setting_value', $crossrefStatus);
					}
				} else {
					// Otherwise if notDeposited was the only filter in crossrefStatus,
					// add the null query
					if ($useNotDeposited) {
						$queryObject->whereNull('pss.setting_value');
					}
				}
			});
		}
	}

	/**
	 * Deposit or export submission metadata for Crossref
	 *
	 * @param Slim\Http\Request $slimRequest Request Slim request object
	 * @param APIResponse $response object
	 * @param array $args arguments
	 * @return APIResponse
	 */
	function executeCrossRefExportAction($slimRequest, $response, $args) {
		$request = $this->getRequest();

		// Get action
		// TODO: Check that only one action has been requested
//		$exportAction = $request->getUserVar('action');
//		$validActions = [
//			PubObjectsExportPlugin::EXPORT_ACTION_EXPORT,
//			PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED,
//			PubObjectsExportPlugin::EXPORT_ACTION_DEPOSIT
//		];
//		if (!in_array($exportAction, $validActions)) {
//			return $response->withStatus(406)->withJsonError('api.crossref.406.noActionIncluded');
//		}

		$rawSubmissionIds = $request->getUserVar('submissionIds');
		if (empty($rawSubmissionIds)) {
			return $response->withStatus(406)->withJsonError('api.crossref.406.noSubmissionsIncluded');
		} else if (is_string($rawSubmissionIds)) {
			$rawSubmissionIds = explode(',', $rawSubmissionIds);
		} else if (!is_array($rawSubmissionIds)) {
			$rawSubmissionIds = array($rawSubmissionIds);
		}
		$submissionIds = array_map('intval', $rawSubmissionIds);

		$context = $request->getContext();
		$exportActionArgs = [
			'submissionIds' => $submissionIds
		];

		$this->_getExportPlugin()->prepareAndExportPubObjects($request, $context, $exportActionArgs);

		return $response->withStatus(200);
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $verb) {
		$router = $request->getRouter();
		return array_merge(
			$this->getEnabled() ? array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			) : array(),
			parent::getActions($request, $verb)
		);
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	public function manage($args, $request) {
		switch ($request->getUserVar('verb')) {

			// Return a JSON response containing the
			// settings form
			case 'settings':
				$this->import('classes.form.CrossRefSettingsForm');
				$form = new CrossRefSettingsForm($this->_getExportPlugin(), $request->getContext()->getId());
				$form->initData();

				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}


//	function display() {
//		$temp = true;
//	}
//
//	function getPubIdDisplayType() {
//		return $this->_getExportPlugin()->getPubIdDisplayType();
//	}
//
//	function getStatusNames() {
//		return $this->_getExportPlugin()->getStatusNames();
//	}
//
//	function getPubIdType() {
//		return $this->_getExportPlugin()->getPubIdType();
//	}
//
//	function getDepositStatusSettingName() {
//		return $this->_getExportPlugin()->getDepositStatusSettingName();
//	}
//
//	function getStatusActions($pubObject) {
//		return $this->_getExportPlugin()->getStatusActions($pubObject);
//	}

	private function _getExportPlugin() {
		if (empty($this->_exportPlugin)) {
			$pluginCategory = 'importexport';
			$pluginPathName = 'CrossRefExportPlugin';
			$this->_exportPlugin = PluginRegistry::getPlugin($pluginCategory, $pluginPathName);
		}
		return $this->_exportPlugin;
	}
}
