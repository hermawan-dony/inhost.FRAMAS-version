<?    
date_default_timezone_set("Asia/Jakarta");
$tgl =  @$_GET['tgl'] ;
  
$html = file_get_contents("https://fiskal.kemenkeu.go.id/informasi-publik/kurs-pajak?date=$tgl");
$pecah =  explode ( '<br class="visible-xs-block">', $html ) ;   
$pecahlagi = explode ( '<td class="text-right curr-change curr-down">', $pecah [1] ) ;   
$hasil =  $pecahlagi[0]; 
 
$hasil = str_replace('<img src="/assets/92970f7b/transparent.gif" class="flag flag-us" alt="Amerika Serikat" />', '', $hasil ); 

//kmk
$pecah =  explode ( '<p><strong>', $hasil ) ;
$pecahlagi = explode ( '</strong></p>', $pecah [1] ) ; 
$kmk =  trim($pecahlagi[0]); 

//tgl
$pecah =  explode ( 'Tanggal Berlaku:', $hasil ) ;
$pecahlagi = explode ( '</em>', $pecah [1] ) ; 
$keterangan =  'Tanggal Berlaku: '. $pecahlagi[0]; 
$tgl =  $pecahlagi[0]; 
$tgl = str_replace(' Januari ', ' Jan ', $tgl ); 
$tgl = str_replace(' Februari ', ' Feb ', $tgl ); 
$tgl = str_replace(' Pebruari ', ' Feb ', $tgl ); 
$tgl = str_replace(' Maret ', ' Mar ', $tgl ); 
$tgl = str_replace(' April ', ' Apr ', $tgl ); 
$tgl = str_replace(' Mei ', ' May ', $tgl ); 
$tgl = str_replace(' Juni ', ' Jun ', $tgl ); 
$tgl = str_replace(' Juli ', ' Jul ', $tgl ); 
$tgl = str_replace(' Agustus ', ' Aug ', $tgl ); 
$tgl = str_replace(' September ', ' Sep ', $tgl ); 
$tgl = str_replace(' Oktober ', ' Oct ', $tgl ); 
$tgl = str_replace(' November ', ' Nov ', $tgl ); 
$tgl = str_replace(' Nopember ', ' Nov ', $tgl ); 
$tgl = str_replace(' Desember ', ' Dec ', $tgl ); 
$pecah =  explode ( ' - ', $tgl ) ; 
$tgl1 =  trim(date("Y-m-d", strtotime( $pecah [0]  )))  ;
$tgl2 =  trim(date("Y-m-d", strtotime( $pecah [1]  )))  ;  
  
//rate
$pecah =  explode ( '<div class="m-l-5">', $hasil ) ;
$pecahlagi = explode ( '</div>', $pecah [1] ) ; 
$rate =  $pecahlagi[0]; 
$rate = str_replace('.', '*', $rate ); 
$rate = str_replace(',', '#', $rate ); 
$rate = str_replace('#', '.', $rate ); 
$rate = trim(str_replace('*', '', $rate )); 

echo "$tgl1\t$tgl2\t$rate\t$kmk\t$keterangan" ; 
?>