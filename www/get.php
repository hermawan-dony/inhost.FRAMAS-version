<?php 
date_default_timezone_set("Asia/Jakarta");
set_time_limit(10); // 60 detik
include('init.php');
include('koneksi.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
$kode_kantor = isset($_SESSION['kode_kantor']) ? $_SESSION['kode_kantor'] : '';


@$aju =  $_GET["aju"];
@$a =  $_GET["a"];

$_SESSION['a'] = $a;
 
if ($aju == '') {
    echo 'Nomor AJU tidak Boleh Kosong!';
    exit;
}
if (strlen($aju) > 62) { 
    $aju = substr($aju, 61, 26); 
}  
if (strlen($aju) <26 ) { 
    echo 'Nomor AJU Tidak Valid!';
    exit;
}  

//if (substr(str_pad(hexdec(substr(md5(strtolower($username)), 0, 8)), 10, '0', STR_PAD_LEFT), -10) != $token) { echo 'Maaf, TOKEN ANDA TIDAK VALID!! Silahkan hubungi Developer.. 0851-7172-1782' ; exit ;}
 
$aju = str_replace('-', '', $aju);
$bc = substr( $aju, 4, 2)   ;



if (substr($aju, 0, 4) != '0000') {
    $kantor = substr($aju, 0, 4) . '00';
} else {
    $kantor = $a;
}


if ($kantor == '') {
    echo 'Kode Kantor tidak Boleh Kosong!';
    exit;
} 


//---- VALIDASI DOBEL

// Check if the 'nomor_aju' already exists in the database
$query_check_aju = "SELECT COUNT(*) as count FROM bc_ceisa_get_data WHERE nomor_aju = '$aju'";
$result_check = mysqli_query($koneksi, $query_check_aju);
$row_check = mysqli_fetch_assoc($result_check);

// If 'nomor_aju' exists, stop the process and show message
if ($row_check['count'] > 0) {
    echo "Data AJU ini Sudah Masuk";
    exit;
}


// URL API
$hostprd = "https://apis-gw.beacukai.go.id/openapi/document/detail/$bc/$aju/$kantor";
 
$bearer = $_SESSION["token"];

// Inisialisasi cURL
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 7,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "GET",
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $bearer",
        "Content-Type: application/json"
    ],
]);

// Eksekusi cURL dan ambil respons
$response = curl_exec($curl);
$err = curl_error($curl);
curl_close($curl);

