<?php

/**
 * @file controllers/listbuilder/settings/MastheadMembershipListbuilderHandler.inc.php
 *
 * Copyright (c) 2000-2009 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class MastheadMembershipListbuilderHandler
 * @ingroup listbuilder
 *
 * @brief Class for adding new Press Divisions
 */

import('controllers.listbuilder.settings.SetupListbuilderHandler');

class MastheadMembershipListbuilderHandler extends SetupListbuilderHandler {
	/** @var The group ID for this listbuilder */
	var $groupId;

	/**
	 * Constructor
	 */
	function PressDivisionsListbuilderHandler() {
		parent::SetupListbuilderHandler();
		$this->addRoleAssignment(ROLE_ID_PRESS_MANAGER, 'getAutocompleteSource');
	}

	function setGroupId($groupId) {
		$this->groupId = $groupId;
	}

	function getGroupId() {
		return $this->groupId;
	}

	/* Load the list from an external source into the grid structure */
	function loadList(&$request) {
		$press =& $this->getPress();
		$groupId = $this->getGroupId();

		$groupMembershipDao =& DAORegistry::getDAO('GroupMembershipDAO');

		$memberships =& $groupMembershipDao->getMemberships($groupId);

		$items = array();
		while ($membership =& $memberships->next()) {
			$user =& $membership->getUser();
			$id = $user->getId();
			$items[$id] = array('item' => $user->getFullName(), 'attribute' => $user->getUsername());
			unset($membership);
		}
		$this->setGridDataElements($items);
	}


	/* Get possible items to populate autosuggest list with */
	function getPossibleItemList(&$request) {
		$pressDao =& DAORegistry::getDAO('PressDAO');
		$roleDao =& DAORegistry::getDAO('RoleDAO');

		$press =& $this->getPress();

		// Get items to populate possible items list with
		$userGroupDao =& DAORegistry::getDAO('UserGroupDAO');
		$users =& $userGroupDao->getUsersByContextId($press->getId());

		$itemList = array();
		while($user =& $users->next()) {
			$itemList[$user->getId()] = array(
			 	'name' => $user->getFullName(),
			 	'abbrev' => $user->getUsername()
			);
			unset($user);
		}
		return $itemList;
	}


	//
	// Overridden template methods
	//
	/**
	 * Need to add additional data to the template via the fetch method
	 * @see Form::fetch()
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function fetch($args, &$request) {
		$router =& $request->getRouter();
		$groupId = $request->getUserVar('groupId');

		$additionalVars = array('itemId' => $groupId,
			'addUrl' => $router->url($request, array(), null, 'addItem', null, array('groupId' => $groupId)),
			'deleteUrl' => $router->url($request, array(), null, 'deleteItems', null, array('groupId' => $groupId)),
			'autocompleteUrl' => $router->url($request, array(), null, 'getAutocompleteSource', null)
		);

		return parent::fetch($args, &$request, $additionalVars);
	}

	/*
	 * Configure the grid
	 * @param PKPRequest $request
	 */
	function initialize(&$request) {
		parent::initialize($request);

		// Basic configuration
		$this->setTitle('manager.groups.membership.addMember');
		$this->setSourceTitle('common.user');
		$this->setSourceType(LISTBUILDER_SOURCE_TYPE_BOUND);
		$this->setListTitle('manager.groups.existingUsers');

		$this->setGroupId($request->getUserVar('groupId'));

		$this->loadList($request);

		$this->addColumn(new ListbuilderGridColumn($this, 'item', 'common.name'));
	}

	/**
	 * @see PKPHandler::setupTemplate()
	 */
	function setupTemplate() {
		parent::setupTemplate();
		Locale::requireComponents(array(LOCALE_COMPONENT_PKP_MANAGER));
	}

	//
	// Public AJAX-accessible functions
	//

	/*
	 * Fetch either a block of data for local autocomplete, or return a URL to another function for AJAX autocomplete
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function getAutocompleteSource($args, &$request) {
		//FIXME: add validation here?
		$this->setupTemplate();

		$sourceArray = $this->getPossibleItemList($request);

		$sourceJson = new JSONMessage(true, null, false, 'local');
		$sourceContent = array();
		foreach ($sourceArray as $id => $item) {
			// The autocomplete code requires the JSON data to use 'label' as the array key for labels, and 'value' for the id
			$additionalAttributes = array(
				'label' =>  sprintf('%s (%s)', $item['name'], $item['abbrev']),
				'value' => $id
			);
			$itemJson = new JSONMessage(true, '', false, null, $additionalAttributes);
			$sourceContent[] = $itemJson->getString();

			unset($itemJson);
		}
		$sourceJson->setContent('[' . implode(',', $sourceContent) . ']');

		return $sourceJson->getString();
	}

	/*
	 * Handle adding an item to the list
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function addItem($args, &$request) {
		$this->setupTemplate();
		$publicationFormatDao =& DAORegistry::getDAO('PublicationFormatDAO');
		$press =& $this->getPress();

		$groupId = $args['groupId'];
		$index = 'sourceId-' . $this->getId() . '-' . $groupId;
		$userId = $args[$index];

		if(empty($userId)) {
			$json = new JSONMessage(false, Locale::translate('common.listbuilder.completeForm'));
			return $json->getString();
		} else {
			$groupMembershipDao =& DAORegistry::getDAO('GroupMembershipDAO');

			$groupMembership =& $groupMembershipDao->getMembership($groupId, $userId);
			// Make sure the membership doesn't already exist
			if (isset($groupMembership)) {
				$json = new JSONMessage(false, Locale::translate('common.listbuilder.itemExists'));
				return $json->getString();
				return false;
			}
			unset($groupMembership);

			$groupMembership = new GroupMembership();
			$groupMembership->setGroupId($request->getUserVar('groupId'));
			$groupMembership->setUserId($userId);
			// For now, all memberships are displayed in About
			$groupMembership->setAboutDisplayed(true);
			$groupMembershipDao->insertMembership($groupMembership);

			$userDao =& DAORegistry::getDAO('UserDAO');
			$user =& $userDao->getUser($userId);

			// Return JSON with formatted HTML to insert into list
			$row =& $this->getRowInstance();
			$row->setGridId($this->getId());
			$row->setId($userId);
			$rowData = array('item' => $user->getFullName(), 'attribute' => $user->getUsername());
			$row->setData($rowData);
			$row->initialize($request);

			$json = new JSONMessage(true, $this->_renderRowInternally($request, $row));
			return $json->getString();
		}
	}

	/*
	 * Handle deleting items from the list
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function deleteItems($args, &$request) {
		$groupMembershipDao =& DAORegistry::getDAO('GroupMembershipDAO');
		$groupId = array_shift($args);

		foreach($args as $userId) {
			$groupMembershipDao->deleteMembershipById($groupId, $userId);
		}

		$json = new JSONMessage(true);
		return $json->getString();
	}
}

?>
