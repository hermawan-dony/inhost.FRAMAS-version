<?php
include('init.php');
include('datalogin.php');
include('koneksi.php');

@$aju = '00002770600520240926000200'; // $_GET["aju"];

if ($aju == '') {
    echo 'Nomor AJU tidak Boleh Kosong!';
    exit;
}

// URL API
$hostprd = "https://apis-gw.beacukai.go.id/openapi/document/detail/27/$aju/050900";

$bearer = $_SESSION["token"];

echo $bearer ; 
// Inisialisasi cURL
$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL => $hostprd,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_ENCODING => "",
    CURLOPT_MAXREDIRS => 10,
    CURLOPT_TIMEOUT => 0,
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
    echo "cURL Error #:" . $err;
} else {
    // Dekode JSON menjadi array
    $data = json_decode($response, true);

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
                    throw new Exception("Error Insert Data Utama: " . mysqli_error($koneksi));
                }

                // Dapatkan ID terakhir yang dimasukkan
                $data_id = mysqli_insert_id($koneksi);

                // Insert barang
                foreach ($main_data['barang'] as $barang) {
                    $query_barang = "INSERT INTO bc_ceisa_get_barang (data_id, seri_barang, jumlah_satuan, netto, nilai_barang, cif, harga_penyerahan, kode_barang, pos_tarif, uraian, merk, tipe, spesifikasi_lain, kode_satuan_barang) 
                        VALUES (
                            '$data_id', 
                            '{$barang['seriBarang']}', 
                            '{$barang['jumlahSatuan']}', 
                            '{$barang['netto']}', 
                            '{$barang['nilaiBarang']}', 
                            '{$barang['cif']}', 
                            '{$barang['hargaPenyerahan']}', 
                            '{$barang['kodeBarang']}', 
                            '{$barang['posTarif']}', 
                            '{$barang['uraian']}', 
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
                    $query_entitas = "INSERT INTO bc_ceisa_get_entitas (data_id, alamat_entitas, kode_entitas, kode_jenis_identitas, kode_status, nama_entitas, nomor_identitas) 
                        VALUES (
                            '$data_id', 
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
                    $query_dokumen = "INSERT INTO bc_ceisa_get_dokumen (data_id, kode_dokumen, nomor_dokumen, tanggal_dokumen, seri_dokumen) 
                        VALUES (
                            '$data_id', 
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
                    $query_kemasan = "INSERT INTO bc_ceisa_get_kemasan (data_id, kode_jenis_kemasan, jumlah_kemasan) 
                        VALUES (
                            '$data_id', 
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
?>
