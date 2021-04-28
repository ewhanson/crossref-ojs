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
						'roles' => [ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR],
					]
				);
				break;
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

		if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_EXPORT)) {
			// Export only
			$this->_getExportPlugin()->prepareAndExportPubObjects($request, $context, $exportActionArgs);
		} else if ($request->getUserVar(PubObjectsExportPlugin::EXPORT_ACTION_MARKREGISTERED)) {
			// Mark registered
			$this->_getExportPlugin()->prepareAndExportPubObjects($request, $context, $exportActionArgs);
		} else {
			// Export and deposit
			$this->_getExportPlugin()->prepareAndExportPubObjects($request, $context, $exportActionArgs);
		}
		return $response->withStatus(200);
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	public function getActions($request, $verb) {
		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
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
				$form = new CrossRefSettingsForm($this, $request->getContext()->getId());

				// Fetch the form the first time it loads,
				// before the user has tried to save it
				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						$notificationMgr = new NotificationManager();
						$notificationMgr->createTrivialNotification($request->getUser()->getId());
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
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
