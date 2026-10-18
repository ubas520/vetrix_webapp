<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../config/database.php';
if(!$conn->query('CREATE TEMPORARY TABLE appointments(id INT PRIMARY KEY,status VARCHAR(30),scheduled_date DATETIME NULL,requested_date DATETIME) ENGINE=InnoDB'))throw new RuntimeException('Temporary fixture failed');
$conn->query("INSERT INTO appointments VALUES
 (1,'approved',CURDATE(),CURDATE()-INTERVAL 30 DAY),
 (2,'completed',CURDATE()-INTERVAL 13 DAY,CURDATE()),
 (3,'pending',NULL,CURDATE()),
 (4,'cancelled',CURDATE(),CURDATE()),
 (5,'rejected',CURDATE(),CURDATE()),
 (6,'approved',CURDATE()-INTERVAL 14 DAY,CURDATE()),
 (7,'approved',CURDATE()+INTERVAL 1 DAY,CURDATE())");
ob_start();include __DIR__.'/../includes/dashboard_visit_chart.php';$html=ob_get_clean();
if(count($chartDays)!==14 || array_sum($chartTotals)!==5)throw new RuntimeException('Date boundaries or total incorrect');
foreach($chartTotals as $status=>$total)if($total!==1)throw new RuntimeException('Wrong status count: '.$status);
if(count($chartPayload['labels'])!==14 || count($chartPayload['datasets'])!==5)throw new RuntimeException('Chart payload dimensions incorrect');
foreach($chartPayload['datasets'] as $series)if(count($series['data'])!==14 || array_sum($series['data'])!==1)throw new RuntimeException('Series totals do not match the table');
if(!str_contains($html,'View daily counts') || !str_contains($html,'<caption>'))throw new RuntimeException('Accessible data table missing');
$conn->query('DELETE FROM appointments'); // Connection-local temporary fixture only.
ob_start();include __DIR__.'/../includes/dashboard_visit_chart.php';$html=ob_get_clean();
if(array_sum($chartTotals)!==0 || !str_contains($html,'No appointments fall in this period'))throw new RuntimeException('Empty chart must show zero and explanation');
echo "Dashboard chart checks passed: 14-day boundaries, current statuses, requested-date fallback, zero-filled days, table totals, empty state. No clinic data changed.\n";
