THIS IS NOT A VALID PHP FILE. THIS IS JUST A WORKSPACE I'M USING WHILE I FIGURE OUT HOW TO STRUCTURE THE CODE

NS
Category
Page



Specific user
User in group
SpecialApprover
	Creator
	Self
	Property
	
	
$page_ns = STRING
$page_cat = ARRAY ( ... )
$page = STRING


<?php

function userCanApprove ( $title ) {

	if ( isset(self::$mUserCanApprove) )
		return self::$mUserCanApprove; // only go through all the logic once

	self::$mwTitleObj = $title; // will be needed by other static methods
		
	$page_ns     = MWNamespace::getCanonicalNamespaces()[ $title->getNamespace() ]; // NS text
	$page_cat    = ""; // going to use $title->getCategorySortkey() or $title->getParentCategoryTree()
	$page_actual = self::$mwTitleObj->getText();
		
	$perms = self::getPermissions();
		
	if ( self::checkIfUserInPerms($perms['All Pages']) )
		return self::$mUserCanApprove;
		
	foreach ($perms['Namespace Permissions'] as $ns => $perms)
		if ($ns == $page_ns)
			self::checkIfUserInPerms( $perms );
		
	foreach ($perms['Category Permissions'] as $cat => $perms) {
		$cat = (  $inclusive = ($cat[0]==='+')  ) ? substr($cat, 1) : $cat;
		if ($cat in $page_cats)
			self::checkIfUserInPerms( $perms, $inclusive );
	}
	
	foreach ($perms['Page Permissions'] as $pg => $perms) {
		$pg = (  $inclusive = ($pg[0]==='+')  ) ? substr($pg, 1) : $pg;
		if ($pg == $page_actual)
			self::checkIfUserInPerms( $perms, $inclusive );
	}
	
	return self::$mUserCanApprove;
	
}

// pull INI-file content from approvedrevs-permissions
function getPermissions () {

	if ( self::$permissions )
		return self::$permissions;
		
	preg_match_all(
		'/<syntaxhighlight>(.*?)<\/syntaxhighlight>/si', 
		wfMessage( 'approvedrevs-permissions' )->text(), 
		$matches);
	
	return self::$permissions = parse_ini_string( $matches[1][0], true );

}


	
/*
	sets self::$mUserCanApprove

	Creator
	Self
	Group:GROUPNAME
	User:USERNAME
	Property:PROPERTYNAME 
*/

/* 
	return scenarios:
		inclusive == true, user in perms
			set $mUserCanApprove = true
			return true
			
		inclusive == true, user not in perms
			don't set $mUserCanApprove: rely on whatever it was previously
			return whatever it was previously
		
		inclusive == false, user in perms
			set $mUserCanApprove = true
			return true
		
		inclusive == false, user not in perms
			set $mUserCanApprove = false
			return false
		
 */
function checkIfUserInPerms( $perms, $inclusive=false ) {
	
	// if this isn't going to overwrite other permissions, and other permissions say the user can
	// approve, no need to check further
	if ( $inclusive == true && self::$mUserCanApprove == true)
		return true;
		
	$perms = explode(',', $perms); // is like: array("User:Yaron Koren", "User:Jamesmontalvo3", "Group:People", "Self", "Creator", "Property:Reviewer")
	foreach($perms as $perm) {
	
		$perm = explode(':', $perm);
		switch ( trim($perm[0]) ):
			case "User":
				if ( trim($perm[1]) == $wgUser->getName() )
					return self::$mUserCanApprove = true;
				break;
			case "Group":
				if ( in_array( trim($perm[1]), self::$user_groups ) )
					return self::$mUserCanApprove = true;
				break;
			case "Self":
				if ( self::usernameIsBasePageName() )
					return self::$mUserCanApprove = true;
				break;
			case "Creator":
				if ( self::isPageCreator() )
					return self::$mUserCanApprove = true;
				break;
			case "Property":
				die("Not yet implemented");
				break;
			default:
				die("OH NO!");
	
	}
	
	// if $inclusive==true, the fact that this call to checkIfUserInPerms() didn't find a match does
	// not mean that that the user is denied. Instead return the unmodified value of  
	// self::$mUserCanApprove, which could be either true or false depending on previous passes
	// through checkIfUserInPerms()
	if ($inclusive)
		return self::$mUserCanApprove;

	// if $inclusive==false, the previous value of $mUserCanApprove is irrelevant. return false 
	// since no matches were found here (still could be overridden by later passes)
	else
		return self::$mUserCanApprove = false;
	
}
	
function isPageCreator () {
	$dbr = wfGetDB( DB_SLAVE );
	$row = $dbr->selectRow(
		array( 'revision', 'page' ),
		'revision.rev_user_text',
		array( 'page.page_title' => $title->getDBkey() ),
		null,
		array( 'ORDER BY' => 'revision.rev_id ASC' ),
		array( 'revision' => array( 'JOIN', 'revision.rev_page = page.page_id' ) )
		// TODO: add a "LIMIT" restriction to not pull excessive amounts of data from DB?
	);
	return $row->rev_user_text == $wgUser->getName();
}

function usernameIsBasePageName () {
	global $wgUser;

	// explode on slash to just get the first part (if it is a subpage)
	// as far as I know usernames cannot have slashes in them, so this should be okay
	return explode('/', $title->getText())[0] == $wgUser->getName();
}