<?php

if ( !defined( 'MEDIAWIKI' ) ) die();

/**
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 */

define( 'APPROVED_REVS_VERSION', '1.0.0-alpha' );

// credits
$wgExtensionCredits['other'][] = array(
	'path'            => __FILE__,
	'name'            => 'Approved Revs',
	'version'         => APPROVED_REVS_VERSION,
	'author'          => array( 'Yaron Koren', '...' ),
	'url'             => 'https://www.mediawiki.org/wiki/Extension:Approved_Revs',
	'descriptionmsg'  => 'approvedrevs-desc'
);

// global variables
$egApprovedRevsIP = dirname( __FILE__ ) . '/';
$egApprovedRevsBlankIfUnapproved = false;
$egApprovedRevsAutomaticApprovals = true;
$egApprovedRevsShowApproveLatest = false;
$egApprovedRevsShowNotApprovedMessage = false;

// internationalization
$wgMessagesDirs['ApprovedRevs'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['ApprovedRevs'] = $egApprovedRevsIP . 'ApprovedRevs.i18n.php';
$wgExtensionMessagesFiles['ApprovedRevsAlias'] = $egApprovedRevsIP . 'ApprovedRevs.alias.php';
$wgExtensionMessagesFiles['ApprovedRevsMagic'] = $egApprovedRevsIP . 'ApprovedRevs.i18n.magic.php';

// register all classes
$wgAutoloadClasses['ApprovedRevs'] = $egApprovedRevsIP . 'ApprovedRevs_body.php';
$wgAutoloadClasses['ApprovedRevsHooks'] = $egApprovedRevsIP . 'ApprovedRevs.hooks.php';
$wgSpecialPages['ApprovedRevs'] = 'SpecialApprovedRevs';
$wgAutoloadClasses['SpecialApprovedRevs'] = $egApprovedRevsIP . 'SpecialApprovedRevs.php';
$wgAutoloadClasses['SpecialApprovedRevsPage'] = $egApprovedRevsIP . 'SpecialApprovedRevsPage.php';

// hooks
$wgHooks['ArticleEditUpdates'][] = 'ApprovedRevsHooks::updateLinksAfterEdit';
$wgHooks['PageContentSaveComplete'][] = 'ApprovedRevsHooks::setLatestAsApproved';
$wgHooks['PageContentSaveComplete'][] = 'ApprovedRevsHooks::setSearchText';
$wgHooks['SearchResultInitFromTitle'][] = 'ApprovedRevsHooks::setSearchRevisionID';
$wgHooks['PersonalUrls'][] = 'ApprovedRevsHooks::removeRobotsTag';
$wgHooks['ArticleFromTitle'][] = 'ApprovedRevsHooks::showApprovedRevision';
$wgHooks['ArticleAfterFetchContent'][] = 'ApprovedRevsHooks::showBlankIfUnapproved';
// MW 1.21+
$wgHooks['ArticleAfterFetchContentObject'][] = 'ApprovedRevsHooks::showBlankIfUnapproved2';
$wgHooks['DisplayOldSubtitle'][] = 'ApprovedRevsHooks::setSubtitle';
// it's 'SkinTemplateNavigation' for the Vector skin, 'SkinTemplateTabs' for
// most other skins
$wgHooks['SkinTemplateTabs'][] = 'ApprovedRevsHooks::changeEditLink';
$wgHooks['SkinTemplateNavigation'][] = 'ApprovedRevsHooks::changeEditLinkVector';
$wgHooks['PageHistoryBeforeList'][] = 'ApprovedRevsHooks::storeApprovedRevisionForHistoryPage';
$wgHooks['PageHistoryLineEnding'][] = 'ApprovedRevsHooks::addApprovalLink';
$wgHooks['UnknownAction'][] = 'ApprovedRevsHooks::setAsApproved';
$wgHooks['UnknownAction'][] = 'ApprovedRevsHooks::unsetAsApproved';
$wgHooks['BeforeParserFetchTemplateAndtitle'][] = 'ApprovedRevsHooks::setTranscludedPageRev';
$wgHooks['ArticleDeleteComplete'][] = 'ApprovedRevsHooks::deleteRevisionApproval';
$wgHooks['MagicWordwgVariableIDs'][] = 'ApprovedRevsHooks::addMagicWordVariableIDs';
$wgHooks['ParserBeforeTidy'][] = 'ApprovedRevsHooks::handleMagicWords';
$wgHooks['AdminLinks'][] = 'ApprovedRevsHooks::addToAdminLinks';
$wgHooks['LoadExtensionSchemaUpdates'][] = 'ApprovedRevsHooks::describeDBSchema';
$wgHooks['EditPage::showEditForm:initial'][] = 'ApprovedRevsHooks::addWarningToEditPage';
$wgHooks['sfHTMLBeforeForm'][] = 'ApprovedRevsHooks::addWarningToSFForm';
$wgHooks['ArticleViewHeader'][] = 'ApprovedRevsHooks::setArticleHeader';
$wgHooks['ArticleViewHeader'][] = 'ApprovedRevsHooks::displayNotApprovedHeader';
$wgHooks['wgQueryPages'][] = 'ApprovedRevsHooks::onwgQueryPages';

// logging
$wgLogTypes['approval'] = 'approval';
$wgLogNames['approval'] = 'approvedrevs-logname';
$wgLogHeaders['approval'] = 'approvedrevs-logdesc';
$wgLogActions['approval/approve'] = 'approvedrevs-approveaction';
$wgLogActions['approval/unapprove'] = 'approvedrevs-unapproveaction';

// user rights
$wgAvailableRights[] = 'approverevisions'; // deprecated
$wgGroupPermissions['sysop']['approverevisions'] = true; // deprecated
$wgAvailableRights[] = 'viewlinktolatest';
$wgGroupPermissions['*']['viewlinktolatest'] = true;


// default permissions:
//   * Group:sysop can approve anything approvable
//   * Namespaces Main, User, Template, Help and Project are approvable
//     with no additional approvers
//   * No categories or pages are approvable (unless they're within one
//     of the above namespaces)
$egApprovedRevsPermissions = array (

	'All Pages' => array( 'group' => 'sysop' ),

	'Namespace Permissions' => array (
		NS_MAIN => array(),
		NS_USER => array(),
		NS_TEMPLATE => array(),
		NS_HELP => array(),
		NS_PROJECT => array(),
	),

	'Category Permissions' => array (),

	'Page Permissions' => array ()

);

// ResourceLoader modules
$wgResourceModules['ext.ApprovedRevs'] = array(
	'styles' => 'ApprovedRevs.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'ApprovedRevs',
	'position' => 'bottom'
);
