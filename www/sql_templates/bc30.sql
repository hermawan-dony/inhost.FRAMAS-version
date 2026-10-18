SELECT REPLACE(REPLACE(concat(GROUP_CONCAT(json) , '}') , '\r', ''), '\n', '') json
FROM (
	SELECT 'header' tabel, 
	       GROUP_CONCAT(
	           CONCAT(
				'{"asalData":"',  'S'  , '"',
				',"asuransi":',  round(coalesce(asuransi, '0'), 2)  , 
				',"bruto":', round(coalesce(bruto, '0'), 4) ,  
				',"cif":', round(coalesce(cif, '0'), 2) , 
				',"disclaimer":"1"',
				',"flagBarkir":"', case when coalesce(barang_kiriman, 'T') in ('1', 'Y', 'y') then 'Y' else 'T' end , '"',
				',"flagCurah":"', coalesce(flag_curah, '2') , '"',
				',"flagMigas":"', coalesce(flag_migas, '2') , '"',							
				',"fob":', round(coalesce(fob, '0'), 2) , 
				',"freight":', round(coalesce(freight, '0'), 2) , 
				',"idPengguna":"', coalesce(user_id, '') , '"',
				',"jabatanTtd":"', coalesce(jabatan_pernyataan, '-') , '"',
				',"jumlahKontainer":', coalesce((SELECT count(1) FROM bc_ceisa_kontainer WHERE nomor_aju = h.nomor_aju), 0) , 
				',"kodeAsuransi":"', coalesce(kode_asuransi, 'LN') , '"',
				',"kodeCaraBayar":"', coalesce(kode_cara_bayar, '1') , '"',	
				',"kodeCaraDagang":"', coalesce(kode_cara_dagang, '1') , '"',	
				',"kodeDokumen":"', '30' , '"',
				',"kodeIncoterm":"', coalesce(kode_incoterm, '') , '"',
				',"kodeJenisEkspor":"', coalesce(kode_jenis_ekspor, '1') , '"',
				',"kodeJenisNilai":"', coalesce(kode_jenis_nilai, '') , '"',
				',"kodeJenisPengangkutan":"', coalesce(kode_jenis_pengangkutan, '1') , '"',
				',"kodeJenisProsedur":"', coalesce(kode_jenis_prosedur, '1') , '"',
				',"kodeKantor":"', coalesce(kode_kantor, '') , '"',
				',"kodeKantorEkspor":"', coalesce(kode_kantor_ekspor, kode_kantor, '') , '"',
				',"kodeKantorMuat":"', coalesce(kode_kantor_muat, kode_kantor, '') , '"',
				',"kodeKantorPeriksa":"', coalesce(kode_kantor_periksa, '') , '"',
				',"kodeKategoriEkspor":"', coalesce(kode_kategori_ekspor, '1') , '"',
				',"kodeLokasi":"', coalesce(kode_lokasi, '1') , '"',
				',"kodeNegaraTujuan":"', coalesce(kode_negara_tujuan, '') , '"',
				',"kodePelEkspor":"', coalesce(kode_pelabuhan_ekspor, '') , '"',
				',"kodePelMuat":"', coalesce(kode_pelabuhan_muat, '') , '"',
				',"kodePelTujuan":"', coalesce(kode_pelabuhan_tujuan, '') , '"',
				',"kodeTps":"', coalesce(kode_tps, '') , '"',
				',"kodeValuta":"', coalesce(kode_valuta, 'USD') , '"',
				',"kotaTtd":"', coalesce(kota_pernyataan, '-') , '"',
				',"namaTtd":"', coalesce(nama_pernyataan, '-') , '"', 
				',"ndpbm":', round(coalesce(ndpbm, '0'), 4) , 
				',"netto":', round(coalesce(netto, '0'), 4) , 
				',"nilaiMaklon":', round(coalesce(nilai_maklon, '0'), 2) , 
				',"nomorAju":"', coalesce(nomor_aju, '') , '"',				
				',"seri":', '1' ,  		 					
				',"tanggalAju":"', coalesce(tanggal_pernyataan, '') , '"',	 	 
				',"tanggalEkspor":"', coalesce(tanggal_ekspor, tanggal_pernyataan, '') , '"',
				',"tanggalPeriksa":"', coalesce(tanggal_periksa, tanggal_pernyataan, '') , '"',
				',"tanggalTtd":"', coalesce(tanggal_pernyataan, '') , '"',
				',"totalDanaSawit":', round(coalesce(total_dana_sawit, '0'), 2)
	           )
	     ) as json
	FROM bc_ceisa_header h WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'barang' tabel,
	    CONCAT(
	       '"barang": [',
	       GROUP_CONCAT(
	           CONCAT(
				'{"fob":', round(coalesce(fob, '0'), 2) , 
				',"hargaEkspor":', round(coalesce(harga_ekspor, '0'), 4) , 
				',"hargaPatokan":', round(coalesce(harga_patokan, '0'), 4) , 
				',"hargaPerolehan":', round(coalesce(harga_perolehan, '0'), 2) , 
				',"hargaSatuan":', round(coalesce(harga_satuan, '0'), 2) , 
				',"jumlahKemasan":', round(coalesce(jumlah_kemasan, '0'), 2) , 
				',"jumlahSatuan":', round(coalesce(jumlah_satuan, '0'), 4) , 
				',"nilaiBarang":', round(coalesce(nilai_barang, '0'), 2) , 
				',"nilaiDanaSawit":', round(coalesce(nilai_dana_sawit, '0'), 2) , 
				',"kodeAsalBahanBaku":"', coalesce(kode_asal_barang, '0') , '"', 
				',"kodeBarang":"', coalesce(kode_barang, '') , '"', 
				',"kodeDaerahAsal":"', coalesce(kode_daerah_asal, '') , '"', 
				',"kodeDokumen":"', '30', '"', 
				',"kodeJenisEkspor":"', coalesce(kode_jenis_ekspor, '1'), '"', 
				',"kodeJenisKemasan":"', coalesce(kode_kemasan, '--') , '"', 
				',"kodeNegaraAsal":"', coalesce(kode_negara_asal, '') , '"', 
				',"kodeSatuanBarang":"', coalesce(kode_satuan, '') , '"', 
				',"merk":"', case when coalesce(merek, '') in ('', '-') then '--' else merek end , '"', 
				',"ndpbm":', round(coalesce(ndpbm, '0'), 4) , 
				',"netto":', round(coalesce(netto, '0'), 4) , 
				',"seriBarang":', coalesce(seri_barang, 1) ,  
				',"posTarif":"', coalesce(hs, '') , '"', 
				',"spesifikasiLain":"', coalesce(spesifikasi_lain, '-') , '"', 
				',"tipe":"', case when coalesce(tipe, '') in ('', '-') then '--' else tipe end , '"', 
				',"ukuran":"', coalesce(ukuran, '-') , '"', 
				',"uraian":"', coalesce(uraian, '-') , '"', 
				',"barangDokumen": []',   
				',"barangPemilik": [ { "seriEntitas": 2 } ]',   
				',"barangSpekKhusus": []',
				',"barangTarif": []}'
	           ) ORDER BY seri_barang 
	       ),
	       ']'
	    ) as json
	FROM bc_ceisa_barang WHERE nomor_aju = :ls_aju	
	
	UNION ALL
    
	SELECT 'entitas' tabel,
	    CONCAT(
	       '"entitas": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"alamatEntitas":"', coalesce(a.alamat_entitas, '-') , '"',
				  	',"kodeEntitas":"', coalesce(a.kode_entitas, '') , '"', 
				  	',"kodeJenisIdentitas":"', coalesce(a.kode_jenis_identitas, '5') , '"',  
					',"namaEntitas":"', coalesce(a.nama_entitas, '-') , '"', 
					',"nibEntitas":"', coalesce(a.nib_entitas, '') , '"', 
					',"nomorIdentitas":"', coalesce(a.nomor_identitas, '-') , '"',  
				    ',"seriEntitas":', coalesce(a.seri, 1) ,  
				 	',"kodeNegara":"', coalesce(a.kode_negara, 'ID') , '"}'
	           ) ORDER BY coalesce(b.idx, a.seri) 
	       ),
	       ']'
	    ) as json
 	FROM bc_ceisa_entitas a
 	LEFT JOIN bc_ceisa_entidx b ON b.bc = '30' AND a.kode_entitas = b.kode_entitas 
 	WHERE a.nomor_aju = :ls_aju
	
	UNION ALL
   	
	SELECT 'dokumen' tabel, coalesce(
	    CONCAT(
	       '"dokumen": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeDokumen":"', coalesce(kode_dokumen, '999') , '"',
	               ',"nomorDokumen":"', coalesce(nomor_dokumen, '-') , '"',
	               ',"seriDokumen":', coalesce(seri, 1) , 
	               ',"tanggalDokumen":"', date_format(tanggal_dokumen, '%Y-%m-%d'), '"}'
	           ) ORDER BY seri 
	       ),
	       ']'
	    ), '"dokumen": []') as json
	FROM bc_ceisa_dokumen WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'pengangkut' tabel, coalesce(
	    CONCAT(
	       '"pengangkut": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"namaPengangkut":"', coalesce(nama_pengangkut, '-') , '"',
	               ',"nomorPengangkut":"', coalesce(nomor_pengangkut, '-') , '"',
	               ',"kodeBendera":"', coalesce(kode_bendera, 'ID') , '"',
	               ',"kodeCaraAngkut":"', coalesce(kode_cara_angkut, '1') , '"',
	               ',"seriPengangkut":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"pengangkut": []') as json
	FROM bc_ceisa_pengangkut WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'kontainer' tabel, coalesce(
	    CONCAT(
	       '"kontainer": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeJenisKontainer":"', coalesce(kode_jenis_kontainer, '4') , '"',
	               ',"kodeTipeKontainer":"', coalesce(kode_tipe_kontainer, '1') , '"',
	               ',"kodeUkuranKontainer":"', coalesce(kode_ukuran_kontainer, '20') , '"',
	               ',"nomorKontainer":"', coalesce(nomor_kontainer, '-') , '"',
	               ',"seriKontainer":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"kontainer": []') as json
	FROM bc_ceisa_kontainer WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'kemasan' tabel, coalesce(
	    CONCAT(
	       '"kemasan": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"jumlahKemasan":', coalesce(round(jumlah_kemasan, 0), 1) ,  
	               ',"kodeJenisKemasan":"', coalesce(kode_kemasan, 'PK') , '"',
	               ',"merkKemasan":"', coalesce(merek, '-') , '"',
	               ',"seriKemasan":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"kemasan": []') as json
	FROM bc_ceisa_kemasan WHERE nomor_aju = :ls_aju
	
	UNION ALL
	
	SELECT 'bankDevisa' tabel, coalesce(
	    CONCAT(
	       '"bankDevisa": [',
	       GROUP_CONCAT(
	           CONCAT(
	               '{"kodeBank":"', coalesce(kode, '') , '"',
	               ',"namaBank":"', coalesce(nama, '-') , '"',
	               ',"seriBank":', coalesce(seri, 1) , '}'
	           ) ORDER BY seri
	       ),
	       ']'
	    ), '"bankDevisa": []') as json
	FROM bc_ceisa_bankdevisa WHERE nomor_aju = :ls_aju
  
	UNION ALL
	
	SELECT 'kesiapanBarang' tabel, coalesce(
	    CONCAT(
	       '"kesiapanBarang": [',
	       GROUP_CONCAT(
	           CONCAT(
				'{"kodeJenisBarang":"', coalesce(kode_jenis_barang, '2') , '"',
				',"kodeJenisGudang":"', coalesce(kode_jenis_gudang, '1') , '"',
				',"namaPic":"', coalesce(nama_pic, '-') , '"',
				',"alamat":"', coalesce(alamat_pic, '-') , '"',
				',"nomorTelpPic":"', coalesce(telp_pic, '-') , '"',
				',"lokasiSiapPeriksa":"', coalesce(lokasi_siap_periksa, '-') , '"',
				',"kodeCaraStuffing":"', coalesce(kode_cara_stuffing, '4') , '"',
				',"kodeJenisPartOf":"', coalesce(kode_jenis_partof, '1') , '"',
				',"tanggalPkb":"', coalesce(date_format(tanggal_pkb, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')) , '"',
				',"waktuSiapPeriksa":"', concat(coalesce(date_format(waktu_siap_periksa, '%Y-%m-%d'), date_format(now(), '%Y-%m-%d')), 'T00:00:00.000Z') , '"',
				',"jumlahContainer20":', coalesce(round(jumlah_kontainer20, 0), 0) , 
				',"jumlahContainer40":', coalesce(round(jumlah_kontainer40, 0), 0) , '}'
	           )
	       ),
	       ']'
	    ), '"kesiapanBarang": []') as json
	FROM bc_ceisa_kesiapanbarang WHERE nomor_aju = :ls_aju  
    
) dt
