<?php

if ( !defined( 'MEDIAWIKI' ) ) die();

/**
 * @file
 * @ingroup Extensions
 *
 * @author Yaron Koren
 */

define( 'APPROVED_REVS_VERSION', '1.0.0' );

// credits
$GLOBALS['GLOBALS']['wgExtensionCredits']['other'][] = array(
	'path'            => __FILE__,
	'name'            => 'Approved Revs',
	'version'         => APPROVED_REVS_VERSION,
	'author'          => array( 'Yaron Koren', '...' ),
	'url'             => 'https://www.mediawiki.org/wiki/Extension:Approved_Revs',
	'descriptionmsg'  => 'approvedrevs-desc'
);

// global variables
$GLOBALS['GLOBALS']['egApprovedRevsIP'] = __DIR__ . '/';
$GLOBALS['egApprovedRevsNamespaces'] = array( NS_MAIN, NS_USER, NS_PROJECT, NS_TEMPLATE, NS_HELP );
$GLOBALS['egApprovedRevsSelfOwnedNamespaces'] = array();
$GLOBALS['egApprovedRevsBlankIfUnapproved'] = false;
$GLOBALS['egApprovedRevsAutomaticApprovals'] = true;
$GLOBALS['egApprovedRevsShowApproveLatest'] = false;
$GLOBALS['egApprovedRevsShowNotApprovedMessage'] = false;

// internationalization
$GLOBALS['wgMessagesDirs']['ApprovedRevs'] = $GLOBALS['egApprovedRevsIP'] . 'i18n';
$GLOBALS['wgExtensionMessagesFiles']['ApprovedRevs'] = $GLOBALS['egApprovedRevsIP'] . 'ApprovedRevs.i18n.php';
$GLOBALS['wgExtensionMessagesFiles']['ApprovedRevsAlias'] = $GLOBALS['egApprovedRevsIP'] . 'ApprovedRevs.alias.php';
$GLOBALS['wgExtensionMessagesFiles']['ApprovedRevsMagic'] = $GLOBALS['egApprovedRevsIP'] . 'ApprovedRevs.i18n.magic.php';

// autoload classes
$GLOBALS['wgAutoloadClasses']['ApprovedRevs'] = $GLOBALS['egApprovedRevsIP'] . 'includes/ApprovedRevs_body.php';
$GLOBALS['wgAutoloadClasses']['ApprovedRevsHooks'] = $GLOBALS['egApprovedRevsIP'] . 'includes/ApprovedRevsHooks.php';
$GLOBALS['wgAutoloadClasses']['SpecialApprovedPages'] = $GLOBALS['egApprovedRevsIP'] . 'includes/specials/SpecialApprovedPages.php';
$GLOBALS['wgAutoloadClasses']['SpecialApprovedFiles'] = $GLOBALS['egApprovedRevsIP'] . 'includes/specials/SpecialApprovedFiles.php';
$GLOBALS['wgAutoloadClasses']['SpecialApprovedPagesQueryPage'] = $GLOBALS['egApprovedRevsIP'] . 'includes/specials/SpecialApprovedPagesQueryPage.php';
$GLOBALS['wgAutoloadClasses']['SpecialApprovedFilesQueryPage'] = $GLOBALS['egApprovedRevsIP'] . 'includes/specials/SpecialApprovedFilesQueryPage.php';

// special pages
$GLOBALS['wgSpecialPages']['ApprovedPages'] = 'SpecialApprovedPages';
$GLOBALS['wgSpecialPages']['ApprovedFiles'] = 'SpecialApprovedFiles';
$GLOBALS['wgSpecialPageGroups']['ApprovedPages'] = 'pages';
$GLOBALS['wgSpecialPageGroups']['ApprovedFiles'] = 'pages';

