<?php
/* @var miniShop2 $miniShop2 */
/* @var pdoFetch $pdoFetch */
$miniShop2 = $modx->getService('minishop2','miniShop2',$modx->getOption('minishop2.core_path',null,$modx->getOption('core_path').'components/minishop2/').'model/minishop2/', $scriptProperties);
$miniShop2->initialize($modx->context->key);
$pdoFetch = $modx->getService('pdofetch','pdoFetch',$modx->getOption('pdotools.core_path',null,$modx->getOption('core_path').'components/pdotools/').'model/pdotools/',$scriptProperties);
$pdoFetch->addTime('pdoTools loaded.');

foreach ($scriptProperties as $k => $v) {
	if ($v === 'false') {
		$scriptProperties[$k] = false;
	}
	$$k = $scriptProperties[$k];
}

// Start building "Where" expression
	$where = array('class_key' => 'msProduct');
	if (empty($showUnpublished)) {$where['published'] = 1;}
	if (empty($showDeleted)) {$where['deleted'] = 0;}
	if (empty($showZeroPrice)) {$where['Data.price:>'] = 0;}

	// Filter by ids
	if (!empty($resources)){
		$resources = array_map('trim', explode(',', $resources));
		$in = $out = array();
		foreach ($resources as $v) {
			if (!is_numeric($v)) {continue;}
			if ($v < 0) {$out[] = abs($v);}
			else {$in[] = $v;}
		}
		if (!empty($in)) {$where['id:IN'] = $in;}
		if (!empty($out)) {$where['id:NOT IN'] = $out;}
	}
	else {
		// Filter by parents

		if (empty($parents) && $parents != '0') {$parents = $modx->resource->id;}
		if (!empty($parents)){
			if (empty($depth)) {$depth = 1;}
			$pids = array_map('trim', explode(',', $parents));
			$parents = $pids;
			foreach ($pids as $v) {
				if (!is_numeric($v)) {continue;}
				$parents = array_merge($parents, $modx->getChildIds($v, $depth));
			}
		}
		// Add product categories
		$q = $modx->newQuery('msCategoryMember', array('category_id:IN' => $parents));
		$q->select('product_id');
		if ($q->prepare() && $q->stmt->execute()) {
			$members = $q->stmt->fetchAll(PDO::FETCH_COLUMN);
		}

		if (!empty($parents) && !empty($members)) {
			$where[] = '(`msProduct`.`parent` IN ('.implode(',',$parents).') OR `msProduct`.`id` IN ('.implode(',',$members).'))';
		}
		else {
			$where['parent:IN'] = $parents;
		}
	}

	// Adding custom where parameters
	if (!empty($scriptProperties['where'])) {
		$tmp = $modx->fromJSON($scriptProperties['where']);
		if (is_array($tmp)) {
			$scriptProperties['where'] = $modx->toJSON(array_merge($where, $tmp));
		}
	}
// End of building "Where" expression

// Fields to select
$resourceColumns = !empty($includeContent) ?  $modx->getSelectColumns('msProduct', 'msProduct') : $modx->getSelectColumns('msProduct', 'msProduct', '', array('content'), true);
$dataColumns = $modx->getSelectColumns('msProductData', 'Data', '', array('id'), true);
$vendorColumns = $modx->getSelectColumns('msVendor', 'Vendor', 'vendor_');

// Default parameters
$default = array(
	'class' => 'msProduct'
	,'where' => $modx->toJSON($where)
	,'leftJoin' => '[
		{"class":"msProductData","alias":"Data","on":"msProduct.id=Data.id"}
		,{"class":"msVendor","alias":"Vendor","on":"msProduct.id=Vendor.id"}
	]'
	,'select' => '{
		"msProduct":"'.$resourceColumns.'"
		,"Data":"'.$dataColumns.'"
		,"Vendor":"'.$vendorColumns.'"
	}'
	,'sortby' => 'id'
	,'sortdir' => 'ASC'
	,'fastMode' => false
	,'return' => 'data'
	,'nestedChunkPrefix' => 'minishop2_'
);

// Merge all properties and run!
$pdoFetch->config = array_merge($pdoFetch->config, $default, $scriptProperties);
$pdoFetch->addTime('Query parameters are prepared.');
$rows = $pdoFetch->run();

// Get json fields
$meta = $modx->getFieldMeta('msProductData');
$jsonFields = array();
foreach ($meta as $k => $v) {
	if ($v['phptype'] == 'json') {
		$jsonFields[] = $k;
	}
}

// Initializing chunk for template rows
if (!empty($tpl)) {
	$pdoFetch->getChunk($tpl);
}

// Processing rows
$output = null;
foreach ($rows as $k => $row) {
	// Processing main fields
	$row['price'] = round($row['price'], 2);
	$row['old_price'] = round($row['old_price'], 2);
	$row['weight'] = round($row['weight'], 3);

	// Processing JSON fields
	foreach ($jsonFields as $field) {
		$array = $modx->fromJSON($row[$field]);

		if (!empty($array[0]) && !empty($tpl) && !empty($pdoFetch->elements[$tpl]['placeholders'][$field])) {
			$row[$field] = '';
			
			foreach ($array as $value) {
				$pl = $pdoFetch->makePlaceholders(array_merge($row, array('value' => $value)));
				$row[$field] .= str_replace($pl['pl'], $pl['vl'], $pdoFetch->elements[$tpl]['placeholders'][$field]);
			}
			$row[$field] = substr($row[$field], 1);
		}
		else {
			$row[$field] = '';
		}
	}

	// Processing product flags
	foreach (array('favorite','new','popular') as $field) {
		if (!empty($row[$field]) && !empty($tpl) && !empty($pdoFetch->elements[$tpl]['placeholders'][$field])) {
			$pl = $pdoFetch->makePlaceholders($row);
			$row[$field] = str_replace($pl['pl'], $pl['vl'], $pdoFetch->elements[$tpl]['placeholders'][$field]);
		}
		else {
			$row[$field] = '';
		}
	}

	// Processing chunk
	if (empty($tpl)) {
		$output[] = '<pre>'.str_replace(array('[',']','`'), array('&#91;','&#93;','&#96;'), htmlentities(print_r($row, true), ENT_QUOTES, 'UTF-8')).'</pre>';
	}
	else {
		$output[] = $pdoFetch->getChunk($tpl, $row, $pdoFetch->config['fastMode']);
	}
}
$pdoFetch->addTime('Returning processed chunks');

if (empty($outputSeparator)) {$outputSeparator = "\n";}
if (!empty($output)) {
	$output = implode($outputSeparator, $output);
}

if ($modx->user->hasSessionContext('mgr') && !empty($showLog)) {
	$output .= '<pre class="msProductsLog">' . print_r($pdoFetch->getTime(), 1) . '</pre>';
}

// Return output
if (!empty($toPlaceholder)) {
	$modx->setPlaceholder($toPlaceholder, $output);
}
else {
	return $output;
}