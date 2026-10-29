<?php
require_once __DIR__ . '/../../config/init.php';
require_once __DIR__ . '/../../includes/inventory.php';
require_once __DIR__ . '/../../includes/inventory_report.php';
requireRole(ROLE_ADMIN);
$db = Database::getInstance();

$scope = ($_GET['scope'] ?? 'all') === 'low' ? 'low' : 'all';
$category = trim((string) ($_GET['category'] ?? ''));
$search = trim((string) ($_GET['q'] ?? ''));
$rows = inventoryReportData($db, $scope, $category, $search);
$totalStockValue = array_sum(array_column($rows, 'stock_value'));
$generatedAt = date('M j, Y g:i A');
$data = [];
$data[] = [
  ['Kapehan · Inventory Report', 'Title', 9],
];
$filterText = $scope === 'low' ? 'Below reorder level' : 'All active inventory';
if ($category !== '') $filterText .= ' · Category: ' . $category;
if ($search !== '') $filterText .= ' · Search: ' . $search;
$data[] = [['Current stock snapshot · ' . $filterText, 'Subtitle', 9]];
$data[] = [['Generated ' . $generatedAt . ' · Stock value uses on-hand quantity × current unit cost', 'Subtitle', 9]];
$data[] = [
  ['Inventory item', 'Header', 25], ['Category', 'Header', 17], ['On hand', 'Header', 12],
  ['Unit', 'Header', 10], ['Reserved', 'Header', 12], ['Available', 'Header', 12],
  ['Reorder level', 'Header', 14], ['Status', 'Header', 14], ['Cost / unit', 'Header', 15], ['Stock value', 'Header', 16],
];

foreach ($rows as $row) {
  $data[] = [
    [(string) $row['name'], 'Text', null], [(string) $row['category'], 'Text', null],
    [(float) $row['quantity_on_hand'], 'Quantity', null], [(string) $row['unit'], 'Text', null],
    [(float) $row['reserved_quantity'], 'Quantity', null], [(float) $row['available_quantity'], 'Quantity', null],
    [(float) $row['reorder_level'], 'Quantity', null], [$row['is_low'] ? 'Reorder' : 'In stock', $row['is_low'] ? 'LowStatus' : 'Status', null],
    [(float) $row['cost_per_unit'], 'Currency', null], [(float) $row['stock_value'], 'Currency', null],
  ];
}
if ($rows) {
  $data[] = [['Total stock value', 'Total', null], [$totalStockValue, 'TotalCurrency', null]];
}

$styleMap = [
  'Title' => 'sTitle', 'Subtitle' => 'sSubtitle', 'Header' => 'sHeader', 'Quantity' => 'sQuantity',
  'Currency' => 'sCurrency', 'Status' => 'sStatus', 'LowStatus' => 'sLowStatus', 'Total' => 'sTotal',
  'TotalCurrency' => 'sTotalCurrency', 'Text' => 'sText',
];
$rowCount = count($data);
$xmlRows = '';
foreach ($data as $rowIndex => $cells) {
  if ($rowIndex < 3) {
    $cell = $cells[0];
    $merge = 9;
    $xmlRows .= '<Row ss:Height="' . ($rowIndex === 0 ? '29' : '21') . '"><Cell ss:StyleID="' . $styleMap[$cell[1]] . '" ss:MergeAcross="' . $merge . '"><Data ss:Type="String">' . inventoryReportXmlEscape((string) $cell[0]) . '</Data></Cell></Row>';
    continue;
  }
  $xmlRows .= '<Row' . ($rowIndex === 3 ? ' ss:Height="25"' : '') . '>';
  foreach ($cells as $cellIndex => $cell) {
    [$value, $style, $width] = $cell;
    $type = is_numeric($value) && !in_array($style, ['Text', 'Header', 'Title', 'Subtitle', 'Status', 'LowStatus', 'Total'], true) ? 'Number' : 'String';
    $mergeAcross = $rowIndex === $rowCount - 1 && $cellIndex === 0 ? ' ss:MergeAcross="8"' : '';
    $xmlRows .= '<Cell ss:StyleID="' . $styleMap[$style] . '"' . $mergeAcross . '><Data ss:Type="' . $type . '">' . inventoryReportXmlEscape((string) $value) . '</Data></Cell>';
  }
  $xmlRows .= '</Row>';
}

