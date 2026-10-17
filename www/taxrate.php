<?php 
date_default_timezone_set("Asia/Jakarta");
$tgl =  @$_GET['tgl'] ;
@$kurs =  @$_GET['kurs'] ;

if ($kurs=='') {$kurs = 1 ;}
if ($kurs== 13 ) {$bagi = 100 ;} else {$bagi = 1 ;}

$html = file_get_contents("https://fiskal.kemenkeu.go.id/informasi-publik/kurs-pajak?date=$tgl");
$pecah =  explode ( '<div class="informasi-highlight-box informasi-document-meta">', $html ) ;   
$pecahlagi = explode ( '<td class="text-right', $pecah [1] ) ; 
$hasil =  $pecahlagi[0]; 
$hasil = str_replace('<img src="/css/transparent.gif" class="hidden-xs flag flag-us" alt="Amerika Serikat" style="margin-right:auto;">', '', $hasil );  
 
//rate
$pecah =  explode ( '<div class="m-l-5">', $html ) ;
$pecahlagi = explode ( '</div>', $pecah [$kurs] ) ; 
$rate =  $pecahlagi[0]; 
$rate = str_replace('.', '*', $rate ); 
$rate = str_replace(',', '#', $rate ); 
$rate = str_replace('#', '.', $rate ); 
$rate = trim(str_replace('*', '', $rate )); 
$rate = $rate/ $bagi ;

echo $rate ; 
?>