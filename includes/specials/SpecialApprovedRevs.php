<?php

/**
 * Special page that displays various lists of pages that either do or do
 * not have an approved revision.
 *
 * @author Yaron Koren
 */
class SpecialApprovedRevs extends SpecialPage {

	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct( 'ApprovedRevs' );
	}

	function execute( $query ) {

		ApprovedRevs::addCSS();
		$this->setHeaders();
		// $this->getOutput()->setPageTitle( "approvedfiles" );

		$rep = new SpecialApprovedRevsQueryPage( $this->getRequest()->getVal( 'show' ) );

		if ( method_exists( $rep, 'execute' ) ) {
			return $rep->execute( $query );
		} else {
			list( $limit, $offset ) = wfCheckLimits();
			return $rep->doQuery( $offset, $limit );
		}
	}

}