$xml = '<?xml version="1.0" encoding="UTF-8"?>'
  . '<?mso-application progid="Excel.Sheet"?>'
  . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">'
  . '<DocumentProperties xmlns="urn:schemas-microsoft-com:office:office"><Title>Kapehan Inventory Report</Title><Author>Kapehan</Author><Created>' . date('Y-m-d\TH:i:s\Z') . '</Created></DocumentProperties>'
  . '<Styles>'
  . '<Style ss:ID="Default" ss:Name="Normal"><Alignment ss:Vertical="Center"/><Borders/><Font ss:FontName="Arial" ss:Size="10"/><Interior/><NumberFormat/><Protection/></Style>'
  . '<Style ss:ID="sTitle"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="18" ss:Bold="1" ss:Color="#7A2E1B"/></Style>'
  . '<Style ss:ID="sSubtitle"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Color="#555555"/></Style>'
  . '<Style ss:ID="sHeader"><Alignment ss:Vertical="Center" ss:WrapText="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#B7A69D"/></Borders><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#7A2E1B" ss:Pattern="Solid"/></Style>'
  . '<Style ss:ID="sText"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10"/></Style>'
  . '<Style ss:ID="sQuantity"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="#,##0.000;[Red]-#,##0.000"/><Font ss:FontName="Arial" ss:Size="10"/></Style>'
  . '<Style ss:ID="sCurrency"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><NumberFormat ss:Format="&quot;₱&quot;#,##0.00;[Red]-&quot;₱&quot;#,##0.00"/><Font ss:FontName="Arial" ss:Size="10"/></Style>'
  . '<Style ss:ID="sStatus"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Color="#237847"/></Style>'
  . '<Style ss:ID="sLowStatus"><Alignment ss:Vertical="Center"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1" ss:Color="#A62E26"/></Style>'
  . '<Style ss:ID="sTotal"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#7A2E1B"/></Borders><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/></Style>'
  . '<Style ss:ID="sTotalCurrency"><Alignment ss:Horizontal="Right" ss:Vertical="Center"/><Borders><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#7A2E1B"/></Borders><NumberFormat ss:Format="&quot;₱&quot;#,##0.00"/><Font ss:FontName="Arial" ss:Size="10" ss:Bold="1"/></Style>'
  . '</Styles><Worksheet ss:Name="Inventory Report"><Table ss:ExpandedColumnCount="10" ss:ExpandedRowCount="' . $rowCount . '" x:FullColumns="1" x:FullRows="1">'
  . '<Column ss:Width="190"/><Column ss:Width="125"/><Column ss:Width="75"/><Column ss:Width="65"/><Column ss:Width="75"/><Column ss:Width="75"/><Column ss:Width="85"/><Column ss:Width="85"/><Column ss:Width="95"/><Column ss:Width="100"/>'
  . $xmlRows . '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><PageSetup><Layout x:Orientation="Landscape"/><Header x:Margin="0.3"/><Footer x:Margin="0.3"/><PageMargins x:Bottom="0.5" x:Left="0.35" x:Right="0.35" x:Top="0.5"/></PageSetup><Print><FitWidth>1</FitWidth><FitHeight>0</FitHeight><ValidPrinterInfo/><PaperSizeIndex>9</PaperSizeIndex></Print><FitToPage/><ProtectObjects>False</ProtectObjects><ProtectScenarios>False</ProtectScenarios></WorksheetOptions></Worksheet></Workbook>';

$filename = 'kapehan-inventory-report-' . date('Y-m-d') . '.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store, max-age=0');
echo $xml;
exit;
