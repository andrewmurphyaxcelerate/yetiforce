<?php
/* +**********************************************************************************
 * The contents of this file are subject to the vtiger CRM Public License Version 1.1
 * ("License"); You may not use this file except in compliance with the License
 * The Original Code is:  vtiger CRM Open Source
 * The Initial Developer of the Original Code is vtiger.
 * Portions created by vtiger are Copyright (C) vtiger.
 * All Rights Reserved.
 * Contributor(s): YetiForce.com
 * ********************************************************************************** */

class Vtiger_DashBoard_Model extends Vtiger_Base_Model
{

	/**
	 * Function to get Module instance
	 * @return Vtiger_Module_Model
	 */
	public function getModule()
	{
		return $this->module;
	}

	/**
	 * Function to set the module instance
	 * @param Vtiger_Module_Model $moduleInstance - module model
	 * @return Vtiger_DetailView_Model
	 */
	public function setModule($moduleInstance)
	{
		$this->module = $moduleInstance;
		return $this;
	}

	/**
	 *  Function to get the module name
	 *  @return string - name of the module
	 */
	public function getModuleName()
	{
		return $this->getModule()->get('name');
	}

	/**
	 * Function returns List of User's selected Dashboard Widgets
	 * @return <Array of Vtiger_Widget_Model>
	 */
	public function getDashboards($action = 1)
	{

		$currentUser = Users_Record_Model::getCurrentUserModel();
		$currentUserPrivilegeModel = Users_Privileges_Model::getCurrentUserPrivilegesModel();
		$moduleModel = $this->getModule();

		if ($action == 'Header')
			$action = 0;
		$query = (new \App\Db\Query())->select('vtiger_links.*, mdw.userid, mdw.data, mdw.active, mdw.title, mdw.size, mdw.filterid,
					mdw.id AS widgetid, mdw.position, vtiger_links.linkid AS id, mdw.limit, mdw.cache, mdw.owners, mdw.isdefault')
			->from('vtiger_links')
			->leftJoin('vtiger_module_dashboard_widgets mdw', 'vtiger_links.linkid = mdw.linkid')
			->where(['mdw.userid' => $currentUser->getId(), 'vtiger_links.linktype' => 'DASHBOARDWIDGET', 'mdw.module' => $moduleModel->getId(), 'active' => $action, 'mdw.dashboardid' => $this->get('dashboardId')]);
		$dataReader = $query->createCommand()->query();
		$widgets = [];

		while ($row = $dataReader->read()) {
			$row['linkid'] = $row['id'];
			if ($row['linklabel'] == 'Mini List') {
				if (!$row['isdeafult'])
					$row['deleteFromList'] = true;
				$minilistWidget = Vtiger_Widget_Model::getInstanceFromValues($row);
				$minilistWidgetModel = new Vtiger_MiniList_Model();
				$minilistWidgetModel->setWidgetModel($minilistWidget);
				$minilistWidget->set('title', $minilistWidgetModel->getTitle());
				$widgets[] = $minilistWidget;
			} elseif ($row['linklabel'] == 'ChartFilter') {
				if (!$row['isdeafult'])
					$row['deleteFromList'] = true;
				$charFilterWidget = Vtiger_Widget_Model::getInstanceFromValues($row);
				$chartFilterWidgetModel = new Vtiger_ChartFilter_Model();
				$chartFilterWidgetModel->setWidgetModel($charFilterWidget);
				$charFilterWidget->set('title', $chartFilterWidgetModel->getTitle());
				$widgets[] = $charFilterWidget;
			} else
				$widgets[] = Vtiger_Widget_Model::getInstanceFromValues($row);
		}

		foreach ($widgets as $index => $widget) {
			$label = $widget->get('linklabel');
			$url = $widget->get('linkurl');
			$data = $widget->get('data');
			$filterid = $widget->get('filterid');
			$module = $this->getModuleNameFromLink($url, $label);

			if ($module == 'Home' && !empty($filterid) && !empty($data)) {
				$filterData = \App\Json::decode(htmlspecialchars_decode($data));
				$module = $filterData['module'];
			}
			if (!$currentUserPrivilegeModel->hasModulePermission($module)) {
				unset($widgets[$index]);
			}
		}

		return $widgets;
	}

	/**
	 * Function to get the module name of a widget using linkurl
	 * @param string $linkUrl
	 * @param string $linkLabel
	 * @return string $module - Module Name
	 */
	public function getModuleNameFromLink($linkUrl, $linkLabel)
	{
		$params = vtlib\Functions::getQueryParams($linkUrl);
		$module = $params['module'];
		if ($linkLabel == 'Overdue Activities' || $linkLabel == 'Upcoming Activities') {
			$module = 'Calendar';
		}
		return $module;
	}

	/**
	 * Function to get the default widgets(Deprecated)
	 * @return Vtiger_Widget_Model[]
	 */
	public function getDefaultWidgets()
	{
		$moduleModel = $this->getModule();
		$widgets = [];

		return $widgets;
	}

	public function verifyDashboard($moduleName)
	{
		\App\Log::trace('Entering ' . __METHOD__ . '(' . $moduleName . ')');
		$currentUser = Users_Record_Model::getCurrentUserModel();
		$blockId = Settings_WidgetsManagement_Module_Model::getBlocksFromModule($moduleName, $currentUser->getRole(), $this->get('dashboardId'));
		if (count($blockId) == 0) {
			\App\Log::trace('Exiting ' . __METHOD__);
			return;
		}
		$dataReader = (new App\Db\Query())->select('vtiger_module_dashboard.*, vtiger_links.tabid')
				->from('vtiger_module_dashboard')
				->innerJoin('vtiger_links', 'vtiger_links.linkid = vtiger_module_dashboard.linkid')
				->where(['vtiger_module_dashboard.blockid' => $blockId])
				->createCommand()->query();
		while ($row = $dataReader->read()) {
			$row['data'] = htmlspecialchars_decode($row['data']);
			$row['size'] = htmlspecialchars_decode($row['size']);
			$row['owners'] = htmlspecialchars_decode($row['owners']);
			if (!(new App\Db\Query())->from('vtiger_module_dashboard_widgets')
					->where(['userid' => $currentUser->getId(), 'templateid' => $row['id']])
					->exists()) {
				$active = $row['isdefault'] ? 1 : 0;
				App\Db::getInstance()->createCommand()->insert('vtiger_module_dashboard_widgets', [
					'linkid' => $row['linkid'],
					'userid' => $currentUser->getId(),
					'templateid' => $row['id'],
					'filterid' => $row['filterid'],
					'title' => $row['title'],
					'data' => $row['data'],
					'size' => $row['size'],
					'limit' => $row['limit'],
					'owners' => $row['owners'],
					'isdefault' => $row['isdefault'],
					'active' => $active,
					'module' => $row['tabid'],
					'cache' => $row['cache'],
					'date' => $row['date'],
					'dashboardid' => $this->get('dashboardId')
				])->execute();
			}
		}
		\App\Log::trace('Exiting ' . __METHOD__);
	}

	/**
	 * Function to get the instance
	 * @param string $moduleName - module name
	 * @return Vtiger_DashBoard_Model
	 */
	public static function getInstance($moduleName)
	{
		$modelClassName = Vtiger_Loader::getComponentClassName('Model', 'DashBoard', $moduleName);
		$instance = new $modelClassName();
		$moduleModel = Vtiger_Module_Model::getInstance($moduleName);
		return $instance->setModule($moduleModel);
	}

	/**
	 * Function to get modules with widgets
	 * @param string $moduleName - module name
	 * @return <Array> $modules
	 */
	public static function getModulesWithWidgets($moduleName = false, $dashboard)
	{
		$currentUser = Users_Privileges_Model::getCurrentUserModel();

		$query = (new \App\Db\Query())->select('vtiger_module_dashboard_widgets.module, vtiger_module_dashboard_blocks.tabid')
			->from('vtiger_module_dashboard')
			->leftJoin('vtiger_module_dashboard_blocks', 'vtiger_module_dashboard_blocks.id = vtiger_module_dashboard.blockid')
			->leftJoin('vtiger_module_dashboard_widgets', 'vtiger_module_dashboard_widgets.templateid = vtiger_module_dashboard.id')
			->where(['userid' => $currentUser->getId(), 'vtiger_module_dashboard_widgets.dashboardid' => $dashboard])
			->orWhere(['authorized' => $currentUser->getRole()])
			->groupBy('module, tabid');
		$dataReader = $query->createCommand()->query();
		$modules = [];
		while ($row = $dataReader->read()) {
			$tabId = $row['module'] ? $row['module'] : $row['tabid'];
			if (!isset($modules[$tabId])) {
				$modules[$tabId] = vtlib\Functions::getModuleName($tabId);
			}
		}
		ksort($modules);
		if ($moduleName && ($tabId = vtlib\Functions::getModuleId($moduleName))) {
			unset($modules[$tabId]);
			$modules = array_merge([$tabId => $moduleName], $modules);
		}
		return $modules;
	}
}
