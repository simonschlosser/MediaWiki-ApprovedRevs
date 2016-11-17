<?php

if ( !defined( 'MEDIAWIKI' ) ) die();

/**
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 */

define( 'APPROVED_REVS_VERSION', '0.8.0' );

// credits
$wgExtensionCredits['other'][] = array(
	'path'            => __FILE__,
	'name'            => 'Approved Revs',
	'version'         => APPROVED_REVS_VERSION,
	'author'          => array( 'Yaron Koren', 'James Montalvo', '...' ),
	'url'             =>
	'https://www.mediawiki.org/wiki/Extension:Approved_Revs',
	'descriptionmsg'  => 'approvedrevs-desc'
);

// global variables
$egApprovedRevsBlankIfUnapproved = false;
$egApprovedRevsAutomaticApprovals = true;
$egApprovedRevsShowApproveLatest = false;
$egApprovedRevsShowNotApprovedMessage = false;
$egApprovedRevsHistoryHeader = false;

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



// internationalization
$wgMessagesDirs['ApprovedRevs'] = __DIR__ . '/i18n';
$wgExtensionMessagesFiles['ApprovedRevs'] = __DIR__ . '/ApprovedRevs.i18n.php';
$wgExtensionMessagesFiles['ApprovedRevsAlias'] = __DIR__ . '/ApprovedRevs.alias.php';
$wgExtensionMessagesFiles['ApprovedRevsMagic'] = __DIR__ . '/ApprovedRevs.i18n.magic.php';

// register all classes
$wgAutoloadClasses['ApprovedRevs'] = __DIR__ . '/ApprovedRevs_body.php';
$wgAutoloadClasses['ApprovedRevsHooks'] = __DIR__ . '/ApprovedRevs.hooks.php';
$wgAutoloadClasses['SpecialApprovedPages'] = __DIR__ . '/specials/SpecialApprovedPages.php';
$wgAutoloadClasses['SpecialApprovedFiles'] = __DIR__ . '/specials/SpecialApprovedFiles.php';
$wgAutoloadClasses['SpecialApprovedPagesQueryPage'] = __DIR__ . '/specials/SpecialApprovedPagesQueryPage.php';
$wgAutoloadClasses['SpecialApprovedFilesQueryPage'] = __DIR__ . '/specials/SpecialApprovedFilesQueryPage.php';

// special pages
$wgSpecialPages['ApprovedPages'] = 'SpecialApprovedPages';
$wgSpecialPages['ApprovedFiles'] = 'SpecialApprovedFiles';

// hooks
$wgHooks['ArticleEditUpdates'][] = 'ApprovedRevsHooks::updateLinksAfterEdit';
$wgHooks['PageContentSaveComplete'][] = 'ApprovedRevsHooks::setLatestAsApproved';
$wgHooks['PageContentSaveComplete'][] = 'ApprovedRevsHooks::setSearchText';
$wgHooks['SearchResultInitFromTitle'][] = 'ApprovedRevsHooks::setSearchRevisionID';
$wgHooks['PersonalUrls'][] = 'ApprovedRevsHooks::removeRobotsTag';
$wgHooks['ArticleFromTitle'][] = 'ApprovedRevsHooks::showApprovedRevision';
$wgHooks['ArticleAfterFetchContentObject'][] = 'ApprovedRevsHooks::showBlankIfUnapproved';
$wgHooks['DisplayOldSubtitle'][] = 'ApprovedRevsHooks::setSubtitle';
// it's 'SkinTemplateNavigation' for the Vector skin, 'SkinTemplateTabs' for
// most other skins
$wgHooks['SkinTemplateTabs'][] = 'ApprovedRevsHooks::changeEditLink';
$wgHooks['SkinTemplateNavigation'][] =
	'ApprovedRevsHooks::changeEditLinkVector';
$wgHooks['PageHistoryBeforeList'][] =
	'ApprovedRevsHooks::storeApprovedRevisionForHistoryPage';
$wgHooks['PageHistoryLineEnding'][] = 'ApprovedRevsHooks::addApprovalLink';
$wgHooks['UnknownAction'][] = 'ApprovedRevsHooks::setAsApproved';
$wgHooks['UnknownAction'][] = 'ApprovedRevsHooks::unsetAsApproved';
$wgHooks['BeforeParserFetchTemplateAndtitle'][] =
	'ApprovedRevsHooks::setTranscludedPageRev';
$wgHooks['ArticleDeleteComplete'][] =
	'ApprovedRevsHooks::deleteRevisionApproval';
$wgHooks['MagicWordwgVariableIDs'][] =
	'ApprovedRevsHooks::addMagicWordVariableIDs';
$wgHooks['ParserBeforeTidy'][] = 'ApprovedRevsHooks::handleMagicWords';
$wgHooks['AdminLinks'][] = 'ApprovedRevsHooks::addToAdminLinks';
$wgHooks['LoadExtensionSchemaUpdates'][] =
	'ApprovedRevsHooks::describeDBSchema';
$wgHooks['EditPage::showEditForm:initial'][] =
	'ApprovedRevsHooks::addWarningToEditPage';
$wgHooks['sfHTMLBeforeForm'][] = 'ApprovedRevsHooks::addWarningToSFForm';
$wgHooks['ArticleViewHeader'][] = 'ApprovedRevsHooks::setArticleHeader';
$wgHooks['ArticleViewHeader'][] = 'ApprovedRevsHooks::displayNotApprovedHeader';
$wgHooks['OutputPageBodyAttributes'][] = 'ApprovedRevsHooks::addBodyClass';
$wgHooks['wgQueryPages'][] = 'ApprovedRevsHooks::onwgQueryPages';

// Approved File Revisions
$wgHooks['UnknownAction'][] = 'ApprovedRevsHooks::setFileAsApproved';
$wgHooks['UnknownAction'][] = 'ApprovedRevsHooks::unsetFileAsApproved';
$wgHooks['ImagePageFileHistoryLine'][] =
	'ApprovedRevsHooks::onImagePageFileHistoryLine';
$wgHooks['BeforeParserFetchFileAndTitle'][] =
	'ApprovedRevsHooks::modifyFileLinks';
$wgHooks['ImagePageFindFile'][] = 'ApprovedRevsHooks::onImagePageFindFile';
$wgHooks['FileDeleteComplete'][] = 'ApprovedRevsHooks::onFileDeleteComplete';


// logging
$wgLogTypes['approval'] = 'approval';
$wgLogNames['approval'] = 'approvedrevs-logname';
$wgLogHeaders['approval'] = 'approvedrevs-logdesc';
$wgLogActions['approval/approve'] = 'approvedrevs-approveaction';
$wgLogActions['approval/unapprove'] = 'approvedrevs-unapproveaction';

// user rights
 // No longer used
$wgAvailableRights[] = 'approverevisions';
$wgGroupPermissions['sysop']['approverevisions'] = true;
 // used
$wgAvailableRights[] = 'viewlinktolatest';
$wgGroupPermissions['*']['viewlinktolatest'] = true;

// ResourceLoader modules
$wgResourceModules['ext.ApprovedRevs'] = array(
	'styles' => 'ApprovedRevs.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'ApprovedRevs',
	'position' => 'bottom'
);
