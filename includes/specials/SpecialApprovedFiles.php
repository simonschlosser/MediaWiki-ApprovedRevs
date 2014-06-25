<?php

/**
 * Special page that displays various lists of files that either do or do
 * not have an approved revision.
 *
 * @author James Montalvo
 */
class SpecialApprovedFiles extends SpecialPage {

	/**
	 * Constructor
	 */
	function __construct() {
		parent::__construct( 'ApprovedFiles' );
	}

	function execute( $query ) {

		ApprovedRevs::addCSS();
		$this->setHeaders();

		$rep = new SpecialApprovedFilesQueryPage( $this->getRequest()->getVal( 'show' ) );

		if ( method_exists( $rep, 'execute' ) ) {
			return $rep->execute( $query );
		} else {
			list( $limit, $offset ) = wfCheckLimits();
			return $rep->doQuery( $offset, $limit );
		}
	}

}