if ($err) {
    echo "Gagal Tarik Data, Coba Lagi" ; // . $err;
} else {
	//echo $response ; 
    // Dekode JSON menjadi array
    $data = json_decode($response, true);
	@$status = $data['status'] ;
	if (@$status === 'false') { echo $data['message'] . ' - Kode Kantor :' . $kantor ; exit ; }

    // Cek apakah respons sukses dan ada data
    if ($data && isset($data['data'])) {
        // Mulai transaksi secara manual
        mysqli_query($koneksi, "START TRANSACTION");

        try {
            // Loop data utama
            foreach ($data['data'] as $main_data) {
                // Query untuk tabel utama
                $query_data = "INSERT INTO bc_ceisa_get_data (nomor_aju, nomor_daftar, tanggal_daftar, kode_kantor, kode_valuta, kota_ttd, nama_ttd, jabatan_ttd, tanggal_aju, kode_dokumen, kode_proses, kode_jenis_tpb, kode_tujuan_pengiriman, kode_tujuan_tpb, jumlah_kontainer, harga_penyerahan, cif, nilai_tukar, bruto, netto) 
                    VALUES (
                        '{$main_data['nomorAju']}', 
                        '{$main_data['nomorDaftar']}', 
                        '{$main_data['tanggalDaftar']}', 
                        '{$main_data['kodeKantor']}', 
                        '{$main_data['kodeValuta']}', 
                        '{$main_data['kotaTtd']}', 
                        '{$main_data['namaTtd']}', 
                        '{$main_data['jabatanTtd']}', 
                        '{$main_data['tanggalAju']}', 
                        '{$main_data['kodeDokumen']}', 
                        '{$main_data['kodeProses']}', 
                        '{$main_data['kodeJenisTpb']}', 
                        '{$main_data['kodeTujuanPengiriman']}', 
                        '{$main_data['kodeTujuanTpb']}', 
                        '{$main_data['jumlahKontainer']}', 
                        '{$main_data['hargaPenyerahan']}', 
                        '{$main_data['cif']}', 
                        '{$main_data['nilaiTukar']}', 
                        '{$main_data['bruto']}', 
                        '{$main_data['netto']}'
                    )";

                if (!mysqli_query($koneksi, $query_data)) {
                    throw new Exception("Error Insert Data Utama: " . mysqli_error($koneksi)); exit ;
                }

                // Dapatkan ID terakhir yang dimasukkan
                //$data_id = mysqli_insert_id($koneksi);

				// Insert barang
				foreach ($main_data['barang'] as $barang) {
					// Melarikan diri karakter spesial di kolom 'uraian'
					$uraian = mysqli_real_escape_string($koneksi, $barang['uraian']);

					$query_barang = "INSERT INTO bc_ceisa_get_barang (nomor_aju, seri_barang, jumlah_satuan, netto, nilai_barang, cif, harga_penyerahan, kode_barang, pos_tarif, uraian, merk, tipe, spesifikasi_lain, kode_satuan_barang) 
						VALUES ('$aju', 
							'{$barang['seriBarang']}', 
							'{$barang['jumlahSatuan']}', 
							'{$barang['netto']}', 
							'{$barang['nilaiBarang']}', 
							'{$barang['cif']}', 
							'{$barang['hargaPenyerahan']}', 
							'{$barang['kodeBarang']}', 
							'{$barang['posTarif']}', 
							'$uraian',  -- Menggunakan variabel yang telah dilarikan
							'{$barang['merk']}', 
							'{$barang['tipe']}', 
							'{$barang['spesifikasiLain']}', 
							'{$barang['kodeSatuanBarang']}'
						)";

					if (!mysqli_query($koneksi, $query_barang)) {
						throw new Exception("Error Insert Barang: " . mysqli_error($koneksi));
					}
				}


                // Insert entitas
                foreach ($main_data['entitas'] as $entitas) {
                    $query_entitas = "INSERT INTO bc_ceisa_get_entitas (nomor_aju, alamat_entitas, kode_entitas, kode_jenis_identitas, kode_status, nama_entitas, nomor_identitas) 
                        VALUES ('$aju', 
                            '{$entitas['alamatEntitas']}', 
                            '{$entitas['kodeEntitas']}', 
                            '{$entitas['kodeJenisIdentitas']}', 
                            '{$entitas['kodeStatus']}', 
                            '{$entitas['namaEntitas']}', 
                            '{$entitas['nomorIdentitas']}'
                        )";

                    if (!mysqli_query($koneksi, $query_entitas)) {
                        throw new Exception("Error Insert Entitas: " . mysqli_error($koneksi));
                    }
                }

                // Insert dokumen
                foreach ($main_data['dokumen'] as $dokumen) {
                    $query_dokumen = "INSERT INTO bc_ceisa_get_dokumen (nomor_aju, kode_dokumen, nomor_dokumen, tanggal_dokumen, seri_dokumen) 
                        VALUES ('$aju', 
                            '{$dokumen['kodeDokumen']}', 
                            '{$dokumen['nomorDokumen']}', 
                            '{$dokumen['tanggalDokumen']}', 
                            '{$dokumen['seriDokumen']}'
                        )";

                    if (!mysqli_query($koneksi, $query_dokumen)) {
                        throw new Exception("Error Insert Dokumen: " . mysqli_error($koneksi));
                    }
                }

                // Insert kemasan
                foreach ($main_data['kemasan'] as $kemasan) {
                    $query_kemasan = "INSERT INTO bc_ceisa_get_kemasan (nomor_aju, kode_jenis_kemasan, jumlah_kemasan) 
                        VALUES ('$aju', 
                            '{$kemasan['kodeJenisKemasan']}', 
                            '{$kemasan['jumlahKemasan']}'
                        )";

                    if (!mysqli_query($koneksi, $query_kemasan)) {
                        throw new Exception("Error Insert Kemasan: " . mysqli_error($koneksi));
                    }
                }
            }

            // Commit transaksi
            mysqli_query($koneksi, "COMMIT");
            echo "Data berhasil disimpan!";
        } catch (Exception $e) {
            // Rollback transaksi jika terjadi kesalahan
            mysqli_query($koneksi, "ROLLBACK");
            echo "Terjadi kesalahan: " . $e->getMessage();
        }
    } else {
        echo "Data tidak ditemukan atau format tidak sesuai.";
    }
}
// Hentikan proses selama 2 detik
//sleep(1);
?>