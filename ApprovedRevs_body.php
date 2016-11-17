<?php

/**
 * Main class for the Approved Revs extension.
 *
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 */
class ApprovedRevs {

	// Static arrays to prevent querying the database more than necessary.
	static $mApprovedContentForPage = array();
	static $mApprovedRevIDForPage = array();
	static $mApprovedFileInfo = array();
	static $mUserCanApprove = null;

	static $permissions = null;
	static $mUserGroups = null;
	static $currentTitle = null;
	static $currentUser = null;
	static $bannedNamespaceIds = array( NS_FILE, NS_MEDIAWIKI, NS_CATEGORY );

	/**
	 * Gets the approved revision ID for this page, or null if there isn't
	 * one.
	 */
	public static function getApprovedRevID( $title ) {
		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedRevIDForPage ) ) {
			return self::$mApprovedRevIDForPage[$pageID];
		}

		if ( ! self::pageIsApprovable( $title ) ) {
			return null;
		}

		return self::getApprovedRevIDfromDB( $pageID );
	}

	public static function getApprovedRevIDfromDB ( $pageID ) {

		$dbr = wfGetDB( DB_SLAVE );
		$revID = $dbr->selectField(
			'approved_revs', 'rev_id', array( 'page_id' => $pageID )
		);
		return self::$mApprovedRevIDForPage[$pageID] = $revID;

	}


	/**
	 * Returns whether or not this page has a revision ID.
	 */
	public static function hasApprovedRevision( $title ) {
		$revisionId = self::getApprovedRevID( $title );
		return ( ! empty( $revisionId ) );
	}

	/**
	 * Returns the contents of the specified wiki page, at either the
	 * specified revision (if there is one) or the latest revision
	 * (otherwise).
	 */
	public static function getPageText( $title, $revisionID = null ) {
		$revision = Revision::newFromTitle( $title, $revisionID );
		return $revision->getContent()->getNativeData();
	}

	/**
	 * Returns the content of the approved revision of this page, or null
	 * if there isn't one.
	 */
	public static function getApprovedContent( $title ) {
		$pageID = $title->getArticleID();
		if ( array_key_exists( $pageID, self::$mApprovedContentForPage ) ) {
			return self::$mApprovedContentForPage[$pageID];
		}

		$revisionID = self::getApprovedRevID( $title );
		if ( empty( $revisionID ) ) {
			return null;
		}
		$text = self::getPageText( $title, $revisionID );
		self::$mApprovedContentForPage[$pageID] = $text;
		return $text;
	}

	/**
	 * Helper function - returns whether the user is currently requesting
	 * a page via the simple URL for it - not specfying a version number,
	 * not editing the page, etc.
	 */
	public static function isDefaultPageRequest() {
		global $wgRequest;
		if ( $wgRequest->getCheck( 'oldid' ) ) {
			return false;
		}
		// check if it's an action other than viewing
		if ( $wgRequest->getCheck( 'action' ) &&
			$wgRequest->getVal( 'action' ) != 'view' &&
			$wgRequest->getVal( 'action' ) != 'purge' &&
			$wgRequest->getVal( 'action' ) != 'render' ) {
				return false;
		}
		return true;
	}

	/**
	 * Returns whether this page can be approved - either because it's in
	 * a supported namespace, or because it's been specially marked as
	 * approvable. Also stores the boolean answer as a field in the page
	 * object, to speed up processing if it's called more than once.
	 */
	public static function pageIsApprovable( Title $title ) {
		// if this function was already called for this page, the
		// value should have been stored as a field in the $title object
		if ( isset( $title->isApprovable ) ) {
			return $title->isApprovable;
		}

		if ( ! $title->exists() ) {
			$title->isApprovable = false;
			return false;
		}

		// Allow custom setting of whether the page is approvable.
		if (
			!Hooks::run(
				'ApprovedRevsPageIsApprovable', array( $title, &$isApprovable )
			)
		) {
			$title->isApprovable = $isApprovable;
			return $title->isApprovable;
		}

		// Even if File, Category, etc are in ApprovedRevs::$permissions, those
		// pages* cannot be approved. isApprovable = false if in banned NS.
		//   * File pages cannot be approved, but the media itself can
		if ( in_array( $title->getNamespace(), self::$bannedNamespaceIds ) ) {
			$title->isApprovable = false;
			return false;
		}

		// Check if ApprovedRevs::$permissions defines approvals for $title
		if ( self::titleInApprovedRevsPermissions( $title ) ) {
			$title->isApprovable = true;
			return true;
		}



		// @deprecated: in v1.0.0+ per-page permissions should not be handled
		// using the __APPROVEDREVS__ magic word. Instead add a category like
		// [[Category:Requires approval]]. In a future version this should be
		// removed.
		// ------------------------------------------------------------------
		// Page doesn't satisfy ApprovedRevs::$permissions, so next
		// check for the page property - for some reason, calling the standard
		// getProperty() function doesn't work, so we just do a DB query on
		// the page_props table
		if ( self::pageHasMagicWord( $title ) )
			return $title->isApprovable = true;

		// if a page already has an approval, it must be considered approvable
		// in order for the user to be able to view/modify approvals. Though
		// this wasn't the case in versions of ApprovedRevs before v1.0, it is
		// necessary now since approvability can change much more easily
		if ( self::getApprovedRevIDfromDB( $title->getArticleID() ) ) {
			$title->isApprovable = true;
			return true;
		}
		else {
			$title->isApprovable = false;
			return false;
		}

	}

	public static function titleInApprovedRevsPermissions ( Title $title ) {

		$perms = self::getPermissions();

		// title in namespace permissions
		if ( in_array(
				$title->getNamespace(),
				array_keys( $perms['Namespace Permissions'] )
		) ) {
			return true;
		}

		$titleApprovableCategories =
			array_intersect(
				self::getCategoryList( $title ),
				array_keys( $perms['Category Permissions'] )
			);

		// title in category permissions
		if ( count( $titleApprovableCategories ) > 0 ) {
			return true;
		}

		// title in page permissions and is in main namespace
		if ( in_array(
			$title->getText(),
			array_keys( $perms['Page Permissions'] )
		) ) {
			return true;
		}

		// title in page permissions in another namespace
		else if ( in_array(
			$title->getNsText() . ':' . $title->getText(),
			array_keys( $perms['Page Permissions'] )
		) ) {
			return true;
		}

		return false;

	}

	public static function fileIsApprovable ( Title $title ) {

		// title doesn't exist, not approvable
		if ( ! $title->exists() ) {
			return $title->isApprovable = false;
		}

		// Check if ApprovedRevs::$permissions defines approvals for $title
		if ( self::titleInApprovedRevsPermissions( $title ) ) {
			$title->isApprovable = true;
			return true;
		}

		// if a file already has an approval, it must be considered approvable
		// in order for the user to be able to view/modify approvals. Though
		// this wasn't the case on versions of ApprovedRevs before v1.0, it is
		// necessary now since approvability can change much more easily

		// if title in approved_revs_files table
		list( $timestamp, $sha1 ) =
			self::getApprovedFileInfo( $title );
		if ( $timestamp !== false ) {
			// only approvable because it already has an approved rev, not
			// because it is in ApprovedRevs::$permissions
			$title->isApprovable = true;
			return true;
		}
		else {
			$title->isApprovable = false;
			return false;
		}

	}

	// check if page has __APPROVEDREVS__
	public static function pageHasMagicWord ( $title ) {

		// It's not in an included namespace, so check for the page
		// property - for some reason, calling the standard
		// getProperty() function doesn't work, so we just do a DB
		// query on the page_props table.
		$dbr = wfGetDB( DB_SLAVE );
		$res = $dbr->select( 'page_props', 'COUNT(*)',
			array(
				'pp_page' => $title->getArticleID(),
				'pp_propname' => 'approvedrevs',
				'pp_value' => 'y'
			)
		);
		$row = $dbr->fetchRow( $res );
		if ( $row[0] == '1' ) {
			return true;
		}
		else {
			return false;
		}
	}

	public static function userCanApprove ( $title, $user=false ) {

		// set title and user for permissions checks
		self::$currentTitle = $title;
		if ( ! $user ) {
			global $wgUser;
			self::$currentUser = $wgUser;
		}
		else {
			self::$currentUser = $user;
		}

		// $mUserCanApprove is a static variable used for "caching" the result
		// of this function, so the logic only has to be executed once.
		if ( isset( self::$mUserCanApprove ) )
			return self::$mUserCanApprove;

		$pageNamespace  = self::$currentTitle->getNamespace();
		$pageCategories = self::getCategoryList( self::$currentTitle );
		$pageFullName   = self::$currentTitle->getText();

		$permissions = self::getPermissions();

		if ( self::checkIfUserInPerms( $permissions['All Pages'] ) ) {
			return self::$mUserCanApprove;
		}

		foreach (
			$permissions['Namespace Permissions'] as
			$namespace => $whoCanApprove
		) {
			if ( $namespace === $pageNamespace ) {
				self::checkIfUserInPerms( $whoCanApprove );
			}
		}

		foreach (
			$permissions['Category Permissions'] as
			$category => $whoCanApprove
		) {
			if ( in_array( $category, $pageCategories ) ) {
				self::checkIfUserInPerms( $whoCanApprove );
			}
		}

		foreach (
			$permissions['Page Permissions'] as
			$page => $whoCanApprove
		) {
			if ( $page == $pageFullName ) {
				self::checkIfUserInPerms( $whoCanApprove );
			}
		}

		if (
			self::usernameIsBasePageName(
				self::$currentUser, self::$currentTitle
			)
		) {
			self::$mUserCanApprove = true;
		}

		return self::$mUserCanApprove;
	}

	public static function saveApprovedRevIDInDB( $title, $rev_id ) {
		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$old_rev_id =
			$dbr->selectField(
				'approved_revs', 'rev_id',
				array( 'page_id' => $page_id )
			);
		if ( $old_rev_id ) {
			$dbr->update(
				'approved_revs',
				array( 'rev_id' => $rev_id ),
				array( 'page_id' => $page_id )
			);
		} else {
			$dbr->insert(
				'approved_revs',
				array( 'page_id' => $page_id, 'rev_id' => $rev_id )
			);
		}
		// Update "cache" in memory
		self::$mApprovedRevIDForPage[$page_id] = $rev_id;
	}

	static function setPageSearchText( $title, $text ) {
		DeferredUpdates::addUpdate(
			new SearchUpdate( $title->getArticleID(),
				$title->getText(), $text )
		);
	}

	/**
	 * Sets a certain revision as the approved one for this page in the
	 * approved_revs DB table; calls a "links update" on this revision
	 * so that category information can be stored correctly, as well as
	 * info for extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function setApprovedRevID(
		$title, $rev_id, $is_latest = false ) {
		self::saveApprovedRevIDInDB( $title, $rev_id );
		$parser = new Parser();

		// If the revision being approved is definitely the latest
		// one, there's no need to call the parser on it.
		if ( !$is_latest ) {
			$parser->setTitle( $title );
			$text = self::getPageText( $title, $rev_id );
			$options = new ParserOptions();
			$parser->parse( $text, $title, $options, true, true, $rev_id );
			$u = new LinksUpdate( $title, $parser->getOutput() );
			$u->doUpdate();
			self::setPageSearchText( $title, $text );
		}

		$log = new LogPage( 'approval' );
		$rev_url = $title->getFullURL( array( 'oldid' => $rev_id ) );
		$rev_link = Xml::element(
			'a',
			array( 'href' => $rev_url ),
			$rev_id
		);
		$logParams = array( $rev_link );
		$log->addEntry(
			'approve',
			$title,
			'',
			$logParams
		);

		Hooks::run(
			'ApprovedRevsRevisionApproved', array( $parser, $title, $rev_id )
		);
	}

	public static function deleteRevisionApproval( $title ) {
		$dbr = wfGetDB( DB_MASTER );
		$page_id = $title->getArticleID();
		$dbr->delete( 'approved_revs', array( 'page_id' => $page_id ) );
	}

	/**
	 * Unsets the approved revision for this page in the approved_revs DB
	 * table; calls a "links update" on this page so that category
	 * information can be stored correctly, as well as info for
	 * extensions such as Semantic MediaWiki; and logs the action.
	 */
	public static function unsetApproval( $title ) {
		global $egApprovedRevsBlankIfUnapproved;

		self::deleteRevisionApproval( $title );

		$parser = new Parser();
		$parser->setTitle( $title );
		if ( $egApprovedRevsBlankIfUnapproved ) {
			$text = '';
		} else {
			$text = self::getPageText( $title );
		}
		$options = new ParserOptions();
		$parser->parse( $text, $title, $options );
		$u = new LinksUpdate( $title, $parser->getOutput() );
		$u->doUpdate();
		self::setPageSearchText( $title, $text );

		$log = new LogPage( 'approval' );
		$log->addEntry(
			'unapprove',
			$title,
			''
		);

		Hooks::run(
			'ApprovedRevsRevisionUnapproved', array( $parser, $title )
		);
	}

	public static function addCSS() {
		global $wgOut;
		$wgOut->addModuleStyles( 'ext.ApprovedRevs' );
	}

	/**
	 * Helper function for backward compatibility.
	 */
	public static function makeLink( $linkRenderer, $title, $msg = null, $attrs = array(), $params = array() ) {
		if ( !is_null( $linkRenderer ) ) {
			// MW 1.28+
			return $linkRenderer->makeLink( $title, $msg, $attrs, $params );
		} else {
			return Linker::linkKnown( $title, $msg, $attrs, $params );
		}
	}

	// setup permissions and fill in any defaults
	public static function getPermissions () {

		if ( self::$permissions ) {
			return self::$permissions;
		}

		global $egApprovedRevsPermissions;
		self::$permissions = $egApprovedRevsPermissions;

		$permissionsZones = array(
			'Namespace Permissions', 'Category Permissions', 'Page Permissions'
		);
		foreach ( $permissionsZones as $zone ) {

			// for each subzone, such as NS_MAIN within namespaces, or
			// "Category:Approval Required" within categories, or "Main Page"
			// within pages...for each of these format the permissions for
			// simpler logic later
			foreach ( self::$permissions[$zone] as $subzone => $subzonePerms ) {
				self::$permissions[$zone][$subzone] =
					self::formatPermissionTypes( $subzonePerms );
			}
		}

		// perform the same formatting on the All Pages permissions
		self::$permissions['All Pages'] =
			self::formatPermissionTypes( self::$permissions['All Pages'] );

		return self::$permissions;

	}

	public static function formatPermissionTypes ( $permArray ) {

		// 'creator' not included since it's bool
		$permissionsTypes = array( 'group', 'user', 'property' );

		foreach ( $permissionsTypes as $type ) {

			// if zone-type additional approvers set to null, convert to
			// empty array
			if ( ! isset( $permArray[$type] ) ) {
				// this just makes the logic easier later
				$permArray[$type] = array();
			}

			// if set to string, wrap that string in an array
			else if ( is_string( $permArray[$type] ) ) {
				$permArray[$type] = array(
					$permArray[$type]
				);
			}

		}

		if ( ! isset( $permArray['creator'] ) ) {
			$permArray['creator'] = false;
		}

		// default is category permissions override namespace, page override
		// category. Specifically, anything later in $egApprovedRevsPermissions
		// overrides prior entries (unless override is explicitly set to false)
		if ( ! isset( $permArray['override'] ) ) {
			$permArray['override'] = true;
		}

		return $permArray;

	}

	/**
	 * @since 1.0.0
	 *
	 * @param array $whoCanApprove array of who can approve,
	 * @return boolean: Whether or not the user has permission to
	 *                  approve the page
	 *
	 * $whoCanApprove is like:
	 * array(
	 * 		'group' => array('editors', 'management'),
	 * 		'user' => array('John'),
	 * 		'creator' => true,
	 * 		'property' => 'Subject matter expert',
	 * 		'override' => false // <-- this is irrelevant within this function
	 * )
	 */
	public static function checkIfUserInPerms( $whoCanApprove ) {

		// $whoCanApprove['override'] determines whether or not this pass
		// through checkIfUserInPerms() will override previous passes. If this
		// isn't going to overwrite other permissions, and other permissions
		// say the user can approve, no need to check further.
		if (
			$whoCanApprove['override'] == false &&
			self::$mUserCanApprove == true
		) {
			return self::$mUserCanApprove;
		}

		$userGroups = array_map(
			'strtolower' , self::$currentUser->getGroups()
		);

		// check if user is the page creator
		if ( $whoCanApprove['creator'] === true && self::isPageCreator() ) {
			self::$mUserCanApprove = true;
			return self::$mUserCanApprove;
		}

		// check if the user is in any of the listed groups
		foreach ( $whoCanApprove['group'] as $group ) {
			if ( in_array( strtolower( $group ), $userGroups ) ) {
				self::$mUserCanApprove = true;
				return self::$mUserCanApprove;
			}
		}

		// check if the user is in the list of users
		foreach ( $whoCanApprove['user'] as $user ) {
			if (
				strtolower( $user ) ===
				strtolower( self::$currentUser->getName() )
			) {
				self::$mUserCanApprove = true;
				return self::$mUserCanApprove;
			}
		}

		// check if the user is set as the value of any SMW properties
		// (if SMW enabled)
		foreach ( $whoCanApprove['property'] as $property ) {
			if ( self::smwPropertyEqualsCurrentUser( $property ) ) {
				self::$mUserCanApprove = true;
				return self::$mUserCanApprove;
			}
		}

		// At this point self::$mUserCanApprove was not set to TRUE in this
		// call to this method, and thus from the perspective of just this call
		// to this method FALSE should be returned. Previous calls to this
		// method are irrelevant because if self::$mUserCanApprove was TRUE
		// and $whoCanApprove['override'] was FALSE this call to this method
		// would already have returned TRUE in the first if-block at the top.
		// This could be overridden in subsequent calls to this method.
		self::$mUserCanApprove = false;
		return self::$mUserCanApprove;

	}

	// return true if self::$currentUser created self::$currentTitle
	public static function isPageCreator () {
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			array( 'revision', 'page' ),
			'revision.rev_user_text',
			array( 'page.page_title' => self::$currentTitle->getDBkey() ),
			null,
			array( 'ORDER BY' => 'revision.rev_id ASC' ),
			array( 'revision' =>
				array( 'JOIN', 'revision.rev_page = page.page_id' )
			)
		);
		return $row->rev_user_text == self::$currentUser->getName();
	}

	// Determines if username is the base pagename, e.g. if user is
	// User:Jamesmontalvo3 then this returns true for pages named
	// User:Jamesmontalvo3, User:Jamesmontalvo3/Subpage, etc
	// This is for use when the User or User_talk namespaces are used in
	// $egApprovedRevsPermissions.
	public static function usernameIsBasePageName () {

		if (
			self::$currentTitle->getNamespace() == NS_USER ||
			self::$currentTitle->getNamespace() == NS_USER_TALK
		) {
			// explode on slash to just get the first part (if it is a subpage)
			// as far as I know usernames cannot have slashes in them, so this
			// should be okay
			$titleSubpageParts = explode( '/', self::$currentTitle->getText() );

			return $titleSubpageParts[0] == self::$currentUser->getName();

		}
		return false;
	}

	public static function getCategoryList ( $title ) {
		$catTree = $title->getParentCategoryTree();
		return array_unique( self::getCategoryListHelper( $catTree ) );
	}

	public static function getCategoryListHelper ( $catTree ) {

		$categoryNames = array(); // array of categories w/o tree structure
		foreach ( $catTree as $cat => $parentCats ) {
			$catParts = explode( ':', $cat, 2 ); // best var name ever!
			$categoryNames[] = str_replace( '_', ' ', $catParts[1] );
			// @todo: anything besides _ need to be replaced?
			if ( count( $parentCats ) > 0 ) {
				array_merge(
					$categoryNames,
					self::getCategoryListHelper( $parentCats )
				);
			}
		}
		return $categoryNames;

	}

	public static function smwPropertyEqualsCurrentUser ( $userProperty ) {

		if ( ! defined( 'SMW_VERSION' ) ) {
			return false; // SMW not installed, return false to ignore
		}
		else {
			$valueDis = smwfGetStore()->getPropertyValues(
				new SMWDIWikiPage(
					self::$currentTitle->getDBkey(),
					self::$currentTitle->getNamespace(), ''
				),
				new SMWDIProperty(
					SMWPropertyValue::makeUserProperty( $userProperty )->
					getDBkey() // trim($userProperty)
				)
			);

			foreach ( $valueDis as $valueDI ) {
				if ( ! $valueDI instanceof SMWDIWikiPage ) {
					throw new Exception( 'ApprovedRevs "Property" ' .
						'permissions must use Semantic MediaWiki ' .
						'properties of type "Page"' );
				}
				if (
					$valueDI->getTitle()->getText() ==
					self::$currentUser->getUserPage()->getText()
				) {
					return true;
				}
			}
		}
		return false;
	}



	public static function setApprovedFileInDB ( $title, $timestamp, $sha1 ) {

		$parser = new Parser();
		$parser->setTitle( $title );

		$dbr = wfGetDB( DB_MASTER );
		$fileTitle = $title->getDBkey();
		$oldFileTitle = $dbr->selectField(
			'approved_revs_files', 'file_title',
			array( 'file_title' => $fileTitle )
		);
		if ( $oldFileTitle ) {
			$dbr->update( 'approved_revs_files',
				array(
					'approved_timestamp' => $timestamp,
					'approved_sha1' => $sha1
				), // update fields
				array( 'file_title' => $fileTitle )
			);
		} else {
			$dbr->insert( 'approved_revs_files',
				array(
					'file_title' => $fileTitle,
					'approved_timestamp' => $timestamp,
					'approved_sha1' => $sha1
				)
			);
		}
		// Update "cache" in memory
		self::$mApprovedFileInfo[$fileTitle] = array( $timestamp, $sha1 );

		$log = new LogPage( 'approval' );

		$imagepage = ImagePage::newFromID( $title->getArticleID() );
		$displayedFileUrl = $imagepage->getDisplayedFile()->getFullURL();

		$revisionAnchorTag = Xml::element(
			'a',
			array(
				'href' => $displayedFileUrl,
				'title' => 'unique identifier: ' . $sha1
			), substr( $sha1, 0, 8 ) // show first 6 characters of sha1
		);
		$logParams = array( $revisionAnchorTag );
		$log->addEntry(
			'approve',
			$title,
			'',
			$logParams
		);

		wfRunHooks(
			'ApprovedRevsFileRevisionApproved',
			array( $parser, $title, $timestamp, $sha1 )
		);

	}

	public static function unsetApprovedFileInDB ( $title ) {

		$parser = new Parser();
		$parser->setTitle( $title );

		$fileTitle = $title->getDBkey();

		$dbr = wfGetDB( DB_MASTER );
		$dbr->delete( 'approved_revs_files',
			array( 'file_title' => $fileTitle )
		);
		// the unapprove page method had LinksUpdate and Parser
		// objects here, but the page text has not changed at all with
		// a file approval, so I don't think those are necessary.

		$log = new LogPage( 'approval' );
		$log->addEntry(
			'unapprove',
			$title,
			''
		);

		wfRunHooks(
			'ApprovedRevsFileRevisionUnapproved', array( $parser, $title )
		);

	}

	/**
	 *  Pulls from DB table approved_revs_files which revision of a
	 *  file, if any besides most recent, should be used as the
	 *  approved revision.
	 **/
	public static function getApprovedFileInfo ( $fileTitle ) {

		if ( isset( self::$mApprovedFileInfo[ $fileTitle->getDBkey() ] ) ) {
			return self::$mApprovedFileInfo[ $fileTitle->getDBkey() ];
		}

		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow(
			'approved_revs_files', // select from table
			array( 'approved_timestamp', 'approved_sha1' ),
			array( 'file_title' => $fileTitle->getDBkey() )
		);
		if ( $row ) {
			$return = array( $row->approved_timestamp, $row->approved_sha1 );
		}
		else {
			$return = array( false, false );
		}

		self::$mApprovedFileInfo[ $fileTitle->getDBkey() ] = $return;
		return $return;

	}

	public static function getApprovabilityStringsForDB () {

		$perms = self::getPermissions();

		$approvableNamespaceIdString =
			implode( ',' , array_keys( $perms['Namespace Permissions'] ) );


		$dbSafeCategoryNames = array();
		foreach ( array_keys( $perms['Category Permissions'] ) as $category ) {
			$mwCategoryObject = Category::newFromName( $category );

			// cannot use category IDs like with pages and namespaces. Instead
			// need to create database-safe SQL column names. Columns in same
			// form as categorylinks.cl_to
			// FIXME: replaced mysql_real_escape_string with addcslashes with escaping on \, " and '
			// mysql_real_escape_string also escapes \x00, \n, \r and \x1a. Do we need these? Not
			// likely since $mwCategoryObject is constructed from Category::newFromName.
			$dbSafeCategoryNames[] = "'" . addcslashes( $mwCategoryObject->getName(), "\\\"'" ) . "'";
		}
		$dbSafeCategoryNamesString = implode( ',', $dbSafeCategoryNames );


		$approvablePageIds = array();
		foreach ( array_keys( $perms['Page Permissions'] ) as $page ) {
			$title = Title::newFromText( $page );
			$approvablePageIds[] = $title->getArticleID();
		}
		$approvablePageIdString = implode( ',', $approvablePageIds );

		return array(
			$approvableNamespaceIdString,
			$dbSafeCategoryNamesString,
			$approvablePageIdString,
		);
	}
}