// hooks
$GLOBALS['wgHooks']['ArticleEditUpdates'][] = 'ApprovedRevsHooks::updateLinksAfterEdit';
$GLOBALS['wgHooks']['ArticleSaveComplete'][] = 'ApprovedRevsHooks::setLatestAsApproved';
$GLOBALS['wgHooks']['ArticleSaveComplete'][] = 'ApprovedRevsHooks::setSearchText';
$GLOBALS['wgHooks']['SearchResultInitFromTitle'][] = 'ApprovedRevsHooks::setSearchRevisionID';
$GLOBALS['wgHooks']['PersonalUrls'][] = 'ApprovedRevsHooks::removeRobotsTag';
$GLOBALS['wgHooks']['ArticleFromTitle'][] = 'ApprovedRevsHooks::showApprovedRevision';
$GLOBALS['wgHooks']['ArticleAfterFetchContent'][] = 'ApprovedRevsHooks::showBlankIfUnapproved';
// MW 1.21+
$GLOBALS['wgHooks']['ArticleAfterFetchContentObject'][] = 'ApprovedRevsHooks::showBlankIfUnapproved2';
$GLOBALS['wgHooks']['DisplayOldSubtitle'][] = 'ApprovedRevsHooks::setSubtitle';
// it's 'SkinTemplateNavigation' for the Vector skin, 'SkinTemplateTabs' for
// most other skins
$GLOBALS['wgHooks']['SkinTemplateTabs'][] = 'ApprovedRevsHooks::changeEditLink';
$GLOBALS['wgHooks']['SkinTemplateNavigation'][] = 'ApprovedRevsHooks::changeEditLinkVector';
$GLOBALS['wgHooks']['PageHistoryBeforeList'][] = 'ApprovedRevsHooks::storeApprovedRevisionForHistoryPage';
$GLOBALS['wgHooks']['PageHistoryLineEnding'][] = 'ApprovedRevsHooks::addApprovalLink';
$GLOBALS['wgHooks']['UnknownAction'][] = 'ApprovedRevsHooks::setAsApproved';
$GLOBALS['wgHooks']['UnknownAction'][] = 'ApprovedRevsHooks::unsetAsApproved';
$GLOBALS['wgHooks']['BeforeParserFetchTemplateAndtitle'][] = 'ApprovedRevsHooks::setTranscludedPageRev';
$GLOBALS['wgHooks']['ArticleDeleteComplete'][] = 'ApprovedRevsHooks::deleteRevisionApproval';
$GLOBALS['wgHooks']['MagicWordwgVariableIDs'][] = 'ApprovedRevsHooks::addMagicWordVariableIDs';
$GLOBALS['wgHooks']['ParserBeforeTidy'][] = 'ApprovedRevsHooks::handleMagicWords';
$GLOBALS['wgHooks']['AdminLinks'][] = 'ApprovedRevsHooks::addToAdminLinks';
$GLOBALS['wgHooks']['LoadExtensionSchemaUpdates'][] = 'ApprovedRevsHooks::describeDBSchema';
$GLOBALS['wgHooks']['EditPage::showEditForm:initial'][] = 'ApprovedRevsHooks::addWarningToEditPage';
$GLOBALS['wgHooks']['sfHTMLBeforeForm'][] = 'ApprovedRevsHooks::addWarningToSFForm';
$GLOBALS['wgHooks']['ArticleViewHeader'][] = 'ApprovedRevsHooks::setArticleHeader';
$GLOBALS['wgHooks']['ArticleViewHeader'][] = 'ApprovedRevsHooks::displayNotApprovedHeader';

// Approved File Revisions
$GLOBALS['wgHooks']['UnknownAction'][] = 'ApprovedRevsHooks::setFileAsApproved';
$GLOBALS['wgHooks']['UnknownAction'][] = 'ApprovedRevsHooks::unsetFileAsApproved';
$GLOBALS['wgHooks']['ImagePageFileHistoryLine'][] = 'ApprovedRevsHooks::onImagePageFileHistoryLine';
$GLOBALS['wgHooks']['BeforeParserFetchFileAndTitle'][] = 'ApprovedRevsHooks::ModifyFileLinks';
$GLOBALS['wgHooks']['ImagePageFindFile'][] = 'ApprovedRevsHooks::onImagePageFindFile';
$GLOBALS['wgHooks']['FileDeleteComplete'][] = 'ApprovedRevsHooks::onFileDeleteComplete';


// logging
$GLOBALS['wgLogTypes']['approval'] = 'approval';
$GLOBALS['wgLogNames']['approval'] = 'approvedrevs-logname';
$GLOBALS['wgLogHeaders']['approval'] = 'approvedrevs-logdesc';
$GLOBALS['wgLogActions']['approval/approve'] = 'approvedrevs-approveaction';
$GLOBALS['wgLogActions']['approval/unapprove'] = 'approvedrevs-unapproveaction';

// user rights
$GLOBALS['wgAvailableRights'][] = 'approverevisions'; // jamesmontalvo3: do we remove this or leave it behind even though it's not being used anymore?
$GLOBALS['wgGroupPermissions']['sysop']['approverevisions'] = true; // jamesmontalvo3: do we remove this or leave it behind even though it's not being used anymore?
$GLOBALS['wgAvailableRights'][] = 'viewlinktolatest';
$GLOBALS['wgGroupPermissions']['*']['viewlinktolatest'] = true;

// page properties
$GLOBALS['wgPageProps']['approvedrevs'] = 'Whether or not the page is approvable';

// ResourceLoader modules
$GLOBALS['wgResourceModules']['ext.ApprovedRevs'] = array(
	'position' => 'bottom',
	'styles' => 'skins/ApprovedRevs.css',
	'localBasePath' => __DIR__,
	'remoteExtPath' => 'ApprovedRevs'
);
