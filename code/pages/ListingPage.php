<?php
/**
 * A page that can be configured to create listings of other content
 *
 * @author Marcus Nyeholt <marcus@silverstripe.com.au>
 * @license BSD License http://silverstripe.org/bsd-license/
 */
class ListingPage extends Page {

	public static $db = array(
		'PerPage'					=> 'Int',
		'Style'						=> "Enum('Standard,A to Z')",
		'SortBy'					=> "Varchar(64)",
		'CustomSort'				=> 'Varchar(64)',
		'SortDir'					=> "Enum('Ascending,Descending')",
		'ListType'					=> 'Varchar(64)',
		'ListingSourceID'			=> 'Int',
		'Depth'						=> 'Int',
		'ClearSource'				=> 'Boolean',
		'StrictType'				=> 'Boolean',
	);
	
	public static $has_one = array(
		'ListingTemplate'			=> 'ListingTemplate',
	);
	
	/**
	 * A mapping between ListType selected and the type of items that should be shown in the "Source" 
	 * selection tree. If not specified in this mapping, it is assumed to be 'Page'.
	 *
	 * @var array
	 */
	public static $listing_type_source_map = array(
		'Folder'	=> 'Folder'
	);

	public static $icon = 'listingpage/images/listing-page';

	/**
	 * @return FieldSet
	 */
	public function getCMSFields() {
		$fields = parent::getCMSFields();
		/* @var FieldSet $fields */

		$fields->replaceField('Content', new HtmlEditorField('Content', _t('ListingPage.CONTENT', 'Content (enter $Listing to display the listing)')));

		$templates = DataObject::get('ListingTemplate');
		if ($templates) {
			$templates = $templates->toDropDownMap('ID', 'Title', '(Select Template)');
		} else {
			$templates = array();
		}

		$fields->addFieldToTab('Root.Content.ListingSettings', new DropdownField('ListingTemplateID', _t('ListingPage.CONTENT_TEMPLATE', 'Listing Template'), $templates));
		$fields->addFieldToTab('Root.Content.ListingSettings', new NumericField('PerPage', _t('ListingPage.PER_PAGE', 'Items Per Page')));
		$fields->addFieldToTab('Root.Content.ListingSettings', new DropdownField('SortDir', _t('ListingPage.SORT_DIR', 'Sort Direction'), $this->dbObject('SortDir')->enumValues()));

		$listType = $this->ListType ? $this->ListType : 'Page';
		$objFields = $this->getSelectableFields($listType);

		$fields->addFieldToTab('Root.Content.ListingSettings', new DropdownField('SortBy', _t('ListingPage.SORT_BY', 'Sort By'), $objFields));
		// $fields->addFieldToTab('Root.Content.Main', new TextField('CustomSort', _t('ListingPage.CUSTOM_SORT', 'Custom sort field')));

		$types = ClassInfo::subclassesFor('DataObject');
		array_shift($types);
		$source = array_combine($types, $types);
		asort($source);

		$optionsetField = new DropdownField('ListType', _t('ListingPage.PAGE_TYPE', 'List items of type'), $source, 'Any');
		$fields->addFieldToTab('Root.Content.ListingSettings', $optionsetField);
		$fields->addFieldToTab('Root.Content.ListingSettings', new CheckboxField('StrictType', _t('ListingPage.STRICT_TYPE', 'List JUST this type, not descendents')));

		if ($this->ListType && Object::has_extension($this->ListType, 'Hierarchy')) {
			$listType = isset(self::$listing_type_source_map[$listType]) ? self::$listing_type_source_map[$listType] : ClassInfo::baseDataClass($listType);
			$fields->addFieldToTab('Root.Content.ListingSettings', new DropdownField('Depth', _t('ListingPage.DEPTH', 'Depth'), array(1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5)));
			$fields->addFieldToTab('Root.Content.ListingSettings', new TreeDropdownField('ListingSourceID', _t('ListingPage.LISTING_SOURCE', 'Source of content for listing'), $listType));
		}

		$fields->addFieldToTab('Root.Content.ListingSettings', new CheckboxField('ClearSource', _t('ListingPage.CLEAR_SOURCE', 'Clear listing source value')));

		return $fields;
	}

	protected function getSelectableFields($listType) {
		$objFields = singleton($listType)->inheritedDatabaseFields();
		$objFields = array_keys($objFields);
		$objFields = array_combine($objFields, $objFields);
		$objFields['LastEdited'] = 'LastEdited';
		$objFields['Created'] = 'Created';
		$objFields['ID'] = 'ID';

		ksort($objFields);
		return $objFields;
	}

	/**
	 * When saving, check to see whether we should delete the
	 * listing source ID
	 */
	public function onBeforeWrite() {
		parent::onBeforeWrite();
		if ($this->ClearSource) {
			$this->ClearSource = false;
			$this->ListingSourceID = 0;
		}
	}
	
	/**
	 * Some subclasses will want to override this. 
	 *
	 * @return DataObject
	 */
	protected function getListingSource() {
		if ($this->ListType && $this->ListingSourceID) {
			return DataObject::get_by_id($this->ListType, $this->ListingSourceID);
		}
	}

	/**
	 * Retrieves all the listing items within this source
	 *
	 * @return DataObjectSource
	 */
	public function ListingItems() {
		// need to get the items being listed
		$source = $this->getListingSource();

		if (!$source) {
			$source = $this;
		}

		$listType = $this->ListType ? $this->ListType : 'Page';

		$ids = $this->getIdsFrom($source, 1);
		$ids[] = $source->ID;

		$filter = array(
			'ParentID IN ' => $ids,
		);

		if ($this->StrictType) {
			$filter['ClassName ='] = $listType;
		}

		$objFields = $this->getSelectableFields($listType);

		$filter = singleton('ListingPageUtils')->dbQuote($filter);
		$sortDir = $this->SortDir == 'Ascending' ? 'ASC' : 'DESC';
		$sort = $this->SortBy && isset($objFields[$this->SortBy]) ? $this->SortBy : 'Title';
		// $sort = $this->CustomSort ? $this->CustomSort : $sort;
		$sort .= ' ' . $sortDir;

		$limit = '';

		$pageUrlVar = 'page' . $this->ID;

		if ($this->PerPage) {
			$page = isset($_REQUEST[$pageUrlVar]) ? $_REQUEST[$pageUrlVar] : 0;
			$limit = "$page,$this->PerPage";
		}

		$items = DataObject::get($listType, $filter, $sort, '', $limit);
		/* @var $items DataObjectSet */

		if ($items) {
			foreach ($items as $result) {
				if (!$result->canView()) {
					$items->remove($result);
				}
			}
			$items->setPaginationGetVar($pageUrlVar);
		}

		return $items;
	}

	/**
	 * Recursively find all the child items that need to be listed
	 *
	 * @param DataObject $parent
	 * @param int $depth
	 */
	protected function getIdsFrom($parent, $depth) {
		if ($depth >= $this->Depth) {
			return;
		}
		$ids = array();
		foreach ($parent->Children() as $kid) {
			$ids[] = $kid->ID;
			$childIds = $this->getIdsFrom($kid, $depth + 1);
			if ($childIds) {
				$ids = array_merge($ids, $childIds);
			}
		}
		return $ids;
	}

	public function Content() {
		$items = $this->ListingItems();
		$item = $this->customise(array('Items' => $items));
		$view = SSViewer::fromString($this->ListingTemplate()->ItemTemplate);
		$content = str_replace('<p>$Listing</p>', '$Listing', $this->Content);
		return str_replace('$Listing', $view->process($item), $content);
	}

}

class ListingPage_Controller extends Page_Controller {
	
}