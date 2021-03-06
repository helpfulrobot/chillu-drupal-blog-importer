<?php
class DrupalBlogCommentBulkLoader extends CsvBulkLoader {

	public $columnMap = array(
		'nid' => 'nid',
		'cid' => 'DrupalCid',
		'uid' => '->importUser',
		'subject' => 'Subject', // requires DrupalCommentExtension
		'name' => 'Name',
		'mail' => 'Email',
		'comment' => '->importComment',
		'timestamp' => 'Created',
		'hostname' => 'Hostname', // requires DrupalCommentExtension
		'homepage' => 'URL',
	);

	public $duplicateChecks = array(
		'DrupalCid' => array(
			'callback' => 'findDuplicateByCid'
		)
	);

	/**
	 * - beforeProcessRecord($record, $columnMap, $result, $preview)
	 * - afterProcessRecord($obj, $record, $columnMap, $result, $preview)
	 * 
	 * @var array
	 */
	public $listeners = array(
		'beforeProcessRecord' => array(),
		'afterProcessRecord' => array()
	);

	protected $_cache_holder;
	protected $_cache_holders = array();
	
	public function __construct($objectClass = 'Comment') {
		parent::__construct($objectClass);

		if(!class_exists('Comment')) {
			throw new LogicException('The "comments" module is not installed, can not import comments');
		}
	}

	protected function getPage($record) {
		$page = (isset($this->_cache_holders[$record['nid']])) ? $this->_cache_holders[$record['nid']] : null;
		if(!$page) {
			$page = BlogEntry::get()->filter('DrupalNid', $record['nid'])->First();
			$this->_cache_holders[$record['nid']] = $page;
		}
		return $page;
	}

	protected function processRecord($record, $columnMap, &$result, $preview = false) {
		foreach($this->listeners['beforeProcessRecord'] as $listener) {
			$listener($record, $columnMap, $result, $preview);
		}

		$page = $this->getPage($record);
		if(!$page) {
			// Mainly for testing, in real imports the posts should be present already
			if(!$holder = $this->_cache_holder) {
				$holder = BlogHolder::get()->First();;	
			}
			if(!$holder) {
				$holder = new BlogHolder();
				$holder->write();
			}
			$this->_cache_holder = $holder;

			$page = new BlogEntry(array(
				'DrupalNid' => $record['nid'],
				'ParentID' => $holder->ID
			));
			$page->write();
		}
		$record['ParentID'] = $page->ID;
		$record['BaseClass'] = 'SiteTree';

		$objId = parent::processRecord($record, $columnMap, $result, $preview);
		$obj = Comment::get()->byId($objId);

		// Created gets overwritten on new records...
		$obj->Created = $record['Created'];
		$obj->write();

		foreach($this->listeners['afterProcessRecord'] as $listener) {
			$listener($obj, $record, $columnMap, $result, $preview);
		}

		return $objId;
	}

	/**
	 * @return Comment
	 */
	protected function findDuplicateByCid($cid, $record) {
		$page = $this->getPage($record);
		if(!$page) return;

		return Comment::get()->filter(array(
			'DrupalCid' => $cid
		))->First();
	}

	protected function importComment($obj, $val, $record) {
		$obj->Comment = $this->cleanupHtml($val);
	}

	protected function importUser($obj, $val, $record) {
		$hasMemberUid = singleton('Member')->hasDatabaseField('DrupalUid');
		$hasCommentAuthorRel = singleton('Comment')->has_one('Author');
		if($val && $hasMemberUid && $hasCommentAuthorRel) {
			// Try importing by UID
			$member = Member::get()->filter('DrupalUid', $val)->First();

			// Fall back to Nickname
			if(!$member) $member = Member::get()->filter('Nickname', $record['Name'])->First();

			// Fall back to creating a member
			if(!$member) {
				$member = new Member(array(
					'DrupalUid' => $val,
					'Nickname' => $record['Name'],
				));
				$member->write();
			}
			if($member) {
				$obj->AuthorID = $member->ID;
				$obj->write();
			}
		} else {
			$obj->Name = $val;
		}
	}

	/**
	 * Remove certain HTML clutter, mostly from Word copypaste.
	 */
	protected function cleanupHtml($val) {
		$val = preg_replace('/\s?style="[^"]*"/', '', $val);
		$val = preg_replace('/<font[^>]*>/', '', $val);
		$val = preg_replace('/<\/font[\s]*>/', '', $val);
		return $val;
	}
	
